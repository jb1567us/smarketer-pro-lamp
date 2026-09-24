-- Email verification settings (ITEM 1, 2026-09-24).
-- Safe to run multiple times: INSERT IGNORE throughout, no DDL.
-- Seeds the keys the Settings → Email Verification section writes and the
-- verification send gate (includes/Compliance.php) reads. Verification stays
-- OFF by default ('verification_required' = '0'): the funnel shows
-- "0 checked" until the buyer enables it with their own MillionVerifier key.
--
-- Keys:
--   verification_required      '1' enables the send gate, anything else disables (default '0')
--   verification_provider      provider id, currently only 'millionverifier'
--   verification_api_key       buyer's MillionVerifier API key (secret, redacted on settings read)
--   verification_risky_action  'block'|'flag' — what to do with 'risky' (catch-all) verdicts (default 'block')
--   verification_strict        '1' also blocks sends on 'unknown' verdicts (default '0' = fail open)
--   verification_cache_days    reuse a cached verdict for this many days (default '30'; '0' = always re-verify)

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('verification_required', '0'),
    ('verification_provider', 'millionverifier'),
    ('verification_api_key', ''),
    ('verification_risky_action', 'block'),
    ('verification_strict', '0'),
    ('verification_cache_days', '30');
