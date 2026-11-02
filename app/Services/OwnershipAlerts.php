<?php

namespace App\Services;

use App\Models\NotificationChannel;
use App\Models\NotificationGroup;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\User;

/**
 * Alerts when a domain's registrar, transfer lock or WHOIS nameservers change,
 * which can mean the domain was transferred or hijacked.
 * Compares the saved domain row with fresh WHOIS data before the row is overwritten.
 */
class OwnershipAlerts
{
    public const TRIGGERS = ['registrar_changed', 'transfer_unlocked', 'nameservers_changed'];
    public const TYPE = 'domain_ownership_changed';

    private const IGNORED_REGISTRARS = ['', 'null', 'unknown', 'not registered', 'n/a'];
    private const COMPANY_WORDS = ['inc', 'llc', 'ltd', 'limited', 'corp', 'corporation', 'co', 'company', 'gmbh', 'sas', 'sa', 'sarl', 'bv', 'nv', 'ag', 'srl', 'spa', 'pty', 'plc', 'pvt', 'private', 'the', 'dba', 'com', 'net'];

    /**
     * Changes between the saved domain and fresh WHOIS data, limited to the enabled triggers.
     * Each change: ['trigger' => ..., 'label' => ..., 'from' => ..., 'to' => ...]
     */
    public static function detect(array $domain, array $whois, string $newStatus, array $triggers): array
    {
        // Someone else's domain you're watching to acquire: its changes aren't a hijack of yours
        if (\App\Models\Domain::isWatched($domain)) {
            return [];
        }
        $oldWhois = json_decode($domain['whois_data'] ?? '', true);
        // A new domain, or one that dropped or was just registered: nothing to compare
        if (!is_array($oldWhois) || !$oldWhois) {
            return [];
        }
        $skip = ['available', 'error', 'pending_delete'];
        if (in_array($domain['status'] ?? '', $skip, true) || in_array($newStatus, $skip, true)) {
            return [];
        }

        $changes = [];

        if (in_array('registrar_changed', $triggers, true)) {
            $old = (string)($domain['registrar'] ?? ($oldWhois['registrar'] ?? ''));
            $new = (string)($whois['registrar'] ?? '');
            if (self::isKnownRegistrar($old) && self::isKnownRegistrar($new) && !self::sameRegistrar($old, $new)) {
                $changes[] = ['trigger' => 'registrar_changed', 'label' => 'Registrar changed', 'from' => trim($old), 'to' => trim($new)];
            }
        }

        if (in_array('transfer_unlocked', $triggers, true)) {
            $newStatuses = $whois['status'] ?? [];
            // Some registries return no status codes at all; only compare when both sides have some
            if (self::hasTransferLock($oldWhois['status'] ?? []) && !empty($newStatuses) && !self::hasTransferLock($newStatuses)) {
                $changes[] = ['trigger' => 'transfer_unlocked', 'label' => 'Transfer lock removed', 'from' => 'transfer prohibited', 'to' => 'transfers allowed'];
            }
        }

        if (in_array('nameservers_changed', $triggers, true)) {
            $old = self::normalizeNameservers($oldWhois['nameservers'] ?? []);
            $new = self::normalizeNameservers($whois['nameservers'] ?? []);
            if ($old && $new && $old !== $new) {
                $changes[] = ['trigger' => 'nameservers_changed', 'label' => 'Nameservers changed', 'from' => implode(', ', $old), 'to' => implode(', ', $new)];
            }
        }

        return $changes;
    }

