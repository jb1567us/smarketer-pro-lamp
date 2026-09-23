# Soft Phone-Home License Lock

Smarketer Pro ships with an optional, **soft** license lock: a tiny standalone
license server plus client code in the app. It is designed around one rule —
**the app never bricks**. The lock keeps honest buyers honest; it does not
pretend to stop a determined pirate (nothing in a PHP source-distributed
product can).

## Architecture

```
Buyer installs app                     Seller's license server
┌──────────────────────┐               ┌──────────────────────────┐
│ install.php          │  register.php │ license-server/api/      │
│  └─ optional key ────┼──────────────▶│  validate / register /   │
│ Settings → License   │  (HTTPS, ≤10s)│  release.php             │
│  ├─ Activate         │               │  + MySQL: licenses,      │
│  ├─ Re-validate      │               │    license_domains,       │
│  └─ Release domain ◄─┼───────────────│    rate_limits           │
│ cron (daily check)   │               └──────────────────────────┘
│ EmailSender choke ───┘
│  point: revoked ⇒ pause sending only
└──────────────────────┘
```

**Half A — license server** (`license-server/`): standalone PHP + MySQL,
zero dependencies on the main app, built for plain cPanel shared hosting
(no root, no daemons, no cron). See `license-server/README.md` for
deployment, key issuance (`admin/make_key.php`), and the API reference.
It currently lives on the **same shared host** as everything else.

**Half B — client** (`includes/Licensing.php`, plus hooks):

- `install.php`: optional license-key + server-URL fields; registration is
  attempted after the schema import and is *soft* — failure never blocks
  install.
- `api/settings.php`: `license_server_url` / `license_key` are allowlisted;
  the key is treated as a secret (redacted on read).
- `api/license.php`: admin-authenticated status + Activate / Re-validate /
  Release-this-domain actions.
- Settings UI (`index.php` + `assets/js/dashboard.js`): a License section
  showing status, slots used, last check, and grace state.
- `cron/process_queue.php`: `Licensing::dailyCheck()` re-validates at most
  once per 24h (every 6h for revoked keys, so un-revoking restores sending
  promptly). Never throws, never blocks the queue.
- `Compliance::requireCompliantSend()`: the revoked-only gate. Because
  `EmailSender::send()` funnels through here, revocation pauses *all*
  outbound mail paths with one hook.

## The license-server URL is fully configurable

The client reads the server location from the **`license_server_url`
setting** (Settings → License), falling back to the
`Licensing::DEFAULT_LICENSE_SERVER_URL` constant that distributors bake in
before packaging a zip. **Moving the license server later is just a settings
change** — no code changes, no reinstall, no buyer action beyond the URL.
Keep the URL stable as long as practical; installs on old versions keep
phoning the old address until their setting is updated.

## Behavior matrix (the whole point)

| Situation | App behavior | Sending |
|---|---|---|
| No server URL configured | "Unlicensed" | ✅ works |
| No key entered | "Unlicensed" | ✅ works |
| Key valid | "Licensed" | ✅ works |
| Key mistyped / unknown | Notice ("check for typos") | ✅ works |
| Domain not registered | Notice + prompt to Activate | ✅ works |
| Domain slots exhausted (409) | Notice + self-service release hint | ✅ works |
| Server unreachable | Notice, auto-retry; grace = last good check + 14 days | ✅ works |
| Grace expired (14d, still unreachable) | Escalated notice | ✅ works (still!) |
| **Key revoked** (seller-side) | Persistent dashboard notice | ⏸️ **paused** |
| Key un-revoked | Next check (≤6h via cron, or manual) | ✅ resumes automatically |

Only `revoked` changes behavior, and only for sending. Dashboard, leads,
campaigns, settings, and the API keep working. There is deliberately no
state in which the app disables itself.

## Verdict storage

The cached verdict is JSON, **HMAC-signed with the per-install secret**
(`Compliance::appSecret()`, same primitive as unsubscribe tokens), stored in
the `license_verdict` setting. A tampered blob fails verification and is
treated as "unknown → revalidate", which fails open (sending allowed).

The verdict records `key_fp` (sha256 of the key, not the key) so the UI and
logs can show *which* key a verdict belongs to without ever persisting the
key next to it — although the key itself lives in the `license_key` setting
like any other API secret.

## Domain normalization (client and server implement identical rules)

Lowercase → strip scheme/userinfo/path/query/fragment/port → strip trailing
dot → strip one leading `www.`. So `https://www.example.com/shop/?x=1` and
`example.com` claim the **same** slot. Documented in both codebases and
covered by parity tests (`tests/licensing/run_licensing_tests.php`).

## Key lifecycle (seller)

1. `php license-server/admin/make_key.php --label="BHW order #123" --email="buyer@x.com" --max-domains=1`
   → prints `SMP-XXXX-XXXX-XXXX` **once**. Send it to the buyer.
2. Buyer activates at install or in Settings → License (domain slot claimed).
3. Buyer moves hosts → they use *Release this domain* themselves. No ticket.
4. Refund/chargeback → `UPDATE licenses SET status='revoked' WHERE …`.
   Buyer's sending pauses on next check; everything else works.

## Threat model — what it stops vs what it doesn't

**Stops:**

- Casual key sharing: a posted key stops working for new domains once its
  slots are full (default: 1).
- Typos burning slots: `validate` never auto-registers; registration is
  explicit and idempotent.
- Database theft: no recoverable plaintext keys (fingerprint + `password_hash`
  only).
- Key-guessing: 60-bit keys, vague `unknown_key` responses, per-IP rate
  limiting on the API.

**Does not stop:**

- Anyone who edits the app's PHP to remove the client check (an afternoon's
  work — inherent to source-distributed PHP).
- A buyer who never enters a key (the app is fully functional unlicensed,
  by design).

The economic mitigations are: pirates get no updates, no support, and no
community — while the lock itself stays invisible to paying buyers.

## Failure modes (all safe by construction)

- License server down / buyer host can't reach it → notice + grace, app works.
- License server misconfigured → API returns `server_misconfigured`; client
  treats any non-JSON/non-200 as unreachable → grace.
- Rate limiter DB error → limiter fails open (never takes the API down).
- Client DB unavailable → verdict read fails open → sending allowed.
- Clock skew → grace math uses server/client local time only; worst case is
  a slightly longer or shorter grace, never a lockout.

## Tests

`php tests/licensing/run_licensing_tests.php` — 71 assertions:

- Pure unit (no DB/network): normalization corpus, client/server parity,
  verdict sign/verify + tamper/forgery/wrong-secret, the full behavior
  matrix (`decideSending`), grace math.
- DB-backed with stubbed HTTP: validate/register/release flows, revoked ⇒
  send-gate refusal, un-revoke ⇒ restore, offline register stores key for
  later, daily-check throttling, release unreachable messaging, disabled
  state, tampered-cache fail-open.

Regression suites (all passing): compliance 72/72, installer 51/51, JEV,
queue stress.
