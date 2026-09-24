# FIX 4 — Paused campaigns actually stop sending

## The bug

The compliance monitor (`includes/SendMonitor.php`) auto-pauses abusive
campaigns with `campaigns.is_active = 0`, and the buyer can pause manually
the same way (`api/campaigns.php` toggle). But `cron/process_queue.php`
drained already-queued send tasks without checking pause status — a pause
that didn't stop sending. This was the most serious remaining launch
blocker.

## The fix

- New `includes/CampaignPauseGate.php` (`App\CampaignPauseGate`):
  `resolveTaskCampaigns()` (payload `campaign_id` wins, batched
  `leads.campaign_id` fallback), `pausedIds()` (one batched
  `is_active = 0` lookup), `holdTaskIds()` (the union). Never throws;
  fails OPEN so the queue can't stall on a lookup error.
- `cron/process_queue.php`: computes `$heldTaskIds` once per tick and
  skips held tasks at the top of the loop, BEFORE the throttle gate (a
  paused campaign must not burn throttle budget either).
- Held tasks are left `Pending` with their original `scheduled_at` — they
  are NOT claimed, NOT marked failed, burn NO attempts, and are NOT counted
  in the blocked-send counters. Unpausing resumes them on the next tick.
- Both auto-pause and manual pause write `is_active = 0`, so one predicate
  covers both. Missing campaign rows are treated as active (fail-open).
- UI: `assets/js/blocked_counts.js` gains `campaignPausedHtml()`, rendered
  on every campaign card in `assets/js/dashboard.js` — "Paused — queued
  sends are held and will resume when you reactivate", or the auto-pause
  reason when `status='paused'`. Paused holds are deliberately NOT counted
  as compliance blocks.

## Honest boundaries

- Mid-flight: a task already claimed and executing when the pause lands
  completes its send — it cannot be un-sent. The gate guarantees no NEW
  sends are claimed for a paused campaign after the check.
- The manual one-off send button (`api/send_email.php`) is an explicit
  buyer action and is NOT gated by pause state; only the queue drain is.
- `is_active` on the campaigns row is the single source of truth; the
  worker reads it fresh every tick, so a pause takes effect on the next
  cron run (up to 5 minutes on typical shared hosting).

## Tests

`tests/sending/PausedCampaignGateTest.php` (in-memory SQLite): paused
campaign tasks held, active campaign tasks not held, unpause resumes,
non-send task types never held, payload-vs-lead campaign resolution,
missing campaign fail-open, lookup failure fail-open. Existing suites
(compliance, licensing, sending, diagnostics, bulk verify, trustscorer,
blocked counts, enum, JEV, queue stress) stay green.
