<?php

namespace App\Services;

/**
 * Limits failed sign-ins and password reset requests.
 *
 * Counts by REMOTE_ADDR only: forwarded headers can be faked to dodge the limit.
 * Behind a reverse proxy every visitor shares the proxy's address, so the per-IP
 * limits are set well above the per-account ones.
 *
 * Database errors never block a sign-in: after a git pull the table only exists once
 * the admin has signed in and run the migration.
 */
class LoginThrottle
{
    public const WINDOW_MINUTES = 15;

    // Failed passwords for one username from one IP, and for any username from one IP
    public const LOGIN_PER_ACCOUNT = 5;
    public const LOGIN_PER_IP = 20;

    // Reset emails for one address, and reset requests from one IP
    public const RESET_PER_EMAIL = 3;
    public const RESET_PER_IP = 10;

    private \PDO $db;

    public function __construct()
    {
        $this->db = \Core\Database::getConnection();
    }

    public static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /**
     * Minutes until another sign-in may be tried, or 0 when allowed
     */
    public function loginBlockedFor(string $username, string $ip): int
    {
        return max(
            $this->blockedFor('login', self::LOGIN_PER_ACCOUNT, $ip, $this->normalise($username)),
            $this->blockedFor('login', self::LOGIN_PER_IP, $ip)
        );
    }

    public function recordLoginFailure(string $username, string $ip): void
    {
        $this->record('login', $ip, $this->normalise($username));
    }

    /**
     * A successful sign-in clears that account's failures from this IP
     */
    public function clearLoginFailures(string $username, string $ip): void
    {
        try {
            $stmt = $this->db->prepare("DELETE FROM login_attempts WHERE type = 'login' AND identifier = ? AND ip_address = ?");
            $stmt->execute([$this->normalise($username), $ip]);
        } catch (\PDOException $e) {
        }
    }

    /**
     * Minutes until this IP may request another reset, or 0 when allowed
     */
    public function resetBlockedFor(string $ip): int
    {
        return $this->blockedFor('reset', self::RESET_PER_IP, $ip);
    }

    /**
     * Whether another reset email may go to this address (checked across all IPs)
     */
    public function resetEmailAllowed(string $email): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM login_attempts
                 WHERE type = 'reset' AND identifier = ? AND created_at > NOW() - INTERVAL " . self::WINDOW_MINUTES . " MINUTE"
            );
            $stmt->execute([$this->normalise($email)]);
            return (int)$stmt->fetchColumn() < self::RESET_PER_EMAIL;
        } catch (\PDOException $e) {
            return true;
        }
    }

    public function recordReset(string $email, string $ip): void
    {
        $this->record('reset', $ip, $this->normalise($email));
    }

    private function blockedFor(string $type, int $limit, string $ip, ?string $identifier = null): int
    {
        $sql = "SELECT COUNT(*) AS attempts,
                       TIMESTAMPDIFF(SECOND, NOW(), MIN(created_at) + INTERVAL " . self::WINDOW_MINUTES . " MINUTE) AS seconds_left
                FROM login_attempts
                WHERE type = ? AND ip_address = ? AND created_at > NOW() - INTERVAL " . self::WINDOW_MINUTES . " MINUTE";
        $params = [$type, $ip];
        if ($identifier !== null) {
            $sql .= " AND identifier = ?";
            $params[] = $identifier;
        }

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            return 0;
        }

        if ((int)($row['attempts'] ?? 0) < $limit) {
            return 0;
        }
        return max(1, (int)ceil(((int)$row['seconds_left']) / 60));
    }

    private function record(string $type, string $ip, string $identifier): void
    {
        try {
            $stmt = $this->db->prepare("INSERT INTO login_attempts (type, identifier, ip_address) VALUES (?, ?, ?)");
            $stmt->execute([$type, $identifier, $ip]);

            // Rows only matter inside the window; keep the table small
            $this->db->exec("DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY");
        } catch (\PDOException $e) {
        }
    }

    private function normalise(string $value): string
    {
        return mb_substr(mb_strtolower(trim($value)), 0, 255);
    }
}
