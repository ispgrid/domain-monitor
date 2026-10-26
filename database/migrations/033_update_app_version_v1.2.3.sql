-- Update application version to 1.2.3
-- composer.lock is now committed and Composer resolves dependencies for PHP 8.2

UPDATE settings
SET setting_value = '1.2.3'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('033_update_app_version_v1.2.3.sql')
ON DUPLICATE KEY UPDATE migration=migration;
