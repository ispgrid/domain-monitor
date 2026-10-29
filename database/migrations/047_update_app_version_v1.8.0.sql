-- Update application version to 1.8.0
-- Site icons and custom logo are stored as files and settings; no schema changes

UPDATE settings
SET setting_value = '1.8.0'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('047_update_app_version_v1.8.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
