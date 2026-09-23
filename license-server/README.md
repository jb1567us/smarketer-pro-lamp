# Smarketer Pro — License Server

A tiny standalone PHP + MySQL app that issues and validates license keys for
the soft phone-home license lock. It has **zero dependencies** on the main
Smarketer Pro codebase and is designed to run on **plain cPanel shared
hosting** — no root access, no background processes, no Composer, no cron.

## Layout

```
license-server/
  schema.sql          — MySQL schema (licenses, license_domains, rate_limits)
  config.example.php  — copy to config.php and fill in (config.php is git-ignored)
  lib/common.php      — shared helpers: JSON I/O, PDO, key gen, domain
                        normalization, rate limiting
  api/validate.php    — POST {key, domain} → {valid, reason, ...}
  api/register.php    — POST {key, domain} → claims a domain slot (409 when full)
  api/release.php     — POST {key, domain} → buyer self-service slot release
  admin/make_key.php  — CLI key issuer (prints the key once, stores only hashes)
```

## Key format

`SMP-XXXX-XXXX-XXXX` — 12 characters from an unambiguous 32-character
alphabet (no `0`/`O`, `1`/`I`/`L`), 60 bits of entropy, generated with
`random_bytes()`. The **plaintext key is shown once at issuance and never
stored**. The database holds only:

- `key_fp` — `sha256` of the normalized key (deterministic lookup index)
- `key_hash` — `password_hash()` of the normalized key (verification)

## Domain normalization (documented rule — the client implements it identically)

Lowercase → strip scheme/userinfo/path/query/port → strip trailing dot →
strip one leading `www.`. So `https://www.example.com/shop/?x=1`,
`www.example.com:443`, and `example.com` all resolve to the single slot
`example.com`.

## Deploying on cPanel shared hosting (same host is fine)

The license server currently lives on the **same shared host** as everything
else. These steps assume cPanel; nothing here needs SSH or root.

1. **Create the MySQL database** — cPanel → *MySQL Databases*:
   - Create database, e.g. `youruser_licenses`
   - Create a MySQL user, e.g. `youruser_licuser`, with a strong password
   - Add the user to the database with ALL PRIVILEGES
2. **Import the schema** — cPanel → *phpMyAdmin* → select the new database →
   *Import* → upload `license-server/schema.sql` → Go.
3. **Upload the files** — cPanel → *File Manager* (or FTP):
   - Option A (subdomain, recommended): create subdomain `license.yourdomain.com`
     pointing at `public_html/license/`, upload the contents of
     `license-server/` there.
   - Option B (subdirectory): upload to `public_html/license/` and serve from
     `https://yourdomain.com/license/`.
   - Either way, the API base URL becomes e.g.
     `https://license.yourdomain.com/api` — this is the value buyers put in
     the app's **License server URL** setting.
4. **Create `config.php`** — on the server, copy `config.example.php` to
   `config.php` in the same directory and fill in the DB credentials from
   step 1. (`config.php` is git-ignored; it must never be committed.)
   On cPanel shared hosting the DB host is almost always `localhost`.
5. **Smoke-test** — visit `https://license.yourdomain.com/api/validate.php`
   in a browser. You should get a JSON `{"valid":false,"reason":"missing_fields"}`
   (not a PHP error page). If you see a PHP error, check the DB credentials
   in `config.php`.
6. **Deny web access to non-API files (optional hardening)** — place an
   `.htaccess` in the license directory root with:
   ```
   <FilesMatch "^(config\.php|config\.example\.php|\.gitignore)$">
       Require all denied
   </FilesMatch>
   ```
   The `api/` and `lib/` scripts are meant to be web-reachable; `admin/`
   is CLI-only (it does nothing harmful over the web, but there's no reason
   to expose it — the `.htaccess` above plus your own discretion covers it).

No cron jobs, no daemons, no outbound network access needed. The server is
pure request/response.

## Issuing keys

From any machine with PHP and MySQL access to the license database
(your laptop works):

```bash
cd license-server
php admin/make_key.php --label="BHW order #1234" --email="buyer@example.com" --max-domains=1
```

Output prints the plaintext key **once** — send it to the buyer, then forget
it. It cannot be recovered from the database afterwards.

- `--max-domains` (default `1`): how many domains/installs one key covers.
  Use `3` for an "agency" tier, etc.
- Revoking a key (refund/chargeback): in phpMyAdmin,
  `UPDATE licenses SET status='revoked' WHERE label='...'`. The buyer's app
  pauses *sending only* on its next daily check; everything else keeps working.

## Moving the server later

The client app reads the server location from its **License server URL**
setting — moving the license server is just changing that setting (per
install, or baked into your next distribution zip). No client code changes,
no reinstall. Keep this URL stable as long as practical; buyers on old
versions keep phoning the old address.

## API reference

All endpoints accept and return JSON (`Content-Type: application/json`).
`validate` is rate-limited to 60 req/min/IP; `register`/`release` to
30 req/min/IP (backed by the `rate_limits` table; the limiter fails open —
it never takes the API down).

### POST /api/validate.php

`{"key": "SMP-XXXX-XXXX-XXXX", "domain": "example.com"}`

| valid | reason | meaning |
|---|---|---|
| true | `ok` | key active, domain registered |
| false | `unknown_key` | no such key (also covers wrong key — deliberately vague) |
| false | `revoked` | key revoked by seller |
| false | `domain_not_registered` | key OK but this domain never claimed a slot |
| false | `missing_fields` | HTTP 400 |
| false | `rate_limited` | HTTP 429 |

Success also returns `domains` (registered list) and `max_domains`.

### POST /api/register.php

Same body. Claims a domain slot. Idempotent (`already_registered` when the
domain is already on the key). `409` + `domain_slots_exhausted` when the key
is at `max_domains` — the buyer frees a slot themselves via `release`
(see buyer FAQ below) instead of emailing you.

### POST /api/release.php

Same body. Deletes the domain's slot. Idempotent. A revoked key can still
release (the buyer may be migrating away); revocation only blocks
validate/register.

## Buyer FAQ (for your sales thread / docs)

- **Do I need the license key to use the app?** No. The app works fully
  without one; the settings page just shows "Unlicensed".
- **What happens if your license server is down?** Nothing. The app keeps
  working — it shows a notice and retries later. It never disables features
  because it can't reach the server.
- **I mistyped my key.** Also nothing — a wrong key only shows a notice.
  Only an explicitly *revoked* key (refunds/chargebacks) pauses sending,
  and everything else keeps working.
- **I moved hosts / changed domain.** Settings → License → *Release this
  domain*, then re-register on the new domain. No need to contact support.
- **I used up my domain slots.** Release an old one as above, or buy a key
  with more slots.

## Threat model

Stops: casual key sharing (one key posted publicly stops working for new
domains once slots are full), typo'd installs burning slots (validate never
auto-registers), database theft (no recoverable plaintext keys).

Does not stop: anyone who edits the app's PHP to remove the client check,
or a buyer who never enters a key (the app is fully functional unlicensed
by design — the lock is *soft*). See `docs/LICENSING.md` for the full
client-side behavior matrix.