    /**
     * Detect and send alerts (channels of the domain's group, and in-app). Returns the changes found.
     * $log receives one line per step, for the cron output.
     */
    public static function check(array $domain, array $whois, string $newStatus, ?callable $log = null): array
    {
        $log ??= static function (string $line): void {};

        try {
            $triggers = (new Setting())->getNotificationStatusTriggers();
            $changes = self::detect($domain, $whois, $newStatus, $triggers);
            if (!$changes) {
                return [];
            }

            $domainName = $domain['domain_name'];
            foreach ($changes as $change) {
                $log("  ⚠ {$change['label']}: {$change['from']} → {$change['to']}");
            }

            $groupModel = new NotificationGroup();
            if (!$groupModel->allowsDomainAlert($domain, 'status')) {
                $log("  → Status alerts are off for this domain");
                return $changes;
            }

            $message = self::message($domainName, $changes);
            $summary = "Domain $domainName: " . implode(', ', array_column($changes, 'label'));

            if (!empty($domain['notification_group_id'])) {
                $channels = (new NotificationChannel())->getActiveByGroupId((int)$domain['notification_group_id']);
                $logModel = new NotificationLog();
                $service = new NotificationService();
                foreach ($channels as $channel) {
                    $config = json_decode($channel['channel_config'], true) ?: [];
                    $success = $service->send($channel['channel_type'], $config, $message, [
                        'subject' => "Domain change alert: $domainName",
                        'domain' => $domainName,
                        'domain_id' => $domain['id'],
                        'event' => self::TYPE,
                        'changes' => $changes,
                    ]);
                    $log($success ? "    ✓ Sent to {$channel['channel_type']}" : "    ✗ Failed to send to {$channel['channel_type']}");
                    $logModel->log((int)$domain['id'], self::TYPE, $channel['channel_type'], $summary, $success, $success ? null : 'Failed to send notification');
                }
            }

            $notification = new \App\Models\Notification();
            $text = $domainName . ': ' . implode('; ', array_map(
                static fn(array $c) => "{$c['label']} ({$c['from']} → {$c['to']})",
                $changes
            ));
            foreach (self::recipients($domain) as $userId) {
                $notification->createNotification($userId, self::TYPE, 'Domain Change Alert', $text, (int)$domain['id']);
            }
        } catch (\Throwable $e) {
            $log("  ⚠ Domain change alert failed: " . $e->getMessage());
        }

        return $changes ?? [];
    }

    public static function message(string $domainName, array $changes): string
    {
        $lines = ["⚠️ DOMAIN CHANGE: '$domainName' changed at the registry.", ''];
        foreach ($changes as $change) {
            $lines[] = "{$change['label']}:";
            $lines[] = "  Before: {$change['from']}";
            $lines[] = "  Now: {$change['to']}";
        }
        $lines[] = '';
        $lines[] = "If you didn't make this change, contact your registrar right away.";
        return implode("\n", $lines);
    }

    /**
     * Users who get the in-app alert: the domain's owner in isolated mode, everyone active otherwise
     */
    private static function recipients(array $domain): array
    {
        $isolated = (new Setting())->getValue('user_isolation_mode', 'shared') === 'isolated';
        if ($isolated) {
            $userId = $domain['user_id'] ?? null;
            if (!$userId && !empty($domain['notification_group_id'])) {
                $group = (new NotificationGroup())->find((int)$domain['notification_group_id']);
                $userId = $group['user_id'] ?? null;
            }
            return $userId ? [(int)$userId] : [];
        }
        return array_map(static fn(array $u) => (int)$u['id'], (new User())->where('is_active', 1));
    }

    private static function isKnownRegistrar(string $name): bool
    {
        return !in_array(strtolower(trim($name)), self::IGNORED_REGISTRARS, true);
    }

    /**
     * Registrar names differ between RDAP and WHOIS ("GoDaddy.com, LLC" vs "GoDaddy"); compare loosely
     */
    public static function sameRegistrar(string $a, string $b): bool
    {
        $a = self::registrarKey($a);
        $b = self::registrarKey($b);
        if ($a === '' || $b === '') {
            return true;
        }
        return $a === $b || str_contains($a, $b) || str_contains($b, $a);
    }

    private static function registrarKey(string $name): string
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        return implode('', array_diff($words, self::COMPANY_WORDS));
    }

    /**
     * Status codes come as "clientTransferProhibited", "client transfer prohibited",
     * "clientTransferProhibited https://icann.org/epp#..." or several joined with commas
     */
    public static function hasTransferLock(array $statuses): bool
    {
        $joined = strtolower(preg_replace('/[^a-z]/i', '', implode('|', array_map('strval', $statuses))));
        return str_contains($joined, 'clienttransferprohibited') || str_contains($joined, 'servertransferprohibited');
    }

    public static function normalizeNameservers(array $nameservers): array
    {
        $list = [];
        foreach ($nameservers as $ns) {
            $ns = rtrim(strtolower(trim((string)$ns)), '.');
            if ($ns !== '') {
                $list[] = $ns;
            }
        }
        $list = array_values(array_unique($list));
        sort($list);
        return $list;
    }
}
