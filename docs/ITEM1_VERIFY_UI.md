# ITEM1 — Verification settings UI

Branch: `commercial/item1-verify-ui` (built on the integrated commercial/fixes state at 949a504)

## What changed

There was a working verification send gate and a MillionVerifier adapter, but no
way to turn them on from the product: the funnel sat at "0 checked" forever and
a buyer had no idea the feature existed. This item adds a settings-tab section
that makes verification discoverable, stays honest about what it does, and keeps
the default OFF.

### Settings UI (`index.php`)

New card **"Email Verification (MillionVerifier)"** in Settings, placed between
the Network/Proxy card and Global Logic. It contains:

- **Enable/disable toggle** (`select#setting-verification_required`):
  `0` = Disabled (default) / `1` = Enabled — verify before send. Saved through
  the normal Save Changes flow.
- **MillionVerifier API key field** (`input#setting-verification_api_key`,
  `type=password`) with a "Get Key" link to millionverifier.com. Stored and
  masked exactly like every other provider key (see below).
- **"Test MillionVerifier Connection" button** (`testVerificationConnection()` in
  `assets/js/dashboard.js`). Server-side test only. It can test a freshly typed,
  not-yet-saved key (sent in the POST body, never persisted by the test) or fall
  back to the stored setting. Renders the result inline; every message is
  HTML-escaped.

### New endpoint: `api/verification_test.php`

- POST, requires authenticated session + CSRF token.
- Tests the key against MillionVerifier's **credits-balance endpoint**
  (`/api/v3/credits`), not a live verification — the test spends **zero**
  verification credits.
- Tight cURL timeouts (8s connect / 12s total), TLS verified.
- **Key handling:** the key is never persisted, never logged, and never echoed
  in any response. Transport failures log the errno only (the request URL
  embeds the key, so URLs are never logged).
- **Rejection detection:** MillionVerifier answers a bad key with HTTP 200 +
  `{"result":"error","error":"apikey_not_found"}` — so a 2xx alone is not
  acceptance; any `result:"error"` or `error` field in the payload is treated
  as a key rejection. This was verified live against the real endpoint with a
  dummy key. Success returns the remaining credit balance (parsed from either
  a bare number or a `credits` JSON field; `null` when unreadable, which is
  still reported as "accepted").
- The testable core is `testMillionVerifierKey(string $key, ?callable $httpGet)`;
  the file defines `VERIFICATION_TEST_UNIT` as the CLI-test bypass for the
  request-handling block.

### Wiring to the existing gate

No new setting keys were invented. The UI writes the keys the existing gate in
`includes/Compliance.php` already reads:

| UI element        | Setting key            | Gate behavior                                   |
|-------------------|------------------------|-------------------------------------------------|
| Enable toggle     | `verification_required`| `'1'` = gate active; anything else = OFF (default `'0'`) |
| API key field     | `verification_api_key` | gate no-ops (never throws) when enabled-but-empty |
| (advanced, future)| `verification_provider`| `'millionverifier'` (default); unknown → null provider, all verdicts `unknown` |
| (advanced, future)| `verification_risky_action` | `'block'` (default) / `'flag'` for catch-all verdicts |
| (advanced, future)| `verification_strict`  | `'1'` also blocks `unknown` verdicts (default `'0'` = fail open) |
| (advanced, future)| `verification_cache_days`| reuse cached verdicts, default `30` (days) |

All six keys were added to `SETTINGS_ALLOWLIST` in `api/settings.php`.
`verification_api_key` is secret by the existing `_api_key` suffix rule —
redacted to `""` on GET with a `secrets_set` map, and an empty POST value
means "leave the stored secret unchanged", exactly like the other provider keys.

### Migrations + installer

- `migrations/2026-09-24-verification-ui.sql` — `INSERT IGNORE` seeds for all
  six keys with the OFF-by-default values, following the licensing migration
  convention (re-runnable, no DDL). Apply order: date order with the rest of
  `/migrations`.
