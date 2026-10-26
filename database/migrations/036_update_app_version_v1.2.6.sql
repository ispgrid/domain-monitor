-- Update application version to 1.2.6
-- Rollback restores the database again (backups no longer start with a mysqldump warning)

UPDATE settings
SET setting_value = '1.2.6'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('036_update_app_version_v1.2.6.sql')
ON DUPLICATE KEY UPDATE migration=migration;
