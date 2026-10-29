-- Update application version to 1.6.0
-- Read-only API with per-user tokens

-- API tokens. Only a SHA-256 hash of the token is stored; the token is shown once when created.
CREATE TABLE IF NOT EXISTS api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    token_hint VARCHAR(16) NOT NULL COMMENT 'Start of the token, to tell tokens apart',
    expires_at DATETIME NULL,
    last_used_at DATETIME NULL,
    last_used_ip VARCHAR(45) NULL,
    window_start DATETIME NULL COMMENT 'Start of the current rate limit minute',
    window_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_api_token_hash (token_hash),
    INDEX idx_api_tokens_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('api_enabled', '1', 'boolean', 'Allow users to create API tokens and use the read-only API')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

UPDATE settings
SET setting_value = '1.6.0'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('044_add_api_tokens_v1.6.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
