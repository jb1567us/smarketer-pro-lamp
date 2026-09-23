# Fix 3 — Self-diagnostics + support deflection

## What changed

- **`includes/Diagnostics.php`** (new) — the whole feature. Six checks, each
  returning `pass` / `warn` / `fail` plus a plain-language explanation and an
  exact fix step written for a non-technical cPanel buyer:
  1. **PHP version + extensions** — mirrors the installer's pre-flight list
     (PHP ≥ 7.4; mysqli, curl, json, session, mbstring, openssl).
  2. **Sender-domain email authentication** — SPF/DKIM/DMARC via the existing
     `DnsAuth::checkDomainCached()` (compliance build), for the domain in the
     `email_sender` setting.
  3. **Provider connectivity** — for every provider with saved credentials,
     verifies the key against a read-only auth endpoint (SendGrid, Resend,
     Mailgun, Brevo, Mailjet, Postmark, MailerSend, Mailtrap, ZeptoMail,
     Pepipost) or an SMTP login handshake (smtp, custom_smtp, amazon_ses,
     sendpulse, zoho_smtp, netcore_smtp). **Never sends anything**: no
     `/send` POST, no `MAIL FROM` / `RCPT TO` / `DATA` — the SMTP probe stops
     at `235 Authentication succeeded` and QUITs.
  4. **Cron / queue health** — last worker run, queue depth (Pending /
     In Progress), stuck tasks (>30 min "In Progress"), whether a run is
     active now.
  5. **License status** — reuses `Licensing::statusForUi()`; revoked/invalid
     is a fail, unlicensed/unreachable is a warn (fail-open).
  6. **Database structure** — verifies the tables and compliance columns the
     app needs (`suppression_list`, `webhook_events`, `domain_auth_cache`,
     `casl_decisions`, `leads.consent_status`, …).
- **`api/diagnostics.php`** (new) — admin-only JSON endpoint (`Auth::requireApiAuth`,
  GET only). Returns all check results plus a pre-rendered plain-text report.
  Every check is individually guarded: one broken probe can never 500 the page.
- **`assets/js/diagnostics.js`** (new) — renders the result cards, fills the
  report textarea, and copies it (`navigator.clipboard` with
  select+`execCommand` fallback). The report contains **no passwords or API
  keys** by construction — credentials are only ever used server-side and
  never echoed into results.
- **`index.php`** — new "🩺 Diagnostics" sidebar tab + tab content (results,
  paste-ready report textarea, support-boundaries box). A small inline script
  decorates `showTab()` from `dashboard.js` so the tab works without touching
  the shared dashboard bundle.
- **`tests/diagnostics/`** — 34 offline unit tests (mocked HTTP/DNS/SMTP,
  no database): verdict logic for every check, credential non-echo, and the
  report renderer.
- **`Diagnostics::APP_VERSION`** — the single place the app version lives
  (shown in the UI and the report). Bump on release.

## Why

Support is the product's biggest cost: the buyer is a non-technical forum
marketer on shared hosting, and every "my emails don't send" ticket starts
with 3–4 back-and-forth messages just to establish the basics. The
Diagnostics page answers the five questions behind ~90% of those tickets
(PHP/extensions, DNS auth, provider key validity, cron running, license
state, DB completeness) before the buyer ever posts, and the copy-paste
report makes the remaining posts answerable in one reply. The support
boundaries box ("community forum only, no guaranteed response times, always
paste the report, never post keys") is the deflection mechanism: it sets
expectations at the exact moment the buyer is asking for help.

Paid priority support is deliberately **not** mentioned in the UI — no paid
support program exists yet, and advertising one would invent an obligation.
Add it when the terms are real.

## How a buyer uses it

1. Log in → sidebar → **🩺 Diagnostics**.
2. Read the cards. Green = fine. Amber/red = read the "How to fix" box and
   do what it says, then hit **Re-run checks**.
3. Still stuck? Hit **Copy report**, paste it into a forum post. That's the
   whole support workflow.

## Notes / deliberate trade-offs

- The cron check reads a `queue_last_run_at` setting that the queue worker
  does not write yet (that 3-line tick was kept out of this commit per scope
  discipline). Until it lands, a healthy worker shows "never recorded" as a
  **warn**, not a fail — honest, and the fix text tells the buyer how to set
  up the cron job.
- JEV stays disabled by default; nothing here touches it.
- The SMTP probe needs outbound ports 25/587/465; when the host blocks them
  the check reports **warn** (not fail) and suggests an HTTP API provider.
