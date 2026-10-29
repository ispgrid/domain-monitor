-- Update application version to 1.7.0
-- DNS history, domain list columns, Cloudflare DNS (read only)

-- DNS history: records a lookup found added or removed, kept for a year
CREATE TABLE IF NOT EXISTS dns_record_changes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL,
    change_type ENUM('added', 'removed') NOT NULL,
    record_type VARCHAR(10) NOT NULL,
    host VARCHAR(255) NOT NULL DEFAULT '@',
    value TEXT NOT NULL,
    priority INT NULL,
    ttl INT NULL,
    detected_at DATETIME NOT NULL,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    INDEX idx_dns_changes_domain_time (domain_id, detected_at),
    INDEX idx_dns_changes_time (detected_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registrar and DNS provider accounts (Cloudflare first). Credentials are encrypted with APP_ENCRYPTION_KEY
CREATE TABLE IF NOT EXISTS registrar_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    provider VARCHAR(32) NOT NULL,
    label VARCHAR(100) NOT NULL,
    credentials TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME NULL,
    last_status VARCHAR(10) NULL COMMENT 'ok or error',
    last_error TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_registrar_accounts_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A domain's zone at a DNS provider, its records and how they compare with public DNS
CREATE TABLE IF NOT EXISTS provider_dns_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    domain_id INT NOT NULL,
    zone_id VARCHAR(64) NOT NULL,
    zone_name VARCHAR(255) NOT NULL,
    zone_status VARCHAR(32) NULL,
    name_servers JSON NULL,
    records MEDIUMTEXT NULL COMMENT 'JSON, grouped by type',
    comparison MEDIUMTEXT NULL COMMENT 'JSON, last comparison with public DNS',
    mismatch_count INT NOT NULL DEFAULT 0,
    mismatch_signature CHAR(64) NULL,
    mismatch_since DATETIME NULL,
    alerted_signature CHAR(64) NULL,
    synced_at DATETIME NULL,
    FOREIGN KEY (account_id) REFERENCES registrar_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_provider_dns_zone_domain (domain_id),
    INDEX idx_provider_dns_zones_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Domain list columns picked by each user
ALTER TABLE users ADD COLUMN domain_list_columns VARCHAR(500) NULL COMMENT 'JSON list of column keys; NULL = default columns' AFTER avatar;

UPDATE settings
SET setting_value = '1.7.0'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('045_add_dns_history_cloudflare_v1.7.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
