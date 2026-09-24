# ITEM2 — Bulk Verify (queue-backed email verification)

**Status:** implemented on `commercial/item2-bulk-verify` (see report for commit hash).
**Scope:** "Verify leads" on the Leads tab — verify selected leads or all unchecked
leads via MillionVerifier, processed by the existing cron queue worker in
batches. One HTTP request = enqueue only; a 10k-row list is never verified
inside a single request.

## How it works

1. **Buyer action** — "✓ Verify leads" button on the Leads tab. A confirm
   dialog states the credit cost up front: *"Each lookup uses about one of
   YOUR MillionVerifier credits."* Two scopes: the currently selected
   checkboxes, or all unchecked leads (unchecked = `verification_status =
   'unknown'`).
2. **Enqueue** — `POST api/leads.php?action=verify_bulk {mode, ids}` inserts
   ONE `task_queue` row (`task_type='BulkVerify'`, `lead_id=NULL`, JSON
   payload with the target list and a cursor). The request returns
   immediately with `{task_id, total, mode}`. A second bulk job cannot be
   enqueued while one is Pending/In Progress (double runs = double credit
   spend).
3. **Worker** — `cron/process_queue.php` → `TaskProcessor::processTask()`
   routes `BulkVerify` tasks to `App\BulkVerifyJob::run()`. Each tick
   processes one batch (default 150 leads, or 240 seconds, whichever comes
   first), writes every verdict to `leads.verification_status` /
   `leads.verified_at` **immediately**, advances the persisted cursor, then
   parks the task back to `Pending` with `scheduled_at` ~20s out for the
   next tick.
4. **Progress** — `GET api/leads.php?action=verify_status&id=TASKID` returns
   `{state, total, checked, valid, invalid, risky, unknown, percent, note,
   error, started_at, finished_at}`. The Leads tab polls it every 3 seconds
   and shows a progress bar + counts + a Cancel button.
5. **Cancel** — `POST api/leads.php?action=verify_cancel&id=` flips a
   `cancel_requested` flag in the payload (checked every 25 leads mid-batch)
   and moves a merely-queued job straight to `Cancelled`. Leads already
   checked keep their verdicts.

## Job model

The whole job is the task row; state lives in `payload`:

```json
{
  "job": "bulk_verify",
  "mode": "selected" | "unchecked",
  "lead_ids": [1, 2, 3],
  "cursor": 2,
  "last_id": 57,
  "total": 1234,
  "counts": {"valid": 1, "invalid": 1, "risky": 0, "unknown": 0},
  "started_at": "2026-09-24 10:00:00",
  "finished_at": null,
  "cancel_requested": false,
  "note": "Verified 300 of 1234 (150 this tick)."
}
```

- `selected` mode walks `lead_ids` by `cursor` index; deleted leads drop out.
- `unchecked` mode uses keyset pagination (`id > last_id`, ordered by id),
  so leads verified earlier in the job are never re-selected and newly
  imported leads can't be skipped or double-checked.
- `retry_count` is reset to 0 each time a batch parks, because resume state
  lives in the cursor — a long job legitimately takes many batches and must
  not trip the queue's 5-crash abandonment guard. A task that dies *mid-batch*
  keeps its incremented retry count, so the guard still catches genuinely
  crashing jobs.

## Rate-limit behavior

MillionVerifier publishes no hard per-minute cap for API keys, so the job
is conservative by default:

- **Politeness delay** between lookups: `verification_bulk_delay_ms`
  (default 250ms → ~240 lookups/min). The real adapter never throws on a
  429/5xx — it returns `unknown` — so…
- **Unknown-streak circuit breaker**: after
  `verification_bulk_max_unknown_streak` (default 15) consecutive `unknown`
  results, the batch parks gracefully with a note ("provider is probably
  rate-limiting or down") and retries on the next tick instead of burning
  credits on a dead provider.
- **Time budget**: a batch stops after 240s so one tick never starves the
  rest of the queue.

Tune via Settings → Email Verification (batch size, delay). Rough
throughput at defaults: ~150 leads/tick × one tick/5 min ≈ 1,800/hr, so a
10k list takes ~5–6 hours overnight. Say this to the buyer; don't imply
it's instant.

## Honest-behavior notes (what the verdicts mean)

The adapter maps MillionVerifier's result codes onto four statuses:

| MillionVerifier `result` | Our status | Meaning |
|---|---|---|
| `ok` | `valid` | Address confirmed deliverable. |
| `invalid`, `disposable` | `invalid` | Dead or burner address. Treated exactly like the per-send gate treats it: send refused, funnel counts it as invalid, **not** mailable. |
| `catch_all` | `risky` | Domain accepts everything; the mailbox can't be confirmed. **Never marked valid.** Send behavior follows the "Risky addresses" setting (block/flag). |
| `unknown`, `error`, anything else, transport failure | `unknown` | **Provider-side uncertainty, not a verdict on the address.** Stays `unknown` in the funnel (funnel "checked" = valid+invalid+risky only), and the job will happily re-check it later. The send gate does not cache `unknown` verdicts. |

- `invalid` verdicts are written to `leads.verification_status` — the same
  columns the funnel (`includes/FunnelStats.php`) reads — so dashboard
  counts update as the job runs. They are deliberately **not** added to
  `suppression_list`: that list records contact-level suppressions
  (unsubscribes, bounces, complaints), and the send gate already refuses
  invalid addresses without it.
- Bulk verify does not change `verification_required` or any send-gate
  setting; it only requires the gate to be switched on *with* a key, as a
  proxy for "the buyer has configured verification."
- The API key is never in UI output, API responses, or logs. The status
  endpoint exposes only counts and notes; `api/settings.php` redacts the
  key on read (secret-suffix rule).

## Settings (Settings → Email Verification)

| Key | Default | Notes |
|---|---|---|
| `verification_required` | `0` (off) | Master switch: per-send gate + bulk verify. |
| `verification_api_key` | empty | Secret; redacted on read, empty POST = unchanged. |
| `verification_provider` | `millionverifier` | Only provider today. |
| `verification_risky_action` | `block` | `block` \| `flag` for catch-all domains. |
| `verification_strict` | `0` | `1` = also block sends on `unknown`. |
| `verification_cache_days` | `30` | Reuse a fresh verdict instead of spending a credit. |
| `verification_bulk_batch_size` | `150` | Leads per queue tick. |
| `verification_bulk_delay_ms` | `250` | Pause between lookups. |
| `verification_bulk_max_unknown_streak` | `15` | Circuit breaker (not in the Settings UI key list; DB/env only). |

If verification is off or keyless, the bulk action refuses with:
*"Email verification is switched off. Bulk verify needs it: go to Settings →
Email Verification (MillionVerifier), paste your MillionVerifier API key, turn
verification ON, and save…"*

## Files

- `includes/BulkVerifyJob.php` — enqueue / run / status / cancel.
- `includes/Domain/TaskProcessor.php` — routes `BulkVerify` tasks to the job.
- `api/leads.php` — `verify_bulk` (POST), `verify_cancel` (POST),
  `verify_status` (GET), `verify_count` (GET).
- `api/settings.php` — verification keys added to the write allowlist.
- `index.php` — Email Verification settings block; "Verify leads" button +
  progress panel on the Leads tab.
- `assets/js/dashboard.js` — confirm dialogs (credit cost stated), polling,
  cancel.
- `schema.sql` + `migrations/2026-09-24-bulk-verify.sql` — `BulkVerify`
  task type, `Cancelled` task status.
- `tests/bulk_verify/run_bulk_verify_tests.php` — scratch-DB suite.
