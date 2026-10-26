-- Update application version to 1.2.5
-- The updater now runs Composer when the web server user has no home directory

UPDATE settings
SET setting_value = '1.2.5'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('035_update_app_version_v1.2.5.sql')
ON DUPLICATE KEY UPDATE migration=migration;
