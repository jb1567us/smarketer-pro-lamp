# JEV Decision Tier (PHP) — smarketer-pro-lamp

Jev (TypeSafe System One) is a **decision-only** model: it takes program state
plus typed questions and returns typed, calibrated decisions
(`noul` probability, `choice`, `score`) in a single parallel pass. It is **not**
a generative LLM and cannot write prose — integrations that need a `reason`
string synthesize one from the decision (see `QualifyLeadAction`).

PHP port of `smarketer-pro`'s `src/llm/decision_tier.py` /
`src/llm/jev.py`. HTTP goes through cURL, matching the rest of this codebase.

## Files

| File | Role |
|---|---|
| `includes/Jev/JevProvider.php` | Thin System One HTTP client (POST `https://api.typesafe.ai/v1/systemone`, Bearer auth, retries on 429/529 with 2s/4s/8s backoff, 120k-char state budget). Model default `jev-latest` (pinned versions get retired by the vendor — `jev-1.13` 404s as of 2026-09-24). |
| `includes/Jev/DecisionTier.php` | off/shadow/live routing, lazy shared provider, shadow logging. |
| `includes/Jev/JevException.php` | Base exception. |
| `includes/Jev/JevAuthException.php` | 401 — bad/missing key. Own file: the PSR-4 autoloader requires one class per file. |
| `includes/Jev/JevValidationException.php` | 422 — malformed request. Same reason. |
| `includes/Actions/QualifyLeadAction.php` | Only current integration: lead qualification. |

## Modes (Settings → AI / Decision Tier)

| Setting key | Meaning |
|---|---|
| `jev_enabled` | `1` = tier active, `0` = bypassed (default `0`) |
| `jev_mode` | `shadow` (default) or `live` |
| `jev_api_key` | TypeSafe key (`ts_...`). Stored like any other secret; never returned by `api/settings.php`. Env fallback `TYPESAFE_API_KEY`. |
| `jev_model` | Override, default `jev-latest` |
| `jev_min_confidence` | Live-mode escalation threshold, default `0.65` |
| `jev_shadow_log` | Optional explicit shadow-log path |

- **off** (`jev_enabled=0`): the legacy LLM path runs, byte-for-byte as
  before. Zero behavior change — this is the default and the safe state.
- **shadow**: both run; the legacy result is returned and the Jev answers are
  appended to the shadow log for agreement analysis. Zero behavior change.
- **live**: the Jev decision is returned. On Jev error **or** confidence below
  `jev_min_confidence`, the legacy LLM path runs instead (escalation).

## Shadow-first rollout (do this before ever enabling live)

1. Add `jev_api_key` in Settings, keep `jev_enabled=0`. Nothing changes.
2. Set `jev_enabled=1`, `jev_mode=shadow`. Run normal qualification volume.
3. Inspect the shadow log (default `<app_root>/logs/jev_shadow.jsonl`,
   falls back to the system temp dir when the app dir isn't writable).
   Each line: `{ts, decision, mode, latency_ms, min_confidence,
   jev_answers, jev_value, llm_value, agree}`.
4. Slice by `decision` and compute the agreement rate. Ship `live` only when
   agreement is high **and** spot-checked disagreements favor Jev.
5. `live` keeps the escalation guard: low-confidence answers fall back to
   the legacy LLM automatically.

## Failure semantics

- Every Jev failure path falls back to the legacy result and is recorded via
  `error_log()` with a `[DecisionTier]` / `[JevProvider]` prefix. Logging
  must never break the pipeline.
- Shadow-log writes are best-effort (`@mkdir`, `@file_put_contents` with
  `LOCK_EX`); a failed write is logged and ignored.
- The API key is never written to logs; HTTP error bodies are truncated to
  500 chars in exception messages.

## Rollback

Set `jev_enabled=0` (or delete the key). The legacy path is then the only
code that runs. No deployment needed.

## Current integration status

Only `QualifyLeadAction::decideQualification()` is routed through the tier
(decision name `qualify_lead.decide_qualification`, questions `qualified`
[noul] + `score` [0–100], agreement = same verdict and score within 15).
Additional decision points should be wired one at a time, each with its own
stable decision name, and each proven in shadow mode first.

## Vendor facts (re-verify before forecasting)

- Endpoint `POST https://api.typesafe.ai/v1/systemone`, docs
  `https://docs.typesafe.ai/api`.
- Published pricing: $0.042 / 1M input tokens, output tokens free.
- Published latency: ~70–500ms per batched call.
