-- Update application version to 1.5.1
-- Days left count whole calendar days; no schema changes

UPDATE settings
SET setting_value = '1.5.1'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('042_update_app_version_v1.5.1.sql')
ON DUPLICATE KEY UPDATE migration=migration;
