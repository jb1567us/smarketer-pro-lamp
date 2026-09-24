# Item 4 — Real TrustScorer in Harvest Results

Branch: `commercial/item4-trustscorer` (worktree `~/workspace/smarketer-followup/worktrees/item4`)

Fix2 removed a **fabricated** harvester trust score (`75 + ((title.length + url.length) % 21)`
displayed as "N% MATCH"). Harvest cards then honestly showed "Not scored". This item wires
the **real** `App\Verification\TrustScorer` into harvest results, so cards show genuine
scores where evidence exists — and keep showing "Not scored" where it doesn't.

## What TrustScorer actually measures

`includes/Verification/TrustScorer.php` — `TrustScorer::computeTrustScore()` is a
**pure, deterministic function** (no network calls, no LLM, microseconds per call). It is
*not* fabricated: every point comes from an explicit evidence flag passed by the caller.

Weighted evidence model ("trust weights per specifications"):

| Input             | Weight | Meaning at harvest time                                  |
|-------------------|--------|----------------------------------------------------------|
| `hasLevel1`       | 50     | Contact found via on-site crawler extraction             |
| `hasLevel2`       | 30     | Contact confirmed in directory baseline (G-Maps, YP)     |
| `hasLevel3`       | 10     | Contact mentioned in general search-engine snippets      |
| `dnsReceptive`    | 10     | Target domain verified receptive to incoming mail (MX/A) |
| `crossReferenceMatch` | —  | Level 1 + Level 2 agree → `gold_standard` status         |

Output: `trust_score` (0–100, capped), `trust_tier`, `is_gold_standard`, `breakdown`,
`verification_status` (`unverified` / `evidence_backed` / `dns_confirmed` /
`cross_source_matched` / `gold_standard`).

### Honest evidence mapping at harvest time

`SimpleHarvester::scoreHarvestResults()` maps only evidence that actually exists when a
harvest runs:

- `hasLevel1 = false` — **never claimed**: no on-site page extraction happens during harvest.
- `hasLevel2 = false` — **never claimed**: no directory-baseline lookup happens during harvest.
- `hasLevel3 = true` iff a contact email is found in the item's own snippet text
  (title + content + snippet). The item *is* a search-engine snippet, so this is exactly
  what Level 3 means.
- `dnsReceptive` = `DNSChecker::checkReceptivity()` on the snippet email's domain, falling
  back to the prospect website's domain when the snippet has no email.
- `crossReferenceMatch = false` — single source at harvest time.

Consequence: **harvest-time scores top out at 20** (10 snippet + 10 DNS). A card showing
"🛡️ 20% MATCH" means "contact appeared in a search snippet and its domain accepts mail" —
weak evidence, honestly labeled. Deeper qualification (the Enrich flow /
`ExtractionExpert`, which does on-site extraction + DNS) can raise a lead to 60–100 later.
The weights are spec-defined, not statistically calibrated — treat the score as a
relative evidence ranking, not a probability.

### Verdict on the scorer

