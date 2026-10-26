-- Update application version to 1.2.7
-- Pages show updated templates right after an update (template cache refresh)

UPDATE settings
SET setting_value = '1.2.7'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('037_update_app_version_v1.2.7.sql')
ON DUPLICATE KEY UPDATE migration=migration;
