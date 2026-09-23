# FIX2 — Verified funnel positioning (honest data quality)

Branch: `commercial/fixes-2`

## What changed

The app used to present a single big raw row-count as the headline metric
("Prospects Discovered", plus "Ready for Outreach" under qualified leads),
which invites reading "N leads in the database" as "N addresses I can mail".
It now shows the verification funnel instead.

### Dashboard (`index.php`, `assets/js/dashboard.js`, `api/stats.php`)

- KPI tile 1 was "Prospects Discovered / Found in Discovery / stat-total_leads".
  It is now a **Lead Funnel** tile whose headline number is `stat-funnel_mailable`
  with the caption "Mailable = verified valid, not suppressed".
- A detail line under it shows the full funnel in plain words:
  `N harvested · M verified valid · P% invalid of C checked · S suppressed`.
- The "Interested Leads" sub-caption was "Ready for Outreach" (a false claim
  for never-verified rows). It now reads "Qualified — not necessarily verified"
  before the first fetch, and the JS-written conversion captions are
  "X% of harvested" instead of "% Efficiency" / "% Outreach" / "% Win Rate".
- `api/stats.php` exposes new flat keys computed by the new
  `includes/FunnelStats.php`:
  `funnel_harvested, funnel_checked, funnel_verified_valid, funnel_invalid,
  funnel_risky, funnel_unknown, funnel_suppressed, funnel_mailable`.
  `total_leads` is still returned for backward compatibility.
- The lead detail drawer now shows a **Verification** line:
  "Not verified" / "Valid — checked (date)" / "Invalid — checked (date)" /
  "Risky — checked (date)". It never claims "verified" for `unknown` rows.

### Harvester UI (`mass_tools_content.php`)

- "Found N high-fidelity prospect matches" → "Found N raw prospects — unverified".
- **Removed a fabricated trust score.** When a search result had no real score,
  the UI displayed `75 + ((title.length + url.length) % 21)` as a
  "🛡️ N% MATCH" badge — fiction derived from string lengths. Unscored results
  now show a plain "Not scored" badge; real scores (when present) are unchanged.
- The "import all" confirm modal now says: "Import all N prospects into your
  CRM? They are unverified — enable email verification before sending."
- The CSV-import toast now says "Successfully imported N leads (unverified)."

### Tests (`tests/compliance/run_compliance_tests.php`)

- New `funnel:` section (9 assertions, delta-based so earlier seeded rows
  don't matter): harvested counts raw rows; valid/invalid/risky/unknown
  partition correctly; checked excludes unknown; suppressed counts
  suppression-list matches; mailable = verified valid AND not suppressed;
  results are deterministic.

## Definitions (used in UI captions)

- **harvested** — every row in `leads`; raw, unverified by definition.
- **verified valid** — `verification_status = 'valid'` (a provider actually
  checked the mailbox at some point).
- **unknown** — never actually verified. Shown as "Not verified", never
  "verified".
- **mailable** — verified valid AND not on the suppression list.
  **Deliberately NOT called "ready to send"**: per-send gates (fresh
  verification verdict, consent/CASL country gate, throttles, DNS preflight)
  still apply at send time.

## Deliberately NOT claimed

- We do **not** claim any "invalid rate" for the database as a whole — the
  invalid percentage is computed only over addresses that were actually
  checked (`invalid / checked`). Unknown rows are excluded, not assumed good.
- We do **not** present `qualified` (sales stage) as mail-ready. Qualification
  is a human/agent judgment; verification is a mailbox check. They are
  separate axes and the UI no longer conflates them.
- "Mailable" does not consider throttles, per-campaign daily caps, DNS
  preflight, or verification-cache freshness — it is a database-state label,
  not a send-time guarantee.
- Verification itself is still **disabled by default** (`verification_required`
  defaults to '0'); until the user enables it and supplies a MillionVerifier
  API key, the funnel will honestly show `0 checked / 0 verified valid`.
- The fabricated match-score removal applies to the mass-tools harvester
  cards only; the real weighted `TrustScorer` (extraction-level, on-site /
  directory / snippet / DNS signals) is untouched.

## Files changed

- `includes/FunnelStats.php` (new)
- `api/stats.php`
- `index.php`
- `assets/js/dashboard.js`
- `mass_tools_content.php`
- `tests/compliance/run_compliance_tests.php`
- `docs/FIX2_VERIFIED_FUNNEL.md` (this file)
