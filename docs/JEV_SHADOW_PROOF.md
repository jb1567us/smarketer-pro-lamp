# JEV Shadow-Mode Proof — smarketer-pro-lamp

Date: 2026-10-01 | Harness: `tests/jev/shadow_proof.php` | 20 fixtures, scripted stub TypeSafe server (no real credits spent).

## What was proven

1. **Shadow mode never changes output.** All 20 fixtures returned results byte-identical to "off" mode. The decision tier is observably inert until a human flips it to live.
2. **Every shadow decision is logged.** 18/20 fixtures produced a shadow-log record with `agree`, `min_confidence`, `latency_ms`, and both values. The 2 provider-outage fixtures fell back to legacy with no exception to the caller (nothing to log — the Jev side never answered).
3. **Agreement: 1/18** on the scripted answers. Disagreements are logged, not hidden — that is the entire point of shadow mode: collect disagreement evidence before trusting live.
4. **Low-confidence answers are visible.** 5 records fell below the 0.65 escalation threshold; in live mode these escalate to the legacy path (verified: fixture 15 escalated).
5. **Provider failure is safe.** HTTP 500 from the stub → legacy result returned, no exception, no partial state.

## Disagreements (scripted)

| fixture | Jev verdict | legacy verdict | Jev confidence |
|---|---|---|---|
| 0 | Jev qualified / 100 | legacy qualified / 82 | 0.88 |
| 1 | Jev qualified / 100 | legacy qualified / 64 | 0.70 |
| 2 | Jev not / 100 | legacy not / 8 | 0.95 |
| 3 | Jev not / 100 | legacy not / 22 | 0.80 |
| 4 | Jev qualified / 100 | legacy qualified / 78 | 0.82 |
| 5 | Jev not / 100 | legacy not / 15 | 0.60 |
| 6 | Jev qualified / 100 | legacy qualified / 55 | 0.45 |
| 7 | Jev qualified / 100 | legacy qualified / 74 | 0.75 |
| 8 | Jev not / 100 | legacy not / 12 | 0.90 |
| 9 | Jev qualified / 100 | legacy not / 40 | 0.70 |
| 10 | Jev not / 100 | legacy qualified / 58 | 0.66 |
| 11 | Jev qualified / 100 | legacy qualified / 71 | 0.73 |
| 12 | Jev not / 100 | legacy not / 18 | 0.85 |
| 13 | Jev not / 100 | legacy not / 33 | 0.50 |
| 15 | Jev qualified / 100 | legacy qualified / 66 | 0.40 |
| 16 | Jev qualified / 100 | legacy qualified / 60 | 0.35 |
| 17 | Jev qualified / 100 | legacy qualified / 79 | 0.84 |

## Confidence distribution (scripted stub)

min 0.35, max 0.95, mean 0.71. Stub latency is loopback-only and not representative of real TypeSafe latency.

## What this does NOT prove

- Nothing here touched the real TypeSafe API. Latency, pricing, and model behavior against production still need verification with a real key.
- The "legacy" side in this harness is a deterministic heuristic stand-in, not a live LLM call. The proof is about tier mechanics (routing, logging, fallback), not about which qualifier is smarter.
- 20 fixtures exercise the contract; they are not a statistical validation of the model.

## Recommendation

Keep `jev_enabled=0` (off) in production until the owner makes the ship call (made 2026-10-01: fresh installs now ship enabled + live). To gather real evidence: enable shadow mode with a real key, let `logs/jev_shadow.jsonl` accumulate on live traffic, then review agreement before ever considering live.