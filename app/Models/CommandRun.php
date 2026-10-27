<?php

namespace App\Models;

use Core\Model;

class CommandRun extends Model
{
    protected static string $table = 'command_runs';

    public function getPaginated(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare(
            "SELECT c.id, c.source, c.label, c.command, c.exit_code, c.duration_ms, c.user_id, c.started_at, u.username
             FROM command_runs c
             LEFT JOIN users u ON u.id = c.user_id
             $where ORDER BY c.id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM command_runs c $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function findWithUser(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, u.username FROM command_runs c LEFT JOIN users u ON u.id = c.user_id WHERE c.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Commands that ran between two times (used to link commands to an update)
     */
    public function getBetween(string $from, string $to): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, source, label, exit_code, duration_ms, started_at FROM command_runs
             WHERE started_at BETWEEN ? AND ? ORDER BY id"
        );
        $stmt->execute([$from, $to]);
        return $stmt->fetchAll();
    }

    public function getSources(): array
    {
        return $this->db->query("SELECT DISTINCT source FROM command_runs ORDER BY source")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function deleteOlderThan(string $cutoff): int
    {
        $stmt = $this->db->prepare("DELETE FROM command_runs WHERE created_at < ?");
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['source'])) {
            $where[] = 'c.source = ?';
            $params[] = $filters['source'];
        }
        if (($filters['result'] ?? '') === 'failed') {
            $where[] = '(c.exit_code IS NULL OR c.exit_code <> 0)';
        } elseif (($filters['result'] ?? '') === 'ok') {
            $where[] = 'c.exit_code = 0';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
