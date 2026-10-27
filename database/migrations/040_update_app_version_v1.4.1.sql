-- Update application version to 1.4.1
-- The app name from Settings shows in the sidebar, sign-in pages and notifications

UPDATE settings
SET setting_value = '1.4.1'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('040_update_app_version_v1.4.1.sql')
ON DUPLICATE KEY UPDATE migration=migration;
