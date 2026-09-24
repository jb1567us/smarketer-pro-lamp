# Item 3 — SES region selector

**Branch:** `commercial/item3-ses-region`
**Date:** 2026-09-24

## What changed

SES sending was hardcoded to `email-smtp.us-east-1.amazonaws.com` in
`includes/EmailSender.php`. Buyers whose SES identities / SMTP credentials
live in another AWS region couldn't send (SES SMTP credentials are issued
per region). There is no SES API sending path in this app — sending is
SMTP-only — so the SMTP host was the only endpoint to fix (the whole tree
was searched for `amazonaws` and `ses.` endpoint constructions; the single
hardcoded host was the only hit).

### New setting: `ses_region`

- Stored in the `settings` table like every other setting. Not a secret
  (comes back in plaintext on settings GET).
- UI: region dropdown in Settings → Email → Advanced API Credentials &
  Passwords, next to the Amazon SES SMTP Pass input. Helper text warns that
  SES SMTP credentials are per-region.
- Default: `us-east-1` — existing installs behave exactly as before.

### Single source of truth: `App\EmailSender`

- `SES_DEFAULT_REGION = 'us-east-1'`
- `sesRegions(): array` — the hardcoded allowlist (15 commercial SES SMTP
  regions, see below). GovCloud excluded (unusable by cPanel buyers).
- `isValidSesRegion($raw): bool`
- `normalizeSesRegion($raw): string` — trims/lowercases; anything off-list
  → default. The sender can never build an invalid endpoint from a corrupt
  setting.
- `configuredSesRegion(): string` — reads `ses_region` via
  `Database::getSetting`, validated.
- `sesSmtpHost(?string $region = null): string` — builds
  `email-smtp.<region>.amazonaws.com`; `null` = buyer's configured region.

`sendViaSmtpSocket()` for `amazon_ses` now uses `self::sesSmtpHost()`.
Precedence: explicit `amazon_ses_smtp_host` → regional default.
(Port/encryption defaults unchanged: 587/TLS.)

### Server-side validation (the judgment call)

**Off-list regions are refused with HTTP 400** by `api/settings.php`
(`SETTINGS_ALLOWLIST` + an `isValidSesRegion` check in the POST/PUT path;
the value is lowercased/trimmed before storage). Rationale: the API
already 400s on unknown keys, so refusing fits the existing contract, and
silently falling back would mask buyer mistakes (wrong region selected).
Belt-and-braces: read-side normalization (`normalizeSesRegion`) still
falls back to `us-east-1` if a bad value ever reaches the DB by another
path (manual SQL, old backup restore), so sending can never break on a
corrupt setting.

## Region list (verified against AWS's documented SES SMTP regions)

| Code | Name |
|---|---|
| us-east-1 | US East (N. Virginia) — default |
| us-east-2 | US East (Ohio) |
| us-west-2 | US West (Oregon) |
| eu-west-1 | Europe (Ireland) |
| eu-west-2 | Europe (London) |
| eu-central-1 | Europe (Frankfurt) |
| eu-north-1 | Europe (Stockholm) |
| eu-south-1 | Europe (Milan) |
| ap-south-1 | Asia Pacific (Mumbai) |
| ap-southeast-1 | Asia Pacific (Singapore) |
| ap-southeast-2 | Asia Pacific (Sydney) |
| ap-northeast-1 | Asia Pacific (Tokyo) |
| ap-northeast-2 | Asia Pacific (Seoul) |
| ca-central-1 | Canada (Central) |
| sa-east-1 | South America (Sao Paulo) |

Source: AWS "Amazon SES endpoints and quotas" + the SES developer guide's
official SMTP-credential generator `SMTP_REGIONS` list. Regions with an SES
API but no SMTP endpoint (e.g. us-west-1, eu-west-3) are deliberately
excluded; GovCloud excluded for the buyer profile.

## Files changed

- `includes/EmailSender.php` — region allowlist + helpers; SMTP fallback
  uses `sesSmtpHost()`.
- `api/settings.php` — `ses_region` allowlisted; 400 on off-list values.
- `index.php` — region dropdown in the SES settings block.
- `assets/js/dashboard.js` — `saveSettings()` posts `ses_region`.
- `includes/Diagnostics.php` — SES check is region-aware: probes the
  regional endpoint (previously fell back to the unrelated global
  `smtp_host`, or failed "host missing" on default installs that send
  fine); results name the region; fail-fix explains credentials are
  per-region.
- `migrations/2026-09-24-ses-region.sql` — idempotent
  `INSERT IGNORE` seed (`ses_region = us-east-1`); apply at deploy like
  the 2026-09-23 migrations.
- `schema.sql` — seeds `ses_region` for fresh installs (install.php
  imports schema.sql).
- `tests/sending/SesRegionTest.php` — 30 assertions (see below).

## Tests

- `tests/sending/SesRegionTest.php` — **33/33 pass**: endpoint per region
  (15/15), allowlist rejection (us-west-1, eu-west-3, GovCloud, junk,
  injection-shaped), normalization, default preserved, wiring assertions
  (API/UI/JS/Diagnostics source checks), mocked-probe diagnostics
  (regional probe, default install, custom-host precedence),
  migration/installer coverage.
- `tests/sending/SendNoticeTest.php` — **46/46 pass** (unchanged).
- `tests/diagnostics/run_diagnostics_tests.php` — **34/34 pass** (unchanged).
- `tests/compliance/run_compliance_tests.php` — **81/81 pass** (unchanged).
- `tests/install/run_install_test.php` — **51/51 pass** (fresh install gets
  `ses_region` via schema.sql; unchanged).
- E2E against a scratch install (local MySQL + `php -S`): valid
  `eu-west-1` → 200; `us-west-1` → **400** "Invalid SES region";
  injection-shaped → **400**; `'  AP-SOUTHEAST-2 '` → 200, stored
  canonical as `ap-southeast-2`.
- Migration `migrations/2026-09-24-ses-region.sql` applied **twice** on
  real MySQL → idempotent, single `ses_region=us-east-1` row.
- `php -l` clean on all touched PHP files; `node --check` clean on
  `assets/js/dashboard.js`.

## Buyer-facing behavior

1. New install: region defaults to us-east-1; works as before.
2. Existing install (upgrade): migration seeds us-east-1; behavior
   unchanged until the buyer picks a region.
3. Buyer with SES credentials in eu-west-1: selects the region, saves,
   re-runs Diagnostics → probe targets
   `email-smtp.eu-west-1.amazonaws.com` and the result says so.
4. Wrong region selected: sends fail auth (expected — credentials are
   per-region); the diagnostics fail-fix tells the buyer exactly this.
