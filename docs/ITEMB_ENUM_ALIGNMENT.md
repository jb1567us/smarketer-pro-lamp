# ITEM B — verification_status ENUM alignment

Date: 2026-09-24. Branch: `commercial/itemB-enum-align`.

## Root cause

`leads.verification_status` had **two contradictory definitions** and **two
independent writer vocabularies**:

| Source | Definition |
|---|---|
| `schema.sql` (fresh installs via `install.php`) | `VARCHAR(20) NOT NULL DEFAULT 'unknown'` |
| `migrations/2026-09-23-compliance.sql` (existing DBs, `add_col_if_missing`) | `VARCHAR(20) NOT NULL DEFAULT 'unknown'` |
| `schema/migration_phase7_saas.sql` (legacy, not in the install path) | `ENUM('unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard') DEFAULT 'unverified'` — **no `'unknown'`** |

Writers:

| Writer | Values written |
|---|---|
| `includes/SimpleHarvester.php` `stageResults()` (harvest insert) | `'unknown'`, or TrustScorer tier when scored |
| `includes/Agents/ExtractionExpert.php` (enrichment update) | TrustScorer tier (fallback `'unverified'`) |
| `includes/Verification/TrustScorer.php` `computeTrustScore()` | `'unverified'`, `'evidence_backed'`, `'dns_confirmed'`, `'cross_source_matched'`, `'gold_standard'` |
| `includes/BulkVerifyJob.php` `persistVerdict()` (bulk verify, item 2) | `'valid'`, `'invalid'`, `'risky'`, `'unknown'` |
| `includes/Compliance.php` `runEmailVerificationGate()` → `$recordStatus` (send gate) | `'valid'`, `'invalid'`, `'risky'`, `'unknown'` |

Readers that key on the verify vocabulary: the gate's cache check
(`$cached !== 'unknown'`), `includes/FunnelStats.php` (`'valid'`/`'invalid'`/
`'risky'`/`'unknown'` buckets, mailable = `'valid'`), `BulkVerifyJob`'s
`WHERE verification_status = 'unknown'` selection queries, `Diagnostics.php`'s
column survey.

**Crash:** on any database where the phase-7 ENUM is in effect,
`BulkVerifyJob::persistVerdict()` writing `'unknown'` (or any gate verdict)
fatals under `STRICT_ALL_TABLES` / `STRICT_TRANS_TABLES` with
`ERROR 1265 (01000): Data truncated for column 'verification_status'`.
`persistVerdict()` has no try/catch, so the whole bulk-verify run dies.
(The gate's `$recordStatus` catches and logs, but the verdict is then silently
lost — also wrong.)

## Decision: widen the ENUM to the union, not change the writers

`'unknown'` is a legitimate, load-bearing state: it is the column default, the
"never verified" marker bulk verify selects on, and the value item 2's
never-valid semantics are built around (`unknown`/`risky` are never upgraded
to `valid`). Changing the writers would mean inventing a new state machine
across the gate, funnel stats, and bulk verify — far riskier than fixing the
schema. The TrustScorer tier values are likewise legitimate (item 4 persists
them at harvest; the dashboard/UI reads them).

So the column is now a single union ENUM everywhere:

```sql
ENUM('unverified','evidence_backed','dns_confirmed','cross_source_matched',
     'gold_standard','unknown','valid','invalid','risky')
NOT NULL DEFAULT 'unknown'
```

**Value order is load-bearing.** The phase-7 values keep their original
relative order as a prefix, with the verify vocabulary appended. MySQL/MariaDB
`ENUM` stores an index number, and a direct `ENUM → ENUM` `ALTER` remaps by
*position* — appending-only means any such alter can never reinterpret stored
values. (The item-B migration additionally stages through `VARCHAR(20)` so it
never relies on this, but the order is cheap insurance for any manual ALTER.)

Default stays `'unknown'`: fresh harvest rows must remain selectable by bulk
verify's `WHERE verification_status = 'unknown'`; `'unverified'` means "was
scored, no evidence found" — a distinct, explicit state.

## Files changed

- `schema.sql` — `verification_status` → union ENUM; added missing
  `INDEX idx_leads_verification (verification_status, trust_score)` (phase-7
  created it, fresh installs never had it).
- `migrations/2026-09-23-compliance.sql` — `add_col_if_missing` DDL for
  `verification_status` → union ENUM.
- `schema/migration_phase7_saas.sql` — ENUM widened to the union (with a
  pointer to the item-B migration); it no longer contradicts the other
  definitions.
- `migrations/2026-09-24-itemb-enum-align.sql` — **new, idempotent** migration
  for existing databases (see below).
