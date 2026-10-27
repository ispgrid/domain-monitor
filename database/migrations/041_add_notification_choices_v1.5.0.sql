-- Update application version to 1.5.0
-- Notification groups choose which alerts they get; domains can be marked as watched to acquire

ALTER TABLE notification_groups
    ADD COLUMN notify_expiry TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Expiry reminders and expired alerts' AFTER description,
    ADD COLUMN notify_status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Status change and renewal alerts' AFTER notify_expiry,
    ADD COLUMN notify_dns TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'DNS change alerts' AFTER notify_status,
    ADD COLUMN notify_ssl TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'SSL status alerts' AFTER notify_dns;

-- own: expiry reminders as usual. acquire: no expiry or expired alerts, only the lifecycle steps that matter to a buyer
ALTER TABLE domains ADD COLUMN purpose VARCHAR(16) NOT NULL DEFAULT 'own' COMMENT 'own or acquire' AFTER ssl_monitoring_enabled;

UPDATE settings
SET setting_value = '1.5.0'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('041_add_notification_choices_v1.5.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
