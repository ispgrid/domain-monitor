<?php

namespace Core;

/**
 * Session Validator Middleware
 *
 * Validates that the current session exists in database and that its account
 * is still active. If the session was deleted (logged out remotely) or the
 * account was disabled or deleted, forces re-login.
 */
class SessionValidator
{
    /**
     * Validate current session against database
     * If session doesn't exist in DB, destroy it and force login
     */
    public static function validate(): void
    {
        // Skip if not logged in
        if (!isset($_SESSION['user_id'])) {
            return;
        }

        try {
            $sessionId = session_id();
            $pdo = Database::getConnection();

            // Only database sessions have a row to check; file sessions never do
            if (SessionConfig::usesDatabase()) {
                // Check if this session exists in database
                $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE id = ?");
                $stmt->execute([$sessionId]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);

                // If session not found in DB, it was deleted remotely
                if (!$result) {
                    self::endSession('Your session was terminated remotely. Please login again.');
                }

                // If session exists but user_id doesn't match, something is wrong
                if ($result['user_id'] != $_SESSION['user_id']) {
                    self::endSession('Session validation failed. Please login again.');
                }
            }

            // The account may have been disabled, deleted or given another role since this login
            $stmt = $pdo->prepare("SELECT is_active, role FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$user || !$user['is_active']) {
                $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$_SESSION['user_id']]);
                setcookie('remember_token', '', time() - 3600, '/');
                self::endSession('Your account is disabled. Please contact an administrator.');
            }

            if (($_SESSION['role'] ?? null) !== $user['role']) {
                $_SESSION['role'] = $user['role'];
            }

        } catch (\Exception $e) {
            // If sessions table doesn't exist, allow normal operation (graceful fallback)
            error_log("Session validation failed: " . $e->getMessage());
        }
    }

    /**
     * Log out this browser and send it to the login page with a message
     */
    private static function endSession(string $message): never
    {
        session_destroy();
        session_start();
        $_SESSION['error'] = $message;
        header('Location: /login');
        exit;
    }
}
