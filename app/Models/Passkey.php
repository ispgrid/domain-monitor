<?php

namespace App\Models;

use Core\Model;

class Passkey extends Model
{
    protected static string $table = 'user_passkeys';

    /**
     * Get all passkeys of a user, newest first
     */
    public function getByUserId(int $userId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM user_passkeys WHERE user_id = ? ORDER BY created_at DESC, id DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    /**
     * Find a passkey by its base64url credential ID
     */
    public function findByCredentialId(string $credentialId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM user_passkeys WHERE credential_id = ?");
        $stmt->execute([$credentialId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find a passkey that belongs to the given user
     */
    public function findForUser(int $id, int $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM user_passkeys WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * The WebAuthn user handle of a user (shared by all their passkeys), or null if they have none
     */
    public function getUserHandle(int $userId): ?string
    {
        $stmt = $this->db->prepare("SELECT user_handle FROM user_passkeys WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $handle = $stmt->fetchColumn();
        return $handle ?: null;
    }

    /**
     * Record a successful sign-in with this passkey
     */
    public function markUsed(int $id, int $signCount): void
    {
        $stmt = $this->db->prepare("UPDATE user_passkeys SET sign_count = ?, last_used_at = NOW() WHERE id = ?");
        $stmt->execute([$signCount, $id]);
    }

    public function rename(int $id, int $userId, string $name): bool
    {
        $stmt = $this->db->prepare("UPDATE user_passkeys SET name = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$name, $id, $userId]);
        return $stmt->rowCount() > 0;
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM user_passkeys WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }
}
