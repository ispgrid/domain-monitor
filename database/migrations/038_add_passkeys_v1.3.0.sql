-- Update application version to 1.3.0
-- Adds passkey (WebAuthn) sign-in

CREATE TABLE IF NOT EXISTS user_passkeys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    credential_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    user_handle VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    public_key TEXT NOT NULL,
    sign_count INT UNSIGNED NOT NULL DEFAULT 0,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_credential_id (credential_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('passkeys_enabled', '1', 'boolean', 'Allow users to sign in with passkeys')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

UPDATE settings
SET setting_value = '1.3.0'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('038_add_passkeys_v1.3.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
