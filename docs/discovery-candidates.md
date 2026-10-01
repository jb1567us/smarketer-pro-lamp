# Deterministic candidate construction for ICP determination (D-P2)

**Status:** implemented 2026-10-01 on `repair/phase0-pipeline`, local-only.
**Code:** `includes/Discovery/BuyerArchetypes.php`, `includes/Discovery/CandidateBuilder.php`.
**Tests:** `tests/discovery/` (40 unit + 18 Relay-fixture checks, all green).

## What this is

The LAMP port of PI's PERSONA_KG move: candidate segments for ICP
determination are built by a **deterministic template over
(archetype × buyer facts)** — never by an LLM pre-draft. The JEV filter
(Stage A) and `ScoreLeadFitAction` (Stage B) then **judge** these
candidates; they never invent them. There is no model-generated pre-draft
to remove — construction is deterministic from day one, so phantoms have
no back door.

## The table

`BuyerArchetypes.php` holds 25 asserted buyer-persona archetypes with
stable ids (label, definition, pains, buying_context, scope_default,
strength, status) — the LAMP-relevant subset of the PI persona KG's 49,
i.e. the buying roles a B2B outbound buyer plausibly sells into
(agencies, SaaS founders, consultants, trades, legal, finance,
manufacturers, ...). Rows are copied verbatim from the PI seed spec
(`~/workspace/jev-icp-determination/PERSONA_KG_SPEC.md` §4), which is the
owner-reviewed source of truth. Adding a row is a taxonomy decision;
deprecation never reuses an id.

## The template (exact)

S1 label:definition · S2 pains · S3 buying context (all KG persona
fields) · S4 "They buy {product_summary}{price}{txn}{geo}{size}{titles}."
(buyer facts, verbatim, punctuation-level joins only) · S4b buyer line as
verbatim quote · S5 owner-hypothesis audience as verbatim quote.
Absent fields omit their clause — never filler. 800-char cap with an
explicit ` […]` marker. Every sentence is single-sourced to a KG field
or a buyer-fact field (never-invent-evidence binds the template).

## Eligibility & ordering

- `sells_to_businesses=false` → zero candidates + `zero_reason`
  (honest absence; this table is B2B-only).
- Scope-match: `ladder(archetype.scope_default) >= ladder(buyer
  geography_scale)` on the shared scope ladder (reused from
  `MentionValidity::SCOPES` — no second taxonomy). Missing/unknown buyer
  scale **widens** (no scope filter) per the discovery spec's "never
  blocks on missing intake" rule.
- Expansion order: primaries by `persona_id` ASC, then secondaries by id
  ASC; truncate to `MAX_CANDIDATES = 3`. Fewer eligible → fewer
  candidates; zero → zero candidates. No padding.

## Deliberate non-behavior (assumptions for owner veto)

1. **Buyer-stated exclusions (anti-personas) are carried, not applied.**
   They ride the buyer-facts input into the pipeline; exclusion
   enforcement stays in the deterministic hard-veto path of
   `ScoreLeadFitAction`/Stage A, where an owner-hypothesis mismatch is
   judged as poor fit. Filtering them here would be the KG judging —
   the PI spec's code-vs-JEV line.
2. **M = 3**, `segment_id = 'seg_' + persona_id`, template version 1.0
   pinned (a template change is a taxonomy release, never a silent edit).
3. **No industry classification of the buyer.** The LAMP table is
   B2B-seller-scoped; eligibility is scope + B2B only. A seller-side
   industry taxonomy is a later extension, not this port.
4. **No persistence.** Candidates are constructed per run from the table;
   nothing is written to the DB. Stage B fixtures (committed snapshots)
   and any persistence live in the discovery loop, not here.

## Integration seam

The discovery path (spec §3: intake → hypotheses → Stage A JEV filter →
Stage B fixtures) consumes `CandidateBuilder::construct($buyerFacts)` as
its **only** candidate source. The result shape is
`['candidates' => [...], 'meta' => [...]]` — JSON-stable for logging and
decision records. NEVER_JEV boundary 5 (retrieval/scope/thresholds =
deterministic code) binds this class; boundary 6 is untouched — the
determination stays automatic, human review stays on leads and sends.

## Verification

- Determinism: same facts → byte-identical JSON, run twice; rebuilt
  facts array → identical bytes.
- No-model audit: source grep for provider/HTTP/env tokens
  (`JevProvider`, `curl_`, `file_get_contents(`, `API_KEY`, ...).
- Relay day-zero fixture: B2B SaaS AI sales-prospecting tool,
  $249/user/mo ($50–500/mo band), 20–500-emp B2B, US/UK/Canada,
  founders/sales leadership → 3 sensible candidates
  (`seg_agency_ops_manager`, `seg_agency_principal`,
  `seg_bootstrapped_saas_founder`), all buyer facts interpolated
  verbatim, byte-identical across runs.
