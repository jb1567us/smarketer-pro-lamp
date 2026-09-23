# Compliance Gaps — Implementation Notes

This document describes the nine compliance gap items plus the schema/packaging
work (item 10) that closed them. It is written for the admin installing or
operating the app: what each feature does, its defaults, the settings involved,
the actions you must take, and the known limitations.

**Nothing here phones home. All gates run locally against your database.**

**What these features are (and aren't):** They are guardrails that reduce the
buyer's business risk — fewer provider bans, a protected sender reputation,
cleaner lists, less wasted spend. They do not make anyone's sending legal.
The buyer is the data controller and is liable for their own sending
practices; these features make risky sends harder to fire off accidentally,
never one click.

---

## 0. How to apply

- **Fresh install:** `install.php` imports `schema.sql`, which already contains
  every table/column below.
- **Existing install:** run `migrations/2026-09-23-compliance-gaps.sql` once
  (it is idempotent — safe to run twice). The older
  `migrations/2026-09-23-compliance.sql` (suppression list, consent columns)
  should already have been applied by the hardening deploy.

---

## 1. Bounce webhooks (`api/webhook_bounce.php`)

**What:** Receives bounce events from SendGrid (signed Event Webhook, ECDSA)
or any provider via generic HMAC-SHA256. Hard bounces, drops and blocks
auto-suppress the address with reason `hard_bounce`; every event is logged to
`webhook_events` and `email_logs`.

**Defaults:** No webhook secret is configured until you set one.

**Settings:**
- `webhook_secret` — auto-generated per install, stored in settings. Used for
  the generic HMAC check (`sha256=` hex signature header).
- `sendgrid_webhook_public_key` — paste your SendGrid Event Webhook public key
  here to enable ECDSA verification.

**Admin actions:**
1. In SendGrid: Mail Settings → Event Webhook → enable "Signed Event Webhook",
   copy the public key into `sendgrid_webhook_public_key`.
2. Point the webhook URL at `https://YOUR-APP/api/webhook_bounce.php`.

**Limitations:** Signature verification is only as good as the secret/key you
configure; with neither set, generic events are rejected (fail closed).

## 2. Complaint webhooks (`api/webhook_complaint.php`)

**What:** Receives spam-complaint events (`spamreport` / `complaint`).
Complained addresses are suppressed with reason `complaint`. The
`webhook_events` table is the complaint-rate source for the monitor (item 4).

**Admin actions:** Point your provider's complaint webhook at
`https://YOUR-APP/api/webhook_complaint.php` (same HMAC scheme as item 1).

## 3. Email verification gate (MillionVerifier)

**What:** Optional pre-send verification. When enabled, each recipient is
checked against the verification provider before sending:
- `invalid` → send blocked.
- `risky` → blocked or flagged per `verification_risky_action`.
- `unknown` (provider outage / inconclusive) → **fails open** (send proceeds)
  unless strict mode is on.
- Verdicts are cached per address for `verification_cache_days` and persisted
  to `leads.verification_status` / `leads.verified_at`.

**Defaults:** `verification_required='0'` — **OFF**. Zero behavior change until
you enable it.

**Settings:** `verification_required`, `verification_provider`
(`millionverifier`), `verification_api_key`, `verification_risky_action`
(`block`|`flag`), `verification_strict` (`0`|`1`), `verification_cache_days`
(`30`; `0` = always re-verify).

**Admin actions:** Get a MillionVerifier API key, paste it into
`verification_api_key`, then set `verification_required='1'`.

**Limitations:** Only MillionVerifier is implemented; the provider interface
(`includes/Verification/EmailVerificationProvider.php`) is pluggable for more.

## 4. Send throttles, monitoring, auto-pause

**What:**
- **Throttles:** per-campaign daily cap (`campaigns.daily_send_cap`),
  per-provider daily cap (`throttle_provider_daily_cap` +
  `throttle_provider_daily_cap_<provider>` overrides), global per-minute cap
  (`throttle_sends_per_minute`). Capped work is **deferred** (kept Pending,
  retried later) — never dropped.
- **Monitor:** rolling 7-day complaint rate (complaints ÷ delivered) and hard-
  bounce rate. At **≥ 0.1% complaints** or **≥ 5% bounces** (with at least
  `monitor_min_delivered` delivered), the campaign is auto-paused
  (`status='paused'`, reason + timestamp recorded) and a red banner appears on
  the dashboard with a one-click **Resume** button. There is no automatic
  resume — you review the pause reason, fix the list, then resume manually
  (dashboard banner or `POST api/campaigns.php?type=campaigns&action=resume`).

**Defaults:** all caps `0` (unlimited), `monitor_auto_pause='1'`,
`monitor_min_delivered='100'`, thresholds `0.001` / `0.05`.

**Admin actions:** Set `campaigns.daily_send_cap` per campaign if you want
per-campaign pacing; tune thresholds in Settings. Review the dashboard banner
if anything pauses.

**Limitations:** Rates come from `email_logs` + `webhook_events`; sends that
bypass the router/API (none in normal operation) would not be counted.

## 5. GDPR: export, erasure, objection, lawful basis

**What:**
- **Export** (`api/gdpr.php`, admin-authenticated JSON): every row the app
  holds for an address — leads, email_logs, suppression_list (plaintext +
  hash rows), webhook_events, casl_decisions.
- **Erasure** (Art. 17): deletes the subject's PII everywhere above, then
  retains **one hash-only suppression row** (`email_hash`, no plaintext
  email) so "do not contact" survives erasure.
- **Objection** (public, signed URL): suppresses + erases without revealing
  whether the address exists (constant-time, identical response either way).
- **Lawful basis:** free-form `leads.lawful_basis` column, settable on import
  and manual add.

**Defaults:** Erasure requires the `suppression_list.email_hash` column
(the migration); without it the API refuses with a clear error rather than
doing a half-erasure.

**Admin actions:** Run the migration. Respond to subject requests via
`api/gdpr.php`.

**Limitations:** Backups are out of scope — erasure covers the live database.

## 6. SPF/DKIM/DMARC preflight

**What:** Before a campaign starts, the app checks the sender domain's SPF,
DKIM and DMARC via `dns_get_record()`:
- **All three missing → campaign start is blocked** (clear error).
- **Partial → warning, start allowed.**
- Results cached 24h (`domain_auth_cache`, TTL via `dns_preflight_cache_hours`).
- Per-campaign override flag (`campaigns.dns_preflight_override`) for edge
  cases (e.g. you know the records are fine but DNS is temporarily broken).

**Defaults:** enforced for every campaign start; override off.

**Admin actions:** Publish SPF/DKIM/DMARC for your sending domain (your DNS
provider). Use the override only deliberately — it is per-campaign and
auditable.

## 7. Provider unsubscribe headers

**What:** `List-Unsubscribe` / `List-Unsubscribe-Post` headers are now added
on every provider path that supports custom headers (Brevo, Mailgun, Mailjet,
Postmark, MailerSend, Mailtrap, ZeptoMail/Zoho, Pepipost/Netcore, Resend,
SendGrid, plus all SMTP paths). One-click unsubscribe lowers complaint volume — and complaints are the signal Gmail/Yahoo bulk-sender rules actually penalize, so fewer complaints means fewer penalty signals against your sender account. (No inbox-placement promise: placement still depends on your provider, account reputation, list, and DNS.)

**Limitations:** Pepipost's custom-header mechanism and MailerSend's (may
need a paid plan) should get a live smoke test; the footer link + suppression
list remain active regardless.

## 8. CASL recipient-country handling

**What:** Replaces the old ".ca email heuristic" with an explicit,
auditable gate:
- `leads.country_code` (ISO-3166-1 alpha-2, NULL = unknown), editable in the
  add/edit lead modals and importable via CSV.
- Every send runs one decision point: **unknown country + non-express consent
  → blocked by default**; express consent → allowed; master toggle
  `compliance_casl_ca_block` preserved for backward compatibility;
  `compliance_casl_unknown_country` (`block`|`allow`, default `block`)
  controls the unknown-country default.
- Every decision is written to `casl_decisions` (email, country, allow/block,
  rule) — full audit trail.

**Defaults:** blocking; unknown country fails closed.

**Admin actions:** Fill in `country_code` for Canadian (and other) leads;
set consent status accurately. Review `casl_decisions` if a send is blocked
unexpectedly.

## 9. Persona vs contact name

**What:** Persona text (e.g. "VP of Sales") is stored only in
`leads.target_persona`. `contact_name` is set only when the input is
conservatively recognized as a person's name, otherwise NULL. The harvester
no longer writes persona text into `contact_name`.

**Note:** pre-existing bad rows are **not** backfilled — spot-check old leads
if you rely on `contact_name`.

## 10. Schema/migration parity + reliability fixes

- `migrations/2026-09-23-compliance-gaps.sql` (idempotent) + full mirror in
  `schema.sql`: `webhook_events`, `domain_auth_cache`, `casl_decisions`,
  `email_logs.campaign_id` + `status='bounced'`, `leads.campaign_id` /
  `country_code` / `lawful_basis`, `campaigns.status|paused_reason|paused_at|
  daily_send_cap|dns_preflight_override`, suppression hash redesign,
  `leads.status='Drafted'` (the drafting agents already wrote it — the ENUM
  now accepts it).
- `email_logs` rows now carry `campaign_id` (router, queue worker, manual
  send) so per-campaign caps and the rate monitor actually attribute sends.
- CSV import no longer fatals on the PDO shim (raw `START TRANSACTION` /
  `COMMIT` / `ROLLBACK`), and duplicate emails are counted as skipped instead
  of inflating the imported count.
- Harvester INSERT survives duplicate-key races (counted as duplicates).
- The installer test now verifies the fresh schema, admin creation + login,
  and a real lead insert.

---

## Support posture

These features are designed to need no support: every gate fails safe
(blocks or degrades rather than crashing), every destructive default is off
until you configure it, and every auto-action (pause, suppression) is visible
in the UI with a manual undo.
