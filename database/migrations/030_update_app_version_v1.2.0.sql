-- Update application version to 1.2.0
-- PHP 8.5 support, minimum PHP 8.2, endroid/qr-code 6.x

UPDATE settings
SET setting_value = '1.2.0'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('030_update_app_version_v1.2.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
