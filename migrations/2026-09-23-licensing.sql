-- Licensing migration (2026-09-23).
-- Safe to run multiple times: INSERT IGNORE throughout, no DDL.
-- The license lock stores everything in the existing settings table:
--   license_server_url  base URL of the license-server API (empty = disabled)
--   license_key         buyer's key (managed via Settings → License)
--   license_verdict     HMAC-signed cached verdict blob (written by the app)

INSERT IGNORE INTO settings (setting_key, setting_value)
VALUES ('license_server_url', '');
