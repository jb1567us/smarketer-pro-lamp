# Channel Target List — P5 (channel_reach target list for LAMP)

**Status:** implemented 2026-10-01 on `repair/phase0-pipeline` (local-only, zero
production behavior change). **Code:** `includes/Channels/ChannelTargetList.php`
+ `includes/Channels/ChannelTargetListExtension.php`. **Tests:**
`tests/channels/`.

## What it is

LAMP's `channel_reach` dimension (or its LAMP-original equivalent — the canonical
dim set is an open owner question, integration-map §4d.8) scores against an
**asserted target list**. This is that list: a deterministic, versioned,
DB-free industry × outbound-channel seed for B2B sellers. It is also the
**only source of channel recommendations rendered into copy** (the render-time
tripwire is `ChannelTargetList::mentionCheck()`).

Ported from the PI Channel KG design
(`~/workspace/jev-icp-determination/CHANNEL_KG_SPEC.md`) with the LAMP product
differences baked in: **flag, never drop** on predicate failures (PI drops
silently; LAMP keeps human review on sends).

## The one load-bearing distinction

The seed asserts **which channels exist as routes to an industry's buyers at a
given scope — not which convert best.** Strength bands (`high|medium|low`) are
usage-prevalence tiers (primary route / common secondary / situational), never
measured conversion rates. Do not read the table as a performance ranking.
[ASSUMPTION — owner can veto the strength semantics.]

## Contents

- **13 outbound channels** (closed enum, stable slugs): `cold_email`,
  `email_nurture`, `linkedin_outreach`, `linkedin_ads`, `cold_call`,
  `direct_mail`, `paid_search`, `social_organic`, `webinars`, `events`,
  `referrals_wom`, `partnerships_affiliates`, `sms`. Channel key changes are
  schema-migration-grade (owner sign-off + version bump); the alias table
  (`CHANNEL_ALIASES`) may grow freely.
- **9 LAMP-relevant industries** with asserted edges, each edge carrying a
  required effectiveness `basis` (closed vocab — an edge without a *why* is
  refused by `selfCheck()`).
- **Shared scope ladder** (single definition, in `App\Evidence\MentionValidity`):
  `local < state < regional < national < online-global`. Edge scope facets are
  optional; absent = inherits the industry's `scope_default`. Coverage rule:
  edge level >= target level (a national channel serves a local query; a
  local-only channel never serves a national query).

## API (all pure, DB-free, deterministic)

- `ChannelTargetList::channelsFor($industryKey, $targetScope, $minStrength = 0.4)`
  → ordered list (strength_num DESC, channel_key ASC), pinned to
  `TABLE_VERSION` (+ `EXTENSION_VERSION`). Unknown industry → empty list +
  explicit miss (never a guess). Unknown scope → invalid input (never a pass).
- `ChannelTargetList::targetBlock(...)` — the seam for ScoreLeadFitAction /
  channel_reach: injects `channels` (labels) + `channel_target`
  (key/label/basis) as the deterministic DP1-style input state. **v1: unused
  by production** (JEV off/shadow; dim set undecided) — the seam is wired to
  nothing. Zero behavior change is the point.
- `ChannelTargetList::mentionCheck($industryKey, $targetScope, $mentionText)`
  — deterministic render predicate: resolves the mention through the alias
  table, requires the edge in the scope/strength-filtered target list.
  Failing mentions return `suggested_routing: human_review` with
  `review_status: 'Needs Review'` — **flag, never silently drop**.
- `ChannelTargetList::selfCheck()` — seed integrity audit (refused edges,
  ladder/closed-vocab violations); run in CI.
- `ChannelTargetList::resolveChannel($text)` — free text → closed key, null
  when unmappable (fail-closed).

## Extension mechanism (not ad-hoc additions)

`ChannelTargetListExtension::EXTENSION_ROWS` + `EXTENSION_VERSION`. Adding an
industry = a committed, versioned row (ships empty in v1 — "not asserted yet"
is a miss, not a guess). Extension rows may ADD industries/channels; they
never override core rows (collisions resolve to core, reported in
`extension_conflicts`). No runtime registration API exists on purpose.

## Never-Jev boundary

Candidate retrieval, scope filtering, ordering keys, the strength floor, the
mention predicate, and recency/staleness are deterministic code
(`docs/NEVER_JEV.md` §5). A future JEV `channel_reach` score judges fit
**against** this list; it never decides what is retrievable or in scope.
No prose generation, no pricing/claims, no send decision anywhere in this
workstream (boundaries §1–§3, §6 intact).

## Deliberately v2 (not built)

- Staleness lifecycle (PI's 12mo degrade / 24mo expire) — owner decision.
- JEV channel ranking (built only on measured ordering pain).
- DB-backed or uploaded extensions.
- `economic_fit`-style evidence pointers per edge (the seed asserts usage
  routes; evidence citations are the evidence-KG track, not this table).