The scorer is **not junk**: deterministic, documented, and its inputs are real evidence
flags (contrast with Fix2's string-length formula). Its limitation is scope, not honesty —
at harvest time only Level-3 + DNS evidence is available, so scores are low. That is
handled by honest labeling, not by inflating numbers.

## Where it runs: sync, not async — and why

Scoring runs **synchronously at harvest time** in both paths:

1. `api/mass_tools.php` → `action=harvest` (sync mode): `scoreHarvestResults()` is called
   on the provider results before `stageResults()` and before the JSON response that the
   cards render.
2. `cron_worker.php` → `processHarvestJob()` (queued/background mode): same call, so
   polled job results carry scores too. Sync/async parity is intentional.

**Why not the queue/cron worker:** the existing job queue exists for *slow* work (search
API calls, page extraction). `computeTrustScore()` is a pure function — microseconds.
Queueing it would add persistence, re-render, and polling complexity for zero compute
benefit. The only network I/O in scoring is the DNS receptivity probe, handled by:

- **Per-batch domain cache** — one probe per unique domain per harvest call
  (50 results on 50 domains ≈ 50 fast MX lookups, typically well under 2 s total,
  versus the seconds the search-API calls already take).
- **Fail-soft** — any DNS exception degrades to `dnsReceptive = false`; a broken
  resolver can never fail a harvest.
- **Kill-switch** — settings key `trustscorer_harvest_dns` (default ON). Set to `'0'`
  on hosts with pathological DNS to skip the probe entirely.

## Honest labeling behavior

`scoreHarvestResults()` attaches `score` (0..1 float, matching the card renderer's
`Math.round(item.score * 100)`), `trust_score` (0–100 int), `trust_tier`,
`trust_breakdown`, `verification_status` **only when the computed score is > 0**
(i.e. at least one evidence input was true).

- Card shows `🛡️ N% MATCH` **iff** that number was genuinely computed from real evidence.
- Otherwise the item carries **no score key at all**, and the renderer shows the honest
  **"Not scored"** badge (unchanged from Fix2).
- A computed zero ("no evidence") is *not* displayed as "0% MATCH" — it stays
  "Not scored". No defaults, no interpolation, no fabrication, ever.

## Persistence

`SimpleHarvester::stageResults()` persists the genuine result when the harvest item was
scored: `trust_score`, `trust_breakdown` (JSON), `verification_status` (TrustScorer
statuses are valid for both the `VARCHAR(20)` and the phase-7 ENUM definitions).

- **Migration:** `migrations/2026-09-24-item4-trustscorer.sql` (procedure-guarded,
  idempotent; also seeds `trustscorer_harvest_dns = 1`). Run on existing databases.
- **Fresh installs:** `schema.sql` `leads` table now includes `trust_score` /
  `trust_breakdown`.
- **Defensive:** `stageResults()` probes for the columns once per call
  (`leadsHasTrustColumns()`). Databases without the migration still harvest fine —
  trust persistence is skipped, never fatal. Unscored items keep the legacy
  `verification_status = 'unknown'` write, byte-for-byte.

## Files changed

- `includes/SimpleHarvester.php` — `scoreHarvestResults()`, `harvestEvidence()`,
  `isHarvestDnsEnabled()`, `leadsHasTrustColumns()`; `stageResults()` persists trust data.
- `api/mass_tools.php` — sync harvest path scores before stage + response.
- `cron_worker.php` — `processHarvestJob()` scores before stage + job result.
- `schema.sql` — `trust_score` / `trust_breakdown` on fresh-install `leads`.
- `migrations/2026-09-24-item4-trustscorer.sql` — idempotent migration + DNS kill-switch seed.
- `tests/harvest/run_trustscorer_harvest_tests.php` — 51 assertions, all passing.
- `docs/ITEM4_TRUSTSCORER.md` — this file.

No JS changes were needed: the card renderer (`mass_tools_content.php`,
`renderHarvestedLeads`) already shows `item.score` when present and "Not scored" when
absent — the Fix2 honesty gate is preserved and now fed with real numbers.

## Tests

`php tests/harvest/run_trustscorer_harvest_tests.php` — 51/51 PASS. Covers: TrustScorer
known vectors (0/10/20/60/100, tiers, statuses, gold conditions), score attachment only
on genuine computation, exact equality with direct `TrustScorer::computeTrustScore()`
calls, per-domain DNS caching, DNS-exception fail-soft, the DNS kill-switch, SQLite
persistence of trust columns, duplicate staging, and legacy DBs without trust columns.

`php -l` clean on all touched PHP files. `tests/compliance/run_compliance_tests.php`
requires a local MariaDB (`mysql` CLI), which is unavailable in this sandbox — it was
not runnable here; no compliance-surface code was changed (harvest staging writes only
the pre-existing columns plus the new trust columns).

## Launch-blocker notes

- **Low harvest-time scores are expected** (max 20). If buyers compare against the old
  fabricated 75–95%, explain the scale — the doc above is the script. Do not "fix" this
  by inflating weights.
- **Run the migration** (`migrations/2026-09-24-item4-trustscorer.sql`) on existing
  databases before launch, or trust persistence silently skips (harvest still works).
- Pre-existing, out of scope but adjacent: `stageResults()` still writes
  `verification_status = 'unknown'`, which is invalid under the phase-7 ENUM definition
  (only matters if `schema/migration_phase7_saas.sql` was applied; MySQL strict mode
  would reject it). Flagged, not changed.
- `includes/Database.php` untouched. `jev_enabled` default untouched. No pushes, no deploys.
