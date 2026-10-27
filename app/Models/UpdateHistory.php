<?php

namespace App\Models;

use Core\Model;

class UpdateHistory extends Model
{
    protected static string $table = 'update_history';

    /**
     * Start a history row; returns its id, or null when the table does not exist yet
     */
    public static function begin(string $action, array $data = []): ?int
    {
        try {
            $model = new self();
            return $model->create(array_merge([
                'action' => $action,
                'status' => 'running',
                'user_id' => \Core\Auth::id(),
                'started_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ], $data));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Finish a row started with begin(); details are merged into the stored JSON
     */
    public static function finish(?int $id, string $status, array $data = [], float $startedAt = 0.0): void
    {
        if (!$id) {
            return;
        }

        try {
            $model = new self();
            if (isset($data['details']) && is_array($data['details'])) {
                $data['details'] = json_encode($data['details'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $data['status'] = $status;
            $data['finished_at'] = date('Y-m-d H:i:s');
            if ($startedAt > 0) {
                $data['duration_ms'] = (int)round((microtime(true) - $startedAt) * 1000);
            }
            $model->update($id, $data);
        } catch (\Throwable $e) {
            // History is best effort and must never break an update
        }
    }

    public function getPaginated(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare(
            "SELECT h.*, u.username FROM update_history h
             LEFT JOIN users u ON u.id = h.user_id
             $where ORDER BY h.id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM update_history h $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function findWithUser(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT h.*, u.username FROM update_history h LEFT JOIN users u ON u.id = h.user_id WHERE h.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getLatest(): ?array
    {
        $row = $this->db->query("SELECT * FROM update_history ORDER BY id DESC LIMIT 1")->fetch();
        return $row ?: null;
    }

    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['action'])) {
            $where[] = 'h.action = ?';
            $params[] = $filters['action'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'h.status = ?';
            $params[] = $filters['status'];
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }
}
