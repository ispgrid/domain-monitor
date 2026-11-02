-- Update application version to 1.10.0
-- Transfer and hijack alerts use the existing notification_status_triggers setting; no schema changes

UPDATE settings
SET setting_value = '1.10.0'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('049_update_app_version_v1.10.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
