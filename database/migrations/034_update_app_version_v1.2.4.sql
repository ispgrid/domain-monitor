-- Update application version to 1.2.4
-- Adds SSL alert days and the Billing preview toggle (Renewed alerts are off until enabled in Settings)

INSERT INTO settings (setting_key, setting_value, `type`, `description`) VALUES
('ssl_notification_days', '30', 'string', 'Days before SSL certificate expiry to send alerts'),
('show_billing_tab', '0', 'boolean', 'Show the Billing preview on the domain page')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

UPDATE settings
SET setting_value = '1.2.4'
WHERE setting_key = 'app_version';

INSERT INTO migrations (migration) VALUES ('034_update_app_version_v1.2.4.sql')
ON DUPLICATE KEY UPDATE migration=migration;
