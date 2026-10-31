-- Per-domain alert switches: NULL follows the domain's notification group, 1 always sends, 0 never sends

ALTER TABLE domains
    ADD COLUMN notify_expiry TINYINT(1) NULL DEFAULT NULL COMMENT 'Override of the group switch, NULL = as group' AFTER purpose,
    ADD COLUMN notify_status TINYINT(1) NULL DEFAULT NULL COMMENT 'Override of the group switch, NULL = as group' AFTER notify_expiry,
    ADD COLUMN notify_dns TINYINT(1) NULL DEFAULT NULL COMMENT 'Override of the group switch, NULL = as group' AFTER notify_status,
    ADD COLUMN notify_ssl TINYINT(1) NULL DEFAULT NULL COMMENT 'Override of the group switch, NULL = as group' AFTER notify_dns;

INSERT INTO migrations (migration) VALUES ('048_add_alert_overrides_channels_v1.9.0.sql')
ON DUPLICATE KEY UPDATE migration=migration;
