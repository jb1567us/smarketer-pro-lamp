# FIX5 — Compliance copy reframed as buyer outcomes

Branch: `commercial/fixes-5` (from `commercial/fixes`). Copy/positioning only —
**no compliance behavior changed** (no gates, suppression logic, thresholds,
or audit writes were touched).

## Why

The product is sold as a self-hosted one-time license to forum marketers
(BlackHatWorld first). The buyer owns the server and the sending accounts,
so the buyer is the **data controller** and is liable for their own sending
practices. Any claim like "CAN-SPAM compliant!" or "fully compliant" would
(a) mislead the buyer about whose liability is at stake and (b) put the
seller in the position of guaranteeing legality we cannot guarantee. The
features (suppression list, sender-identity footer, CASL country gate,
verification gate, DNS preflight, throttle + bounce/complaint monitors with
auto-pause) are **guardrails that reduce business risk**: fewer provider
bans, protected sender reputation, cleaner lists, less wasted spend. The
copy now says exactly that.

## What changed (file by file)

- `index.php` (Settings UI):
  - Sender-identity help text reframed as deliverability, not law: mail
    without a real sender identity gets filtered as spam.
  - "Off — I accept the legal risk" / "Allow — I accept the legal risk"
    → "Off/Allow — my risk, my responsibility".
  - Added the strongest data-controller disclaimer next to the CASL master
    toggle (the setting where the buyer deliberately disables a guardrail):
    *you decide what gets sent, you are liable for your own sending
    practices; these gates protect your accounts — they do not make your
    sending legal.*
- `includes/Compliance.php`:
  - Class docblock reframed: "account-protection guardrails" instead of
    "CAN-SPAM / CASL / GDPR sending guardrails"; keeps and strengthens the
    data-controller/liability paragraph.
  - CASL block messages no longer claim CASL "prohibits" anything. They name
    the gate (the tests assert on "CASL" in the message), explain the risk in
    outcome terms ("these are the sends providers flag first"), and state the
    buyer owns the sending practices.
  - Sender-identity refusal: "(required by CAN-SPAM...)" →
    "providers flag or block commercial mail without a real sender identity."
- `includes/EmailSender.php`:
  - "required to satisfy SPF/DKIM compliance" → outcome: providers need the
    sender address to authenticate your mail; without it sends fail or get
    flagged as spoofed.
  - Choke-point and unsubscribe-header comments reframed as account
    protection (Gmail/Yahoo bulk-sender rules penalize missing headers,
    which damages the sender account's standing).
- `api/send_email.php`: suppression refusal now explains WHY — re-mailing
  opt-outs and complainers is the fastest way to get an account suspended.
- `assets/js/dashboard.js`:
  - Auto-pause banner: "Paused to protect your provider account from
    suspension: bounce or complaint rate crossed the safety threshold, and
    providers suspend accounts over exactly these signals." Points to
    diagnostics: bounce/complaint entries in email logs, DNS preflight on
    restart, sender auth in Settings; warns that resuming without fixing the
    cause burns more reputation.
  - Create-campaign modal: data-controller disclaimer added (you are liable
    for your own sending practices; nothing here makes a campaign's sending
    legal).
  - Import-leads modal: disclaimer added — importing a list doesn't make it
    safe to mail; guardrails protect accounts, not legality.
- `docs/COMPLIANCE_GAPS.md`:
  - New top-of-document positioning paragraph: guardrails ≠ legal shield;
    buyer is the data controller.
  - "One-click unsubscribe keeps you compliant with Gmail/Yahoo bulk-sender
    rules" → complaint-reduction framing: complaints are what those rules
    penalize, so fewer complaints means fewer penalty signals against the
    sender account.

## Deliberately NOT claimed

- Never "CAN-SPAM compliant", "GDPR compliant", "CASL compliant", or "fully
  compliant" anywhere.
- Never implied the product makes the buyer's sending legal, or that
  enabling a gate confers legal safety.
- Never removed the requirement language where the *product* enforces it
  (sender identity still blocks sending when unset; suppression still
  refuses) — only the *legal framing* was replaced with outcome framing.
- Test assertions on copy strings were **not** deleted or weakened: the
  CASL gate messages still name the "CASL country gate" (tests needle on
  "CASL"), and the run_compliance needles ('sender identity',
  'suppression list', 'country is unknown') still match. No behavior changed.

## Judgment calls

- Kept the words CASL/GDPR/CAN-SPAM where they name a feature (settings
  labels, class names, doc headings) — removing them would obscure which
  rule a gate implements. The reframing is about *claims of compliance*, not
  vocabulary.
- The unknown-country CASL refusal never asserted illegality ("prohibits")
  but still got the outcome treatment for consistency.
- DNS preflight already surfaces its own warnings on campaign start; the
  pause banner now cross-links to it rather than duplicating it.
