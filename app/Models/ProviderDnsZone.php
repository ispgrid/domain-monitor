<?php

namespace App\Models;

use Core\Model;

/**
 * A domain's zone at a DNS provider (Cloudflare): the provider's records and how they compare with public DNS
 */
class ProviderDnsZone extends Model
{
    protected static string $table = 'provider_dns_zones';

    /**
     * The domain's zone with the account's label and provider, records and comparison decoded
     */
    public function getForDomain(int $domainId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT z.*, a.label AS account_label, a.provider, a.user_id AS account_user_id
             FROM provider_dns_zones z JOIN registrar_accounts a ON a.id = z.account_id
             WHERE z.domain_id = ?"
        );
        $stmt->execute([$domainId]);
        $zone = $stmt->fetch();
        if (!$zone) {
            return null;
        }
        $zone['name_servers'] = json_decode($zone['name_servers'] ?? '[]', true) ?: [];
        $zone['records'] = json_decode($zone['records'] ?? '[]', true) ?: [];
        $zone['comparison'] = json_decode($zone['comparison'] ?? '[]', true) ?: [];
        return $zone;
    }

    public function findByDomain(int $domainId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM provider_dns_zones WHERE domain_id = ?");
        $stmt->execute([$domainId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Insert or replace the domain's zone row
     */
    public function saveZone(int $accountId, int $domainId, array $zone, array $records, array $comparison, array $mismatch): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO provider_dns_zones
                (account_id, domain_id, zone_id, zone_name, zone_status, name_servers, records, comparison,
                 mismatch_count, mismatch_signature, mismatch_since, alerted_signature, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                account_id = VALUES(account_id), zone_id = VALUES(zone_id), zone_name = VALUES(zone_name),
                zone_status = VALUES(zone_status), name_servers = VALUES(name_servers), records = VALUES(records),
                comparison = VALUES(comparison), mismatch_count = VALUES(mismatch_count),
                mismatch_signature = VALUES(mismatch_signature), mismatch_since = VALUES(mismatch_since),
                alerted_signature = VALUES(alerted_signature), synced_at = VALUES(synced_at)"
        );
        $stmt->execute([
            $accountId,
            $domainId,
            $zone['id'],
            $zone['name'],
            $zone['status'] ?? null,
            json_encode(array_values($zone['name_servers'] ?? [])),
            json_encode($records),
            json_encode($comparison),
            $mismatch['count'],
            $mismatch['signature'],
            $mismatch['since'],
            $mismatch['alerted'],
            date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Subdomain hosts listed in the domain's zone, so DNS checks look them up even when discovery never found them.
     * Wildcards and the root are left out. Empty when there is no zone (or before the update's migration).
     */
    public function hostsForDomain(int $domainId): array
    {
        try {
            $zone = $this->getForDomain($domainId);
        } catch (\PDOException $e) {
            return [];
        }
        if (!$zone) {
            return [];
        }
        $hosts = [];
        foreach ($zone['records'] as $records) {
            foreach ($records as $record) {
                $host = strtolower((string)($record['host'] ?? ''));
                if ($host !== '@' && preg_match('/^[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?(\.[a-z0-9_]([a-z0-9_-]*[a-z0-9_])?)*$/', $host)) {
                    $hosts[$host] = true;
                }
            }
        }
        return array_keys($hosts);
    }

    /**
     * "TYPE|host" => ['proxied' => bool] for every name and type in the zone, to mark Records rows.
     * A proxied or flattened CNAME answers publicly with A/AAAA, so those are marked for its host too.
     */
    public static function recordFlags(array $zoneRecords): array
    {
        $flags = [];
        foreach ($zoneRecords as $type => $records) {
            foreach ($records as $record) {
                $host = strtolower((string)($record['host'] ?? ''));
                $proxied = !empty($record['proxied']);
                $key = strtoupper($type) . '|' . $host;
                $flags[$key] = ['proxied' => ($flags[$key]['proxied'] ?? false) || $proxied];
                if (strtoupper($type) === 'CNAME' && ($proxied || !empty($record['flattened']) || $host === '@')) {
                    foreach (['A', 'AAAA'] as $answerType) {
                        $answerKey = $answerType . '|' . $host;
                        $flags[$answerKey] = ['proxied' => ($flags[$answerKey]['proxied'] ?? false) || $proxied];
                    }
                }
            }
        }
        return $flags;
    }

    /**
     * Remove the account's zone rows for domains not in $keepDomainIds (zone deleted or no longer shared with the token)
     */
    public function deleteOtherZones(int $accountId, array $keepDomainIds): int
    {
        $sql = "DELETE FROM provider_dns_zones WHERE account_id = ?";
        $params = [$accountId];
        if (!empty($keepDomainIds)) {
            $sql .= ' AND domain_id NOT IN (' . implode(',', array_fill(0, count($keepDomainIds), '?')) . ')';
            $params = array_merge($params, array_values($keepDomainIds));
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
