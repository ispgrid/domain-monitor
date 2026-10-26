-- Update application version to 1.2.2
-- Session race fix, DNS refresh on Windows, updater reads commit SHA from archive comment

UPDATE settings
SET setting_value = '1.2.2'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('032_update_app_version_v1.2.2.sql')
ON DUPLICATE KEY UPDATE migration=migration;
