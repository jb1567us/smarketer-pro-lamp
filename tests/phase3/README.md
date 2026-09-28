# Phase 3 JEV integration tests — Reply classification + routing

Tests the five new Phase 3 files on local branch `repair/phase0-pipeline`
(all untracked, NOT committed):

- `includes/Actions/ClassifyReplyAction.php` — JEV-native reply classifier
  (public `classify()`, `heuristicFallback()`)
- `includes/ReplyRouter.php` — `App\ReplyRouter`, instance `route()`
- `includes/ReplyIntake.php` — shared wiring for both endpoints
- `reply_lab.php` — manual two-step classify → route page
- `api/ingest_reply.php` — authenticated JSON ingestion endpoint

## Run

On the cPanel host (PHP 8.1):

```sh
php tests/phase3/run_phase3_tests.php
```

or run individual files:

```sh
php tests/phase3/test_classify_heuristic.php
php tests/phase3/test_router.php
php tests/phase3/test_endpoints_static.php
```

The suite needs **no network**. `test_classify_heuristic.php` needs no DB.
`test_router.php` needs no DB except the two suppression cases
(`unsubscribe`, `hostile`), which call `Compliance::suppress()` → live DB;
without a DB they SKIP rather than FAIL. `test_classify_heuristic.php`'s
`classify()` end-to-end check SKIPs unless JEV mode is actually `off` in the
DB settings (the heuristic path itself IS the JEV-off code path).

## Style

Follows `tests/jev/`: each test file is a standalone `php` CLI script with
a `check()` helper, a per-file summary, and a nonzero exit on failure.

## Coverage vs. the audit's check categories

| Category | File | Status on this host |
|---|---|---|
| (a) syntax/lint | `run_phase3_tests.php` (php -l) | **STATIC-ONLY** — no PHP runtime here; runnable on cPanel |
| (b) heuristic fixtures per intent | `test_classify_heuristic.php` + `fixtures.php` | **STATIC-ONLY** (same reason) |
| (c) off-mode marking | `test_classify_heuristic.php` | **STATIC-ONLY** |
| (d) routing outcomes per intent | `test_router.php` + `fake_pdo.php` | **STATIC-ONLY** |
| (e) auth on new endpoints | `test_endpoints_static.php` | **STATIC-ONLY** |
| (f) verdict-shape reconciliation | all (esp. `test_endpoints_static.php`) | **STATIC-ONLY** |

Nothing in this directory touches the real database, sends email, or writes
outside `logs/ingest_replies.jsonl` (never hit by the tests themselves).

## Findings from the static review (2026-09-28)

Verdict-shape reconciliation against the ACTUAL
`ClassifyReplyAction::classify()` return shape
(`intent, needs_human, urgency:int 1-10, confidence, source, latency_ms, note?`):

1. **FIXED (2-line normalization):** `ReplyIntake::normalizeVerdict()` dropped
   the heuristic `note` key even though it is part of the classifier's
   documented shape. Now preserved (`null` when absent). Additive only.
2. **Reported, not changed:** the `api/ingest_reply.php` docblock response
   example predates the `note` key and does not show it. Real JSON now
   carries `"note"` — update the example when convenient.
3. **Reported, not changed (design decision, needs owner eyes):** the
   router's LOW_CONFIDENCE_THRESHOLD (0.55) guardrail sends EVERY heuristic
   verdict (confidence 0.0) to human review — including unsubscribe and
   hostile. So in JEV-off mode the auto-suppression paths never fire;
   off-mode is effectively human-review-only. This is documented fail-closed
   behavior in the router, but it silently disables compliance
   auto-suppression in off mode. The test suite asserts this behavior as
   `human_review` (deliberate), not as a failure.
4. **Reported, cosmetic:** `normalizeVerdict()` defaults `urgency` to the
   string `'normal'` when missing and `intent` to `'unknown'` when missing;
   the classifier always supplies both, so this only affects malformed
   input (router then human-reviews anyway).
5. Python port fidelity: keyword sets, check order, and per-branch
   urgency/needs_human values match `_heuristic_fallback` exactly; the two
   extra intents (`not_now`, `hostile`) are checked before `objection`,
   matching the plan. One deliberate normalization difference: the live JEV
   path maps the TypeSafe score position to urgency via `round(score)+1`
   (clamped 1-10), where the Python port used `round(score)` directly —
   consistent with the PHP TypeSafe 0-based spectrum contract verified in
   Phase 0/1.