- `install.php` — seeds the same defaults during install (INSERT IGNORE, soft
  failures recorded, never blocking the install).

## Setting keys

`verification_required` (default `'0'`), `verification_provider` (default
`'millionverifier'`), `verification_api_key` (default `''`, secret),
`verification_risky_action` (default `'block'`), `verification_strict`
(default `'0'`), `verification_cache_days` (default `'30'`).

## Honest-behavior notes (what verification does/doesn't promise)

The settings card copy — and everything this item adds — was written to these
rules; the words "guaranteed", "deliverable" (as a promise), and "inbox"
(placement) appear nowhere:

- **Checks:** syntax, domain mail-server reachability signals, disposable
  (burner) domains, and an SMTP mailbox probe where possible. Each address is
  classified valid / invalid / risky / unknown.
- **Does:** skips `invalid` addresses before they can bounce (protecting the
  buyer's sending accounts); blocks `risky` (catch-all) addresses by default.
- **Doesn't:** it does not guarantee an address is reachable — "valid" verdicts
  can still bounce. It says nothing about inbox placement. `unknown` means the
  provider was uncertain, never a clean bill of health.
- **Costs:** every check consumes the buyer's own MillionVerifier credits (free
  plan covers evaluation; the app pays for nothing and the vendor relationship
  is the buyer's).
- **Funnel:** the Leads funnel shows "0 checked" until verification is enabled.
- **Incomplete setup is safe:** enabling the toggle without a key does nothing —
  the gate logs a notice and skips verification rather than blocking sends or
  erroring.

## Tests

- `tests/compliance/VerificationSettingsUITest.php` (new, DB-free,
  network-free): 22 assertions — settings allowlist + secret-rule contract
  (A1–A5), gate wiring through the UI keys incl. off/empty-key safety (B1–B5),
  test-connection core incl. the real HTTP-200-rejection shape and the
  key-never-in-output invariant (C1–C9). **22/22 pass.**
- `tests/compliance/VerificationGateTest.php` (existing): **37/37 pass.**
- `tests/compliance/run_compliance_tests.php` (existing): extended to apply
  `2026-09-24-verification-ui.sql` twice and assert the OFF defaults; **cannot
  run in this sandbox — no MySQL server installed** (fails at the
  `mysql -u root` bootstrap, before any test logic). Needs a MySQL environment.
- `tests/install/run_install_test.php` (existing): extended with install-seed
  assertions (`verification_required` = `0`, `verification_api_key` row
  present); **same MySQL limitation — not runnable here.**
- `php -l` clean on every touched PHP file; `node --check` clean on
  `assets/js/dashboard.js`.

## Judgment calls

1. **Reused the gate's existing keys instead of the example names**
   (`verification_enabled`, `millionverifier_api_key`). The gate already reads
   `verification_required` / `verification_api_key` with safe defaults; new
   parallel keys would need sync logic or a gate change. Documented above.
2. **Test endpoint uses the credits endpoint, not a probe verification.** A
   probe would spend a credit per click; the credits endpoint validates the key
   for free. The UI says this explicitly ("Spends no verification credits").
3. **Risky-action/strict/cache keys are API-writable but not in the UI.** The
   task asked for toggle + key + test; the advanced keys keep their documented
   safe defaults and can be added to the UI later without a migration.

## Launch-blocker review

- **Nothing in this item is a launch blocker.** The riskiest assumption (key
  rejection arrives as HTTP 200) was verified against the live API and is
  handled + tested.
- **Adjacent gap worth flagging (pre-existing, not introduced here):** the
  gate blocks sends on `invalid` verdicts but there is no UI surface showing a
  *why* to the buyer when a campaign send is skipped — if many leads verify
  invalid, sends will silently not happen and a forum buyer will call it a bug.
  Recommend item 2 (or a follow-up) surface blocked counts in the campaign view.
- Minor: if MillionVerifier ever changes the credits payload shape, the test
  button degrades to "accepted, balance unknown" — by design, not a failure.