- `includes/Agents/ExtractionExpert.php` — sibling fix: wrote
  `leads.status = 'Cold'`, which is not in the `leads.status` ENUM and fatals
  under strict mode. Nothing reads `'Cold'`; writer now uses the existing
  `'Unqualified'` value.
- `includes/SimpleHarvester.php` — comment updated (was referencing the
  phase-7-only ENUM).

## Migration strategy (`2026-09-24-itemb-enum-align.sql`)

Existing databases fall into three shapes; the migration converges all of
them, and is safe to run twice (verified — see Tests):

1. **Column missing** (very old DB) → `ADD COLUMN` with the final ENUM.
2. **VARCHAR(20)** (compliance-path DB) → sanitize, then `MODIFY` to ENUM.
3. **Phase-7 ENUM(5)** → stage through `VARCHAR(20)` (ENUM→VARCHAR converts
   by *string*, never positional), sanitize, then `MODIFY` to the union ENUM.

Steps inside one idempotent stored procedure (no-ops when `COLUMN_TYPE`
already equals the target; guards `leads` table existence):

- sanitize: `UPDATE leads SET verification_status='unknown'` where the value
  `IS NULL` or not in the 9-value vocabulary — repairs legacy junk and `''`
  left by old non-strict truncations;
- the `idx_leads_verification` index is created only if missing.

Fresh installs need nothing: `install.php` imports `schema.sql`, which now
carries the final definition.

## Sibling ENUM audit (writer-vs-schema)

Checked every `ENUM(...)` column definition against every writer found by
grepping the tree:

| Column | ENUM | Writers | Verdict |
|---|---|---|---|
| `leads.verification_status` | union (fixed) | 4 writers, 9 values | **fixed by this item** |
| `leads.status` | `New,Enriched,Contacted,Qualified,Unqualified,Converted,Drafted` | `SimpleHarvester` → `'New'`; `ExtractionExpert` → `'Qualified'`/`'Cold'` | **`'Cold'` fixed → `'Unqualified'`** |
| `leads.consent_status` | `unknown,implied,express` | `SimpleHarvester` → `'unknown'` | clean |
| `campaigns.status` | `VARCHAR(20)` (not ENUM) | `SendMonitor` → `'paused'` | clean (no ENUM constraint) |
| `task_queue.status` | `Pending,In Progress,Completed,Failed,Cancelled` | `BulkVerifyJob::setStatus` etc. | clean |
| `task_queue.task_type` | `...,BulkVerify` | bulk-verify migration aligns | clean |
| `casl_decisions.decision` | `allow,block` | CASL gate | clean |
| `email_logs.status` | `sent,failed,queued,bounced` | send paths | clean |
| `proxies.status` | `Active,Dead` (ProxyManager DDL) | `reportFailure` → `'Dead'` | clean |
| `agent_traces.operational_mode` | `Production,Simulation` | traces | clean |

## Tests

- `tests/enum_alignment/run_enum_alignment_tests.php` (new): strict-mode
  proof. Builds a scratch MySQL database, sets
  `sql_mode='STRICT_ALL_TABLES,...'`, and for each pre-state
  (phase-7 ENUM(5), compliance VARCHAR(20), missing column):
  applies the item-B migration **twice** (idempotency), asserts the final
  `COLUMN_TYPE` equals the union ENUM, then executes the exact statements the
  real writers emit (`UPDATE ... SET verification_status='unknown'`,
  `'gold_standard'`, `'valid'`, `'invalid'`, `'risky'`, plus an INSERT with a
  TrustScorer tier) and asserts no exception. Also asserts pre-existing
  in-vocabulary values survive the conversion by string (e.g. phase-7
  `'gold_standard'` stays `'gold_standard'`, not positionally remapped).
  Requires MySQL: `ENUMALIGN_MYSQL_DSN` (e.g.
  `mysql:host=127.0.0.1;dbname=enumalign_test`), `ENUMALIGN_MYSQL_USER/PASS`.
- Existing suites re-run green (see report): bulk-verify, compliance,
  trustscorer/harvest, install.
- `php -l` clean on all touched PHP files.

## Launch-blocker notes

- The `'Cold'` → `leads.status` write would have been a strict-mode fatal on
  the enrichment path (`ExtractionExpert` when no email is extracted). Fixed
  here; no migration needed since nothing could ever have stored `'Cold'`
  under strict mode (and non-strict DBs stored `''`, which is inert).
- Any buyer database that already ran the old phase-7 file **must** run
  `migrations/2026-09-24-itemb-enum-align.sql` once (phpMyAdmin / cPanel SQL
  runner) before bulk verify or the verification gate is used; the file is
  safe to re-run. Consider adding it to the buyer's upgrade notes alongside
  the other dated migrations.
