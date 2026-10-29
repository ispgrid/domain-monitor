-- Update application version to 1.7.1
-- Cloudflare connect and Sync now no longer wait for every zone; no schema changes

UPDATE settings
SET setting_value = '1.7.1'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('046_update_app_version_v1.7.1.sql')
ON DUPLICATE KEY UPDATE migration=migration;
