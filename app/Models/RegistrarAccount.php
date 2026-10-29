<?php

namespace App\Models;

use Core\Encryption;
use Core\Model;

/**
 * A user's registrar or DNS provider account. Credentials are stored encrypted and never sent back to the browser.
 */
class RegistrarAccount extends Model
{
    protected static string $table = 'registrar_accounts';

    // A background sync that hasn't finished after this long is treated as dead
    public const SYNC_TIMEOUT_MINUTES = 15;

    public function getByUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.id, a.user_id, a.provider, a.label, a.is_active, a.last_sync_at, a.last_status, a.last_error, a.created_at,
                    (a.last_status = 'syncing' AND a.updated_at > NOW() - INTERVAL " . self::SYNC_TIMEOUT_MINUTES . " MINUTE) AS is_syncing,
                    (SELECT COUNT(*) FROM provider_dns_zones z WHERE z.account_id = a.id) AS zone_count
             FROM registrar_accounts a WHERE a.user_id = ? ORDER BY a.label ASC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM registrar_accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        return $stmt->fetch() ?: null;
    }

    public function getActive(): array
    {
        return $this->db->query("SELECT * FROM registrar_accounts WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
    }

    public function createAccount(int $userId, string $provider, string $label, array $credentials): int
    {
        return $this->create([
            'user_id' => $userId,
            'provider' => $provider,
            'label' => $label,
            'credentials' => (new Encryption())->encrypt(json_encode($credentials)),
            'is_active' => 1,
        ]);
    }

    public function updateCredentials(int $id, array $credentials): void
    {
        $this->update($id, ['credentials' => (new Encryption())->encrypt(json_encode($credentials))]);
    }

    public function credentials(array $account): array
    {
        if (empty($account['credentials'])) {
            return [];
        }
        return json_decode((new Encryption())->decrypt($account['credentials']), true) ?: [];
    }

    /**
     * Ids of the user's accounts with a background sync still running
     */
    public function syncingIds(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM registrar_accounts
             WHERE user_id = ? AND last_status = 'syncing' AND updated_at > NOW() - INTERVAL " . self::SYNC_TIMEOUT_MINUTES . " MINUTE"
        );
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Mark a background sync as started. updated_at is set here so a repeat of a dead sync restarts the timeout.
     */
    public function markSyncing(int $id): void
    {
        $this->db->prepare("UPDATE registrar_accounts SET last_status = 'syncing', updated_at = NOW() WHERE id = ?")->execute([$id]);
    }

    public function recordSync(int $id, ?string $error): void
    {
        $this->update($id, [
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_status' => $error === null ? 'ok' : 'error',
            'last_error' => $error,
        ]);
    }
}
