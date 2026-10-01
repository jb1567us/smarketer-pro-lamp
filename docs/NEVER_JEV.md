# Never-Jev Boundaries — smarketer-pro-lamp product policy

**Status: product policy, adopted by owner 2026-10-01.** This document is normative.
Any future port, feature, or refactor that would move a task across one of these
boundaries needs the owner's explicit decision, not a code review.

## Product rules this document rests on

- **Product Insights** = fully automatic once the human puts the initial inputs in.
  No human review in the pipeline.
- **smarketer-pro-lamp** = keeps human review on **leads and outbound sends**,
  NEVER on the ICP determination itself. Product or service in → the app determines
  the ICP automatically, for a buyer with zero ICP knowledge. Buyer override of the
  ICP is an advanced-only path, never a default.
- **JEV (TypeSafe System One) is decision-only**: it takes program state plus typed
  questions and returns typed, calibrated decisions (`noul` probability, `choice`,
  `score`). It judges fit and grounding; it emits no prose, asserts no facts, and
  never generates names of real-world things.
- **JEV stays off/shadow in production** (`jev_enabled='0'` default; shadow first).
  The ship call — what runs live against real money and real senders — belongs to
  the owner alone.

Do not collapse the product distinction: where a fully-automatic sibling product
would silently drop a failing item, LAMP must **flag** it (route to Needs Review).

## The six boundaries

### 1. All outreach prose generation — generative LLM only
JEV judges fit/grounding, emits no prose. By construction the decision tier returns
typed decisions, not words. Draft copy comes from the generative LLM; JEV's job is
to judge it (e.g. `draft_review.review_draft`) and to fail closed — any JEV
error, timeout, or low-confidence result escalates to a human, never silently
approves.

### 2. Final partner signing, pricing, public claims — human only
No model ever signs a partner, sets a price, or makes a public claim. JEV may
*score* a candidate partner against the partner-ICP rubric (fit dimensions only);
the commercial decision is a human's. Public-facing claims and pricing are owner
decisions, always.

### 3. Send/ship decisions — deterministic gates + human review
No JEV "should we send?" second-opinion call. A separate Jev judgment on whether to
send would be a second opinion with no new evidence: pure cost, no information.
Send decisions are made by deterministic compliance gates first (rules, caps,
throttles, suppression lists), then human review. Live JEV failures on the
send gate fail closed to DENIED — the model never vetoes its own way past the
gate.

### 4. Licensing/metering — deterministic counting
Entitlement checks, usage metering, and credit accounting are arithmetic.
A probabilistic judgment adds nothing to counting. Keep probabilistic models out
of anything that decides what the customer is allowed to do or what they owe.

### 5. Candidate retrieval, scope filtering, aggregation, thresholds, ranking keys,
mention-validity predicates, recency/staleness — deterministic code
The machinery that *finds* candidates and *orders* them is code, not judgment:
retrieval queries, scope and eligibility filters, aggregation math, threshold
comparisons, sort keys, validity predicates, recency buckets, and staleness
lifecycle. JEV scores fit against an explicit rubric; it does not decide what is
retrievable, what is in scope, or what counts as recent. The never-invent-evidence
rule binds every prompt: scores are only as good as the evidence handed to them.

### 6. The determination is automatic; human review lives on leads and sends —
this boundary never drifts
No JEV gate may ever insert a human into the ICP determination path, and no
"efficiency" port may ever remove the human from the send path. The determination
stays automatic end to end; the review stays on leads (Needs Review) and on
outbound sends (approval gate). This asymmetry is the product. It is not a
configuration option.

---
*Source: PI → LAMP integration map §5, drafted 2026-09-30; adopted as LAMP policy
per owner approval 2026-10-01 ("approve all").*
