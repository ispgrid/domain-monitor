-- Update application version to 1.2.1
-- Fixes for multi-level ccTLDs, .sk/.it WHOIS parsing, updater on open_basedir hosts; updater PHP check

UPDATE settings
SET setting_value = '1.2.1'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('031_update_app_version_v1.2.1.sql')
ON DUPLICATE KEY UPDATE migration=migration;
