-- Update application version to 1.4.0
-- Adds the Logs area: cron runs, update history, commands and the activity log

CREATE TABLE IF NOT EXISTS cron_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'running',
    trigger_type VARCHAR(16) NOT NULL DEFAULT 'cron',
    triggered_by INT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    heartbeat_at DATETIME NULL,
    duration_ms INT UNSIGNED NULL,
    items_checked INT UNSIGNED NOT NULL DEFAULT 0,
    alerts_sent INT UNSIGNED NOT NULL DEFAULT 0,
    errors INT UNSIGNED NOT NULL DEFAULT 0,
    summary TEXT NULL,
    error_message TEXT NULL,
    output MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_cron_job_started (job, started_at),
    INDEX idx_cron_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS update_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action VARCHAR(16) NOT NULL,
    update_type VARCHAR(16) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'running',
    from_version VARCHAR(64) NULL,
    to_version VARCHAR(64) NULL,
    user_id INT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    duration_ms INT UNSIGNED NULL,
    files_updated INT UNSIGNED NULL,
    composer_status VARCHAR(16) NULL,
    db_backup_path VARCHAR(500) NULL,
    file_backup_path VARCHAR(500) NULL,
    log_file VARCHAR(255) NULL,
    details TEXT NULL,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_update_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS command_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(32) NOT NULL,
    label VARCHAR(100) NOT NULL,
    command TEXT NOT NULL,
    exit_code INT NULL,
    duration_ms INT UNSIGNED NULL,
    output MEDIUMTEXT NULL,
    user_id INT NULL,
    started_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_command_started (started_at),
    INDEX idx_command_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(255) NULL,
    action VARCHAR(64) NOT NULL,
    description VARCHAR(500) NOT NULL,
    entity_type VARCHAR(32) NULL,
    entity_id INT NULL,
    changes TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_created (created_at),
    INDEX idx_audit_action (action),
    INDEX idx_audit_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('log_retention_days', '30', 'string', 'Days to keep logs (0 keeps them forever)'),
('cron_alerts_enabled', '1', 'boolean', 'Alert admins when a cron job is late or keeps failing')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

UPDATE settings
SET setting_value = '1.4.0'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('039_add_logs_v1.4.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
