<?php

namespace App\Models;

use Core\Model;

class AuditLog extends Model
{
    protected static string $table = 'audit_log';

    public const CATEGORIES = [
        'auth' => 'Sign-ins',
        'profile' => 'Profile',
        'settings' => 'Settings',
        'domain' => 'Domains',
        'user' => 'Users',
        'group' => 'Notification groups',
        'update' => 'Updates',
        'logs' => 'Logs',
    ];

    /**
     * Record an action by the signed-in user (or the user given in $options).
     * Never throws: the activity log must not break the action it records.
     *
     * Options: user_id, username, entity_type, entity_id, changes (array)
     */
    public static function record(string $action, string $description, array $options = []): void
    {
        try {
            $userId = array_key_exists('user_id', $options) ? $options['user_id'] : \Core\Auth::id();
            $username = $options['username'] ?? ($userId && $userId === \Core\Auth::id() ? \Core\Auth::username() : null);

            if ($userId && $username === null) {
                $user = (new User())->find((int)$userId);
                $username = $user['username'] ?? null;
            }

            $changes = $options['changes'] ?? null;

            (new self())->create([
                'user_id' => $userId,
                'username' => $username,
                'action' => $action,
                'description' => mb_substr($description, 0, 500),
                'entity_type' => $options['entity_type'] ?? null,
                'entity_id' => $options['entity_id'] ?? null,
                'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => self::clientIp(),
                'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Table may not exist yet (code updated before the migration ran)
        }
    }

    /**
     * Changed keys as ['key' => ['old' => ..., 'new' => ...]]; values of secret-looking keys are hidden
     */
    public static function diff(array $old, array $new): array
    {
        $changes = [];
        foreach ($new as $key => $value) {
            $before = $old[$key] ?? null;
            if ((string)(is_array($before) ? json_encode($before) : $before) === (string)(is_array($value) ? json_encode($value) : $value)) {
                continue;
            }
            if (preg_match('/password|secret|token|api_key|private/i', (string)$key)) {
                $changes[$key] = ['old' => '***', 'new' => '*** (changed)'];
            } else {
                $changes[$key] = ['old' => $before, 'new' => $value];
            }
        }
        return $changes;
    }

    public function getPaginated(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare("SELECT * FROM audit_log $where ORDER BY id DESC LIMIT ? OFFSET ?");
        $stmt->execute([...$params, $limit, $offset]);
        return $stmt->fetchAll();
    }

    public function countFiltered(array $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM audit_log $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public function getUsernames(): array
    {
        return $this->db->query(
            "SELECT DISTINCT username FROM audit_log WHERE username IS NOT NULL ORDER BY username"
        )->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function countSince(string $since, string $action): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM audit_log WHERE created_at >= ? AND action = ?");
        $stmt->execute([$since, $action]);
        return (int)$stmt->fetchColumn();
    }

    public function deleteOlderThan(string $cutoff): int
    {
        $stmt = $this->db->prepare("DELETE FROM audit_log WHERE created_at < ?");
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    private function buildWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['category']) && isset(self::CATEGORIES[$filters['category']])) {
            $where[] = 'action LIKE ?';
            $params[] = $filters['category'] . '.%';
        }
        if (!empty($filters['user'])) {
            $where[] = 'username = ?';
            $params[] = $filters['user'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(description LIKE ? OR ip_address LIKE ?)';
            $params[] = '%' . $filters['q'] . '%';
            $params[] = '%' . $filters['q'] . '%';
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    private static function clientIp(): ?string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = trim(explode(',', $_SERVER[$header])[0]);
                return mb_substr($ip, 0, 45);
            }
        }
        return null;
    }
}
