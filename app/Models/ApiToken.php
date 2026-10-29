<?php

namespace App\Models;

use Core\Model;

class ApiToken extends Model
{
    protected static string $table = 'api_tokens';

    // Tokens look like dm_ followed by 40 hex characters
    public const PREFIX = 'dm_';
    public const MAX_PER_USER = 20;
    public const REQUESTS_PER_MINUTE = 120;

    /**
     * Get all tokens of a user, newest first (never includes the token itself)
     */
    public function getByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, token_hint, expires_at, last_used_at, last_used_ip, created_at,
                    (expires_at IS NOT NULL AND expires_at <= NOW()) AS is_expired
             FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function countForUser(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM api_tokens WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Create a token and return it. This is the only time the token itself is available.
     */
    public function createForUser(int $userId, string $name, ?int $expiresInDays): string
    {
        $token = self::PREFIX . bin2hex(random_bytes(20));

        // Expiry is set with the database clock, the same clock findValid() compares against
        $stmt = $this->db->prepare(
            "INSERT INTO api_tokens (user_id, name, token_hash, token_hint, expires_at)
             VALUES (?, ?, ?, ?, IF(? > 0, NOW() + INTERVAL ? DAY, NULL))"
        );
        $days = (int)$expiresInDays;
        $stmt->execute([$userId, $name, hash('sha256', $token), substr($token, 0, 9), $days, $days]);

        return $token;
    }

    /**
     * Find the active user behind a token, or null when the token is unknown, expired,
     * or its user is disabled
     */
    public function findValid(string $token): ?array
    {
        if (!preg_match('/^' . self::PREFIX . '[0-9a-f]{40}$/', $token)) {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT t.id, t.user_id, u.username, u.role
             FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ?
               AND (t.expires_at IS NULL OR t.expires_at > NOW())
               AND u.is_active = 1"
        );
        $stmt->execute([hash('sha256', $token)]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Count a request against the token's per-minute limit and record its use.
     * Returns false when the limit is used up.
     */
    public function hit(int $id, ?string $ip): bool
    {
        // MySQL applies SET left to right, so window_count is worked out from the old window_start
        $stmt = $this->db->prepare(
            "UPDATE api_tokens
             SET window_count = IF(window_start IS NOT NULL AND window_start > NOW() - INTERVAL 1 MINUTE, window_count + 1, 1),
                 window_start = IF(window_count = 1, NOW(), window_start),
                 last_used_at = NOW(),
                 last_used_ip = ?
             WHERE id = ?"
        );
        $stmt->execute([$ip, $id]);

        $stmt = $this->db->prepare("SELECT window_count FROM api_tokens WHERE id = ?");
        $stmt->execute([$id]);
        return (int)$stmt->fetchColumn() <= self::REQUESTS_PER_MINUTE;
    }

    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, name FROM api_tokens WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM api_tokens WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }
}
