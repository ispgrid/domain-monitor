<?php

namespace App\Models;

use Core\Model;

class CronRun extends Model
{
    protected static string $table = 'cron_runs';

    // A run whose heartbeat is older than this was killed or crashed without a shutdown handler
    public const STALE_MINUTES = 15;

    /**
     * Paginated runs, newest first
     */
    public function getPaginated(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare(
            "SELECT r.id, r.job, r.status, r.trigger_type, r.triggered_by, r.started_at, r.finished_at, r.heartbeat_at,
                    r.duration_ms, r.items_checked, r.alerts_sent, r.errors, r.error_message, r.created_at, u.username
             FROM cron_runs r
             LEFT JOIN users u ON u.id = r.triggered_by
             $where
             ORDER BY r.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return array_map([self::class, 'withEffectiveStatus'], $stmt->fetchAll());
    }

    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM cron_runs r $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function findWithUser(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, u.username FROM cron_runs r LEFT JOIN users u ON u.id = r.triggered_by WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::withEffectiveStatus($row) : null;
    }

    /**
     * The newest runs of one job (used for health checks)
     */
    public function getLatestForJob(string $job, int $limit = 5): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, job, status, trigger_type, started_at, finished_at, heartbeat_at, duration_ms,
                    items_checked, alerts_sent, errors, error_message, created_at
             FROM cron_runs WHERE job = ? ORDER BY id DESC LIMIT ?"
        );
        $stmt->execute([$job, $limit]);
        return array_map([self::class, 'withEffectiveStatus'], $stmt->fetchAll());
    }

    /**
     * Whether a run of this job is queued or still sending heartbeats
     */
    public function hasActiveRun(string $job): bool
    {
        foreach ($this->getLatestForJob($job, 3) as $run) {
            if (in_array($run['effective_status'], ['queued', 'running'], true)) {
                return true;
            }
        }
        return false;
    }

    public function deleteOlderThan(string $cutoff): int
    {
        $stmt = $this->db->prepare("DELETE FROM cron_runs WHERE created_at < ?");
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Add effective_status: "interrupted" for runs that stopped sending heartbeats,
     * and for queued runs whose process never started
     */
    public static function withEffectiveStatus(array $run): array
    {
        $status = $run['status'];
        $staleBefore = time() - self::STALE_MINUTES * 60;

        if ($status === 'running') {
            $beat = strtotime($run['heartbeat_at'] ?? $run['started_at'] ?? $run['created_at']);
            if ($beat !== false && $beat < $staleBefore) {
                $status = 'interrupted';
            }
        } elseif ($status === 'queued') {
            $created = strtotime($run['created_at']);
            if ($created !== false && $created < time() - 120) {
                $status = 'not_started';
            }
        }

        $run['effective_status'] = $status;
        return $run;
    }

    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['job'])) {
            $where[] = 'r.job = ?';
            $params[] = $filters['job'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['trigger'])) {
            $where[] = 'r.trigger_type = ?';
            $params[] = $filters['trigger'];
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
