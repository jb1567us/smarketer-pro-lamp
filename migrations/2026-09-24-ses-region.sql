-- SES region selector (Item 3, 2026-09-24).
-- Safe to run multiple times: INSERT IGNORE throughout, no DDL.
-- The SES adapter stores the buyer's chosen AWS region in the existing
-- settings table:
--   ses_region  one of the SES SMTP regions allowlisted in
--               App\EmailSender::sesRegions() (default us-east-1).
-- EmailSender builds the endpoint email-smtp.<region>.amazonaws.com from
-- it. Default us-east-1 preserves pre-Item-3 behavior for existing installs.

INSERT IGNORE INTO settings (setting_key, setting_value)
VALUES ('ses_region', 'us-east-1');
