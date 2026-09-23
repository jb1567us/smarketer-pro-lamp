# FIX 1 — Provider-first sending, honest deliverability copy

## What changed (file by file)

- `index.php` (Settings UI):
  - The "Active Outreach Method" selector now lists API providers first under
    an "API providers — Recommended" optgroup, with SendGrid, Resend, and
    Amazon SES annotated "★ Recommended". The old default-first position of
    SMTP is gone.
  - Shared-host SMTP and the other SMTP paths moved to an "Advanced — not
    recommended" optgroup, and the SMTP option itself reads "Shared-host
    SMTP (not recommended)".
  - A disclaimer sits under the selector: "Emails go out through *your*
    provider account, on *your* domain. This software provides no sending
    infrastructure and makes no inbox-placement promises."
  - The SMTP credential group now opens with an amber "Advanced — not
    recommended" banner: mail leaves from the shared host's IP, shared-hosting
    IP reputation is outside our control, expect worse deliverability than a
    dedicated sending provider, testing/very-low-volume only, no
    inbox-placement promises.
  - Amazon SES is now selectable in the UI (`amazon_ses` was supported by
    `EmailSender` and the router but had no option and no field group; the
    generic SMTP fields serve it, and `toggleActiveProviderFields()` /
    `updateSetupProgress()` now treat `amazon_ses`/`sendpulse` as SMTP-group
    providers alongside `smtp`/`custom_smtp`/`zoho_smtp`/`netcore_smtp`).
  - The setup-card copy ("Set Up Email Sender") was rewritten: "Connect your
    own sending account (SendGrid, Resend, Amazon SES). Your account, your
    reputation — the app only sends through it."
- `includes/SendNotice.php` (new): pure, unit-testable builder
  (`\App\SendNotice::build()`) for the pre-send notice — provider label,
  sender address, configured flag, and fixed honest body text. Also
  `PROVIDER_LABELS` / `API_PROVIDERS` / `SMTP_PROVIDERS` constants and
  `uiOrder()` (API providers always before SMTP paths).
- `api/campaigns.php`:
  - New `campaign_send_notice()` helper (reads `active_email_provider` and
    `email_sender` from settings; computes a boolean "credentials stored"
    flag without ever returning secrets).
  - The toggle action attaches `send_notice` when a campaign is ACTIVATED
    (launch). Pausing is untouched.
  - The manual `resume` action (auto-paused campaigns) also attaches
    `send_notice`.
- `assets/js/dashboard.js`:
  - `toggleCampaignActive()` shows the honest-notice modal on successful
    activation; `resumeCampaign()` shows it on successful resume. The modal
    names the provider account and sender address, prints the notice, and —
    when no credentials are stored — warns that sends will fail.
  - New `showSendNotice()` modal (same `#modal-container` pattern as the
    other modals, `closeModal()` to dismiss).
- `tests/sending/SendNoticeTest.php` (new, 46 assertions): notice honesty,
  provider ordering, UI selector order/annotations, demotion copy presence,
  a copy-scrub regression scan (UI + JS + notices + `docs/*.md`), and API
  wiring of `send_notice` on both launch paths.
- `docs/COMPLIANCE_GAPS.md`, `docs/FIX5_COMPLIANCE_OUTCOMES.md`: softened
  two mechanism phrases that implied inbox protection into account-standing
  language with an explicit no-promise note (they were internal docs, but
  the scrub is total per the brief).

## Why

The buyer owns the server and the sending accounts. Positioning shared-host
SMTP first implied the software handles delivery; it doesn't — it hands
messages to the buyer's provider (or the shared host's mail path). Forum
marketers will publicly punish a product that over-promises and
under-delivers, so every claim is now about the *workflow* ("sends through
your provider") and never about *outcomes* (inbox placement, delivery
rates). The launch notice exists so the buyer sees, in their own words at
launch time, which account is about to send and what we don't control.

## Deliberately NOT claimed

- No outcome promises anywhere: nothing is claimed about where messages land,
  what delivery rates to expect, or any guarantee of delivery — the scrub also
  removed the three borderline mechanism phrases that could read as promises.
- No claim that deliverability *improves* by choosing an API provider — only
  that shared-host IP reputation is outside our control and worse results
  should be expected.
- The pre-send notice is informational, not a gate: it does not block launch.
  (The existing DNS preflight gate already covers blocking; stacking a second
  blocker would have annoyed without informing.)
- JEV stays disabled by default (`jev_enabled` untouched; default `0`).
- Runtime provider fallbacks (`?? 'smtp'` in the queue worker, router, and
  manual-send API) were NOT changed: silently swapping an existing install's
  fallback to an API provider with no key stored would break sending, not
  improve it. Recommendation lives in the UI and guidance, as briefed.

## Judgment calls

- The brief asked for SES among the recommended providers, but SES had no
  UI option at all (only SMTP-path support in code). It was added to the
  selector as "Amazon SES ★ Recommended", wired to the existing generic SMTP
  fields — no new backend work needed.
- Mailtrap stays in the API group but is labeled "(testing sandbox)", and
  its notice says messages are "never delivered to real inboxes".
- `mail()`/sendmail was never a sending path in this codebase (the only
  match is a code comment noting the raw socket bypasses it), so the
  "Advanced — not recommended" section covers shared-host SMTP only.
- Test counts moved between runs (72 → 81) while a sibling agent worked in
  the same tree; repeated runs are now stable at 81/81 with zero failures,
  and none of the changed files touch the compliance layer.
