<?php

namespace App\Services;

use App\Models\NotificationChannel;
use App\Models\NotificationGroup;
use App\Models\NotificationLog;
use App\Models\ProviderDnsZone;
use App\Models\RegistrarAccount;
use App\Models\Setting;
use App\Services\Registrars\DnsProviderInterface;
use App\Services\Registrars\ProviderException;
use App\Services\Registrars\ProviderRegistry;

/**
 * Reads DNS zones from provider accounts (Cloudflare), links them to domains by name, compares each zone's records
 * with public DNS and alerts when they still differ after DRIFT_GRACE_MINUTES (time for changes to propagate).
 */
class ProviderDnsSync
{
    public const DRIFT_GRACE_MINUTES = 60;

    /** Types compared with public DNS; others are listed as "not checked" */
    private const COMPARED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'CAA', 'SRV'];

    private $providerFactory;
    private DnsService $dns;
    private RegistrarAccount $accountModel;
    private ProviderDnsZone $zoneModel;
    private Logger $logger;

    public function __construct(?callable $providerFactory = null, ?DnsService $dns = null)
    {
        $this->providerFactory = $providerFactory ?? fn(string $key) => ProviderRegistry::make($key);
        $this->dns = $dns ?? new DnsService();
        $this->accountModel = new RegistrarAccount();
        $this->zoneModel = new ProviderDnsZone();
        $this->logger = new Logger('provider-dns');
    }

    /**
     * Sync every zone of an account. Errors are stored on the account and returned, never thrown.
     *
     * @return array{zones: int, linked: int, mismatched: int, unused: int, alerts: int, error: ?string}
     */
    public function syncAccount(array $account): array
    {
        $summary = ['zones' => 0, 'linked' => 0, 'mismatched' => 0, 'unused' => 0, 'alerts' => 0, 'error' => null];

        try {
            $provider = ($this->providerFactory)($account['provider']);
            if (!$provider instanceof DnsProviderInterface) {
                $this->accountModel->recordSync((int)$account['id'], null);
                return $summary;
            }
            $creds = $this->accountModel->credentials($account);
            $zones = $provider->listZones($creds);
            $summary['zones'] = count($zones);

            $domains = $this->domainsByName((int)$account['user_id']);
            $linked = [];
            foreach ($zones as $zone) {
                $domain = $domains[$zone['name']] ?? null;
                if (!$domain) {
                    continue;
                }
                try {
                    $result = $this->syncZone($account, $provider, $creds, $domain, $zone);
                    $linked[] = (int)$domain['id'];
                    $summary['linked']++;
                    $summary['mismatched'] += $result['mismatches'] > 0 ? 1 : 0;
                    $summary['unused'] += $result['in_use'] ? 0 : 1;
                    $summary['alerts'] += $result['alerted'] ? 1 : 0;
                } catch (ProviderException $e) {
                    // Keep the zone's last data; the account shows the error
                    $linked[] = (int)$domain['id'];
                    $summary['error'] = $e->getMessage();
                }
            }

            $this->zoneModel->deleteOtherZones((int)$account['id'], $linked);
        } catch (ProviderException $e) {
            $summary['error'] = $e->getMessage();
        } catch (\Throwable $e) {
            $this->logger->error('Provider DNS sync failed', ['account_id' => $account['id'], 'error' => $e->getMessage()]);
            $summary['error'] = 'Sync failed: ' . $e->getMessage();
        }

        $this->accountModel->recordSync((int)$account['id'], $summary['error']);
        return $summary;
    }

    /**
     * Re-read one domain's zone (the button on the DNS tab)
     *
     * @return array{mismatches: int, alerted: bool, in_use: bool}
     */
    public function syncDomain(array $domain, array $zoneRow, array $account): array
    {
        $provider = ($this->providerFactory)($account['provider']);
        if (!$provider instanceof DnsProviderInterface) {
            throw new ProviderException('This provider does not host DNS');
        }
        $zone = [
            'id' => $zoneRow['zone_id'],
            'name' => $zoneRow['zone_name'],
            'status' => $zoneRow['zone_status'],
            'name_servers' => is_array($zoneRow['name_servers']) ? $zoneRow['name_servers'] : (json_decode($zoneRow['name_servers'] ?? '[]', true) ?: []),
        ];
        return $this->syncZone($account, $provider, $this->accountModel->credentials($account), $domain, $zone);
    }

    /**
     * @return array{mismatches: int, alerted: bool, in_use: bool}
     */
    private function syncZone(array $account, DnsProviderInterface $provider, array $creds, array $domain, array $zone): array
    {
        $records = $provider->listRecords($creds, $zone['id'], $zone['name']);
        $comparison = $this->compare($zone['name'], $records, $zone['name_servers'] ?? [], (string)($zone['status'] ?? ''));

        $previous = $this->zoneModel->findByDomain((int)$domain['id']);
        $mismatch = $this->driftState($comparison, $previous);
        $alerted = false;
        if ($mismatch['send']) {
            $alerted = $this->sendDriftAlert($domain, $account, $comparison);
            if ($alerted) {
                $mismatch['alerted'] = $mismatch['signature'];
            }
        }

        $this->zoneModel->saveZone((int)$account['id'], (int)$domain['id'], $zone, $records, $comparison, $mismatch);
        return ['mismatches' => $mismatch['count'], 'alerted' => $alerted, 'in_use' => $comparison['in_use']];
    }

    /**
     * Compare the zone's records with public DNS, one row per type and host.
     * A zone the domain doesn't use (nameservers elsewhere, or the provider says it isn't active) is not compared.
     *
     * @return array{rows: array, delegation: array, in_use: bool, mismatches: int, checked_at: string}
     */
    public function compare(string $zoneName, array $records, array $zoneNameServers, string $zoneStatus = 'active'): array
    {
        $publicNs = array_map(fn($r) => self::normalize('NS', $r), $this->dns->lookupType($zoneName, 'NS', $zoneName));
        $zoneNs = array_map(fn($ns) => strtolower(rtrim($ns, '.')), $zoneNameServers);
        sort($publicNs);
        sort($zoneNs);
        $delegation = [
            'zone' => $zoneNs,
            'public' => $publicNs,
            'ok' => empty($zoneNs) || empty($publicNs) || count(array_intersect($zoneNs, $publicNs)) > 0,
        ];
        $inUse = $delegation['ok'] && in_array($zoneStatus, ['', 'active'], true);

        $groups = [];
        foreach ($records as $type => $list) {
            foreach ($list as $record) {
                $groups[$type . '|' . $record['host']][] = $record;
            }
        }
        ksort($groups);

        $rows = [];
        foreach ($groups as $key => $zoneRecords) {
            [$type, $host] = explode('|', $key, 2);
            $rows[] = $inUse ? $this->compareGroup($zoneName, $type, $host, $zoneRecords) : $this->unusedRow($type, $host, $zoneRecords);
        }

        $mismatches = $inUse ? count(array_filter($rows, fn($r) => in_array($r['status'], ['missing', 'different'], true))) : 0;

        return ['rows' => $rows, 'delegation' => $delegation, 'in_use' => $inUse, 'mismatches' => $mismatches, 'checked_at' => date('Y-m-d H:i:s')];
    }

    private function unusedRow(string $type, string $host, array $zoneRecords): array
    {
        $zoneValues = array_values(array_unique(array_map(fn($r) => self::normalize($type, $r), $zoneRecords)));
        sort($zoneValues);
        return [
            'type' => $type,
            'host' => $host,
            'zone' => $zoneValues,
            'public' => [],
            'proxied' => (bool)array_filter($zoneRecords, fn($r) => !empty($r['proxied'])),
            'ttl' => $zoneRecords[0]['ttl'] ?? null,
            'status' => 'unused',
        ];
    }

    private function compareGroup(string $zoneName, string $type, string $host, array $zoneRecords): array
    {
        $fqdn = $host === '@' ? $zoneName : $host . '.' . $zoneName;
        $proxied = (bool)array_filter($zoneRecords, fn($r) => !empty($r['proxied']));
        $flattened = $type === 'CNAME' && ($host === '@' || array_filter($zoneRecords, fn($r) => !empty($r['flattened'])));
        $zoneValues = array_values(array_unique(array_map(fn($r) => self::normalize($type, $r), $zoneRecords)));
        sort($zoneValues);

        $row = [
            'type' => $type,
            'host' => $host,
            'zone' => $zoneValues,
            'public' => [],
            'proxied' => $proxied,
            'ttl' => $zoneRecords[0]['ttl'] ?? null,
            'status' => 'match',
        ];

        if (!in_array($type, self::COMPARED_TYPES, true) || ($type === 'CAA' && !defined('DNS_CAA'))) {
            $row['status'] = 'skipped';
            return $row;
        }

        // Proxied names answer with Cloudflare's addresses, and flattened CNAMEs with the target's addresses
        if ($proxied || $flattened) {
            $ips = [];
            foreach (['A', 'AAAA'] as $ipType) {
                foreach ($this->dns->lookupType($fqdn, $ipType, $zoneName) as $r) {
                    $ips[] = self::normalize($ipType, $r);
                }
            }
            $row['public'] = array_values(array_unique($ips));
            if (empty($ips)) {
                $row['status'] = 'missing';
            } elseif ($proxied) {
                $cloudflare = array_filter($ips, fn($ip) => str_contains($ip, ':') ? $this->dns->isCloudflareIpv6($ip) : $this->dns->isCloudflareIp($ip));
                $row['status'] = $cloudflare ? 'proxied' : 'different';
            } else {
                $row['status'] = 'flattened';
            }
            return $row;
        }

        $publicValues = array_values(array_unique(array_map(fn($r) => self::normalize($type, $r), $this->dns->lookupType($fqdn, $type, $zoneName))));
        sort($publicValues);
        $row['public'] = $publicValues;
        if (empty($publicValues)) {
            $row['status'] = 'missing';
        } elseif ($publicValues !== $zoneValues) {
            $row['status'] = 'different';
        }
        return $row;
    }

    /**
     * One comparable string per record, the same for provider and public records
     */
    public static function normalize(string $type, array $record): string
    {
        $value = trim((string)($record['value'] ?? ''));
        switch ($type) {
            case 'A':
            case 'AAAA':
                $packed = @inet_pton($value);
                return $packed !== false ? inet_ntop($packed) : strtolower($value);
            case 'CNAME':
            case 'NS':
                return strtolower(rtrim($value, '.'));
            case 'MX':
                $target = strtolower(rtrim($value, '.'));
                return (int)($record['priority'] ?? 0) . ' ' . ($target === '' ? '.' : $target);
            case 'SRV':
                return (int)($record['priority'] ?? 0) . ' ' . (int)($record['weight'] ?? 0) . ' ' . (int)($record['port'] ?? 0) . ' ' . strtolower(rtrim($value, '.'));
            case 'CAA':
                if (preg_match('/^(\d+)\s+(\S+)\s+"?(.*?)"?$/', $value, $m)) {
                    return $m[1] . ' ' . strtolower($m[2]) . ' "' . $m[3] . '"';
                }
                return $value;
            default:
                return $value;
        }
    }

    /**
     * Mismatch bookkeeping: a mismatch alerts once it has been the same for DRIFT_GRACE_MINUTES
     *
     * @return array{count: int, signature: ?string, since: ?string, alerted: ?string, send: bool}
     */
    private function driftState(array $comparison, ?array $previous): array
    {
        $count = (int)$comparison['mismatches'];
        if ($count === 0) {
            return ['count' => 0, 'signature' => null, 'since' => null, 'alerted' => null, 'send' => false];
        }

        $problems = array_values(array_map(
            fn($r) => [$r['type'], $r['host'], $r['zone'], $r['public']],
            array_filter($comparison['rows'], fn($r) => in_array($r['status'], ['missing', 'different'], true))
        ));
        $signature = hash('sha256', json_encode($problems));

        $since = date('Y-m-d H:i:s');
        $alerted = null;
        if ($previous && $previous['mismatch_signature'] === $signature) {
            $since = $previous['mismatch_since'] ?: $since;
            $alerted = $previous['alerted_signature'];
        }

        // No public nameservers means the lookup failed or the domain doesn't resolve, so the differences prove nothing yet
        $due = strtotime($since) <= time() - self::DRIFT_GRACE_MINUTES * 60 && !empty($comparison['delegation']['public']);
        return ['count' => $count, 'signature' => $signature, 'since' => $since, 'alerted' => $alerted, 'send' => $due && $alerted !== $signature];
    }

    /**
     * Alert the domain's group, and the account owner in the app. Returns true when the alert went out
     */
    private function sendDriftAlert(array $domain, array $account, array $comparison): bool
    {
        if (!(new NotificationGroup())->allowsAlert($domain['notification_group_id'] ?? null, 'dns')) {
            return true; // switched off for this group: treat as handled so it isn't retried every sync
        }

        $label = ProviderRegistry::label($account['provider']);
        $summary = "{$label} and public DNS differ for {$domain['domain_name']} ({$comparison['mismatches']} difference(s))";
        $lines = ["⚠️ {$label} zone and public DNS differ: {$domain['domain_name']}", ''];
        foreach ($comparison['rows'] as $row) {
            if (!in_array($row['status'], ['missing', 'different'], true)) {
                continue;
            }
            $public = empty($row['public']) ? 'nothing' : implode(', ', $row['public']);
            $lines[] = "{$row['type']} {$row['host']}: zone " . implode(', ', $row['zone']) . " | public {$public}";
        }
        $lines[] = '';
        $lines[] = 'Still different after ' . self::DRIFT_GRACE_MINUTES . ' minutes, so it is not just propagation.';
        $message = implode("\n", $lines);

        $sent = false;
        if (!empty($domain['notification_group_id'])) {
            $notificationService = new NotificationService();
            $logModel = new NotificationLog();
            foreach ((new NotificationChannel())->getActiveByGroupId((int)$domain['notification_group_id']) as $channel) {
                $ok = $notificationService->send($channel['channel_type'], json_decode($channel['channel_config'], true) ?: [], $message, [
                    'subject' => "DNS differs from {$label}: {$domain['domain_name']}",
                    'domain' => $domain['domain_name'],
                    'domain_id' => $domain['id'],
                ]);
                $logModel->log((int)$domain['id'], 'dns_drift', $channel['channel_type'], $summary, $ok, $ok ? null : 'Failed to send notification');
                $sent = $sent || $ok;
            }
        }

        try {
            (new \App\Models\Notification())->createNotification((int)$account['user_id'], 'dns_drift', 'DNS differs from ' . $label, $summary, (int)$domain['id']);
            $sent = true;
        } catch (\Throwable $e) {
            $this->logger->warning('In-app drift notification failed', ['error' => $e->getMessage()]);
        }

        return $sent;
    }

    /**
     * Active domains the account's owner can see, keyed by ASCII name
     */
    private function domainsByName(int $userId): array
    {
        $db = \Core\Database::getConnection();
        $isolated = (new Setting())->getValue('user_isolation_mode', 'shared') === 'isolated';
        $sql = "SELECT id, domain_name, notification_group_id, user_id FROM domains WHERE is_active = 1" . ($isolated ? " AND user_id = ?" : '');
        $stmt = $db->prepare($sql);
        $stmt->execute($isolated ? [$userId] : []);

        $byName = [];
        foreach ($stmt->fetchAll() as $domain) {
            $name = strtolower($domain['domain_name']);
            if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $name)) {
                $name = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $name;
            }
            $byName[$name] = $domain;
        }
        return $byName;
    }
}
