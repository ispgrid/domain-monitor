-- Update application version to 1.5.2
-- Security fixes: sign-in and reset limits, SMTP certificate check, webhook address check

-- Failed sign-ins and password reset requests, for the attempt limits (rows older than a day are removed)
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(16) NOT NULL COMMENT 'login or reset',
    identifier VARCHAR(255) NOT NULL COMMENT 'Username or email as typed, lowercased',
    ip_address VARCHAR(45) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_ip (type, ip_address, created_at),
    INDEX idx_login_attempts_identifier (type, identifier, created_at),
    INDEX idx_login_attempts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('mail_verify_certificate', '1', 'boolean', 'Check the SMTP server certificate'),
('webhook_allow_private', '0', 'boolean', 'Allow webhook channels to reach local and private network addresses')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

UPDATE settings
SET setting_value = '1.5.2'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('043_add_security_fixes_v1.5.2.sql')
ON DUPLICATE KEY UPDATE migration=migration;
