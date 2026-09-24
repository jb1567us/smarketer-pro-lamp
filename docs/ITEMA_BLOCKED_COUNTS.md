# ITEM A — Blocked-send counts in the campaign view

**Date:** 2026-09-24 · **Branch:** `commercial/itemA-blocked-count`

Every campaign card now shows a **"🚫 Blocked: N"** indicator when sends were
refused, with a plain-language breakdown of *why* and where to look next.

## What changed

**Storage** — six counter columns on `campaigns` (fresh installs: `schema.sql`;
existing installs: `migrations/2026-09-24-blocked-counts.sql`, idempotent via
the `add_col_if_missing` procedure pattern):

| Column | Reason |
|---|---|
| `blocked_invalid_verification` | email-verification gate refused the address (invalid, risky-with-block, or strict-unknown) |
| `blocked_suppression` | address on the suppression list (opt-out, bounce, complaint) |
| `blocked_compliance_pause` | CASL country gate blocked it, or sender identity (company name / postal address) is not configured |
| `blocked_throttle` | a throttle cap refused it for now (campaign/provider daily cap, per-minute limit) |
| `blocked_license_revoked` | the license key was revoked; sending is paused |
| `blocked_placeholder` | fabricated harvester address (`*@placeholder.com`) |

**Counting** — `includes/BlockedCount.php` (`App\BlockedCount::record()`).
One `UPDATE … SET col = col + 1` per refused send; one column-exists probe per
process (cached). Never throws, never blocks sending: pre-migration schemas
silently no-op, a dead database logs and moves on, a null campaign id is
ignored. A lost increment on crash is accepted; counts live in the table so
they survive page reloads and cron restarts.

**Instrumentation** (every refusal path, each counted exactly once):

- `Compliance::requireCompliantSend($to, $lead, $campaignId)` — all five gate
  refusals route through the new `refuseSend()` choke point, which records
  *before* throwing the byte-identical `OutreachException`. The verification
  gate records inside `runEmailVerificationGate()` (catch → record →
  rethrow), so cached-verdict refusals count too.
- `EmailSender::send(..., $campaignId)` — placeholder refusal; passes the id
  through to the gate.
- `SmartEmailRouter::send()` — throttle denial and placeholder fail-fast
  (the router never reaches `EmailSender` for those paths); campaign id flows
  into `attemptDelivery()` → `EmailSender::send()`. Guardrail refusals
  (`OutreachException` from the gate — suppression, compliance, license,
  verification) are rethrown instead of failing over to the next provider:
  the refusal is already counted once, and another provider would count it
  again. In the queue worker this surfaces as a Failed task with the refusal
  message.
- `api/send_email.php` — the fail-fast pre-checks (placeholder, suppression)
  record before throwing, since `EmailSender` is never reached there.
- `cron/process_queue.php` — throttle deferrals (task kept Pending, retried
  later) count as `throttle` at the moment of refusal.

**Display** — `assets/js/blocked_counts.js` (new), loaded in `index.php`
before `dashboard.js`; `fetchCampaigns()` inserts `campaignBlockedHtml(c)`
into each card. Renders only when the total is > 0. The API already returns
`SELECT *`, so the counters arrive with the existing payload.

**Diagnostics** — the blocked columns were added to
`Diagnostics::expectedSchema()`, so a missing ITEM A migration shows up as a
schema FAIL with the standard "import the /migrations files" fix text.

## Copy decisions

Rules: plain language, no jargon, no promises about lead quality or inbox
placement. Every line says what happened and what the buyer can do.

- *bad addresses* — "These failed email verification, so they were never
  mailed. Clean your list or adjust verification in Settings." → Settings
- *opt-outs, bounces, complaints* — "Mailing these risks getting your sending
  account suspended, so they stay blocked." → Diagnostics
- *compliance rules* — "Paused to protect your provider account — consent or
  sender-identity rules refused these sends." → Diagnostics
- *sending limits hit* — "These sends were held back by your sending limits
  and retry on their own." → Diagnostics
- *license revoked* — "Sending is paused until the license key is valid
  again." → Settings (License section)
- *made-up addresses* — "Your list contained fake addresses that were never
  real mailboxes. Fix the source list — no setting changes this." (no link;
  the fix is the buyer's list, not the app)

Links use in-page tab switching (`showTab('diagnostics')`), not full reloads.

## Deliberately not claimed

- **Throttle counts can exceed unique emails.** A deferred queue task is
  counted each time a cap refuses it, then usually sends later. The count
  means "refused attempts", not "dead emails" — the copy says "held back …
  retry on their own" for exactly this reason.
- **`compliance_pause` conflates three things** (CASL block, missing sender
  identity). Both are "a compliance rule refused this send"; splitting them
  would double the schema for no buyer-actionable difference.
- **No per-address drill-down.** The counters are totals, not a log. A
  per-recipient refusal log would be a new table and new privacy surface;
  the suppression list and `casl_decisions` already cover the auditable cases.
- **`license_revoked` is counted per campaign** even though a revoked key
  pauses *all* sending. The per-campaign number answers "why did *this*
  campaign stop", which is the question the card is for.
- **Monitor auto-pauses are not counted.** `SendMonitor` pausing a whole
  campaign is already visible on the card (Active/Paused toggle +
  `paused_reason`); counting every unsent email on top would be noise.
- **Nothing about deliverability.** The copy never says verification or the
  counters improve inbox placement — consistent with the fix-1 honest-sending
  rules.

## Files

- `includes/BlockedCount.php` (new) · `migrations/2026-09-24-blocked-counts.sql` (new)
- `assets/js/blocked_counts.js` (new) · `docs/ITEMA_BLOCKED_COUNTS.md` (this file)
- Modified: `includes/Compliance.php`, `includes/Licensing.php` (test seam
  only), `includes/EmailSender.php`, `includes/Routers/SmartEmailRouter.php`,
  `includes/Diagnostics.php`, `api/send_email.php`, `cron/process_queue.php`,
  `assets/js/dashboard.js`, `index.php`, `schema.sql`
- Tests: `tests/sending/BlockedCountTest.php` (new, 34 assertions),
  `tests/sending/blocked_counts_display_test.js` (new, 28 assertions);
  extended `tests/compliance/run_compliance_tests.php` (MariaDB wiring +
  migration idempotency) and `tests/install/run_install_test.php`
  (fresh-schema column assertions).

## Open smell (not fixed here)

The compliance monitor's auto-pause sets `campaigns.is_active = 0`, but
nothing in the queue path (`cron/process_queue.php`, `includes/runner.php`,
`TaskProcessor`) checks campaign active/paused status before processing
already-queued `EmailOutreach` tasks. A monitor-paused campaign's queued
tasks appear to keep sending until the queue drains. Worth a look before
launch — out of ITEM A scope, flagged not fixed.
