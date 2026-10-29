<?php

namespace App\Models;

use Core\Model;

/**
 * DNS history: one row per record that a lookup found added or removed.
 * A changed value shows up as a removed and an added row of the same check.
 */
class DnsRecordChange extends Model
{
    protected static string $table = 'dns_record_changes';

    /** How long the history is kept */
    public const KEEP_DAYS = 365;

    /**
     * @param array $added   rows with record_type, host, value, priority, ttl
     * @param array $removed same shape
     */
    public function logChanges(int $domainId, array $added, array $removed, string $detectedAt): void
    {
        if (empty($added) && empty($removed)) {
            return;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO dns_record_changes (domain_id, change_type, record_type, host, value, priority, ttl, detected_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach (['added' => $added, 'removed' => $removed] as $changeType => $rows) {
            foreach ($rows as $row) {
                $stmt->execute([
                    $domainId,
                    $changeType,
                    $row['record_type'],
                    $row['host'] ?? '@',
                    $row['value'] ?? '',
                    $row['priority'] ?? null,
                    $row['ttl'] ?? null,
                    $detectedAt,
                ]);
            }
        }
    }

    /**
     * Latest checks with changes, newest first. Each check lists its changes, with a removed and an
     * added value of the same type and host paired up as one "changed" entry.
     *
     * @return array<int, array{detected_at: string, changes: array, counts: array}>
     */
    public function getTimeline(int $domainId, int $limitChecks = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT detected_at FROM dns_record_changes WHERE domain_id = ? ORDER BY detected_at DESC LIMIT " . max(1, $limitChecks)
        );
        $stmt->execute([$domainId]);
        $times = array_column($stmt->fetchAll(), 'detected_at');
        if (empty($times)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($times), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM dns_record_changes WHERE domain_id = ? AND detected_at IN ({$placeholders})
             ORDER BY detected_at DESC, record_type ASC, host ASC, change_type DESC, id ASC"
        );
        $stmt->execute(array_merge([$domainId], $times));

        $byTime = [];
        foreach ($stmt->fetchAll() as $row) {
            $byTime[$row['detected_at']][] = $row;
        }

        $timeline = [];
        foreach ($byTime as $detectedAt => $rows) {
            $changes = self::pairChanges($rows);
            $counts = ['added' => 0, 'removed' => 0, 'changed' => 0];
            foreach ($changes as $change) {
                $counts[$change['kind']]++;
            }
            $timeline[] = ['detected_at' => $detectedAt, 'changes' => $changes, 'counts' => $counts];
        }
        return $timeline;
    }

    /**
     * Pair removed and added rows of the same type and host (in order) into "changed" entries
     */
    public static function pairChanges(array $rows): array
    {
        $removed = [];
        foreach ($rows as $row) {
            if ($row['change_type'] === 'removed') {
                $removed[$row['record_type'] . '|' . $row['host']][] = $row;
            }
        }

        $changes = [];
        foreach ($rows as $row) {
            if ($row['change_type'] !== 'added') {
                continue;
            }
            $key = $row['record_type'] . '|' . $row['host'];
            if (!empty($removed[$key])) {
                $old = array_shift($removed[$key]);
                $changes[] = ['kind' => 'changed', 'record_type' => $row['record_type'], 'host' => $row['host'],
                    'old_value' => $old['value'], 'old_priority' => $old['priority'],
                    'value' => $row['value'], 'priority' => $row['priority']];
            } else {
                $changes[] = ['kind' => 'added', 'record_type' => $row['record_type'], 'host' => $row['host'],
                    'value' => $row['value'], 'priority' => $row['priority']];
            }
        }
        foreach ($removed as $left) {
            foreach ($left as $row) {
                $changes[] = ['kind' => 'removed', 'record_type' => $row['record_type'], 'host' => $row['host'],
                    'value' => $row['value'], 'priority' => $row['priority']];
            }
        }

        usort($changes, fn($a, $b) => [$a['record_type'], $a['host']] <=> [$b['record_type'], $b['host']]);
        return $changes;
    }

    public function countByDomain(int $domainId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(DISTINCT detected_at) FROM dns_record_changes WHERE domain_id = ?");
        $stmt->execute([$domainId]);
        return (int)$stmt->fetchColumn();
    }

    public function deleteOlderThan(string $cutoff): int
    {
        $stmt = $this->db->prepare("DELETE FROM dns_record_changes WHERE detected_at < ?");
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }
}
