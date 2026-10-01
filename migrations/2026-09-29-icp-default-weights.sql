-- Remove the tech_stack ICP dimension entirely (2026-09-29).
--
-- Rationale (product decision): a prospect's internal sales-tech stack is
-- structurally unobservable — no enrichment can reliably reveal it, and no
-- prompt rewrite changes that. It only carried decision weight for
-- tech-to-tech / partner / integration plays, and at 17% it was the largest
-- single source of scoring noise in real-data calibration (leads with no
-- public CRM info lost ~12 fit points on that dimension alone).
-- The dimension is removed from the scoring model altogether:
--   - IcpProfile::DIMENSIONS no longer lists it (prompt, validation,
--     notes-marker parsing all follow the constant);
--   - ScoreLeadFitAction::normalizeJevAnswers() filters the weight vector
--     to known dimensions before summing, so even a stray unknown weight
--     key can never inflate the denominator and dilute scores;
--   - updateWeights() rejects it as an unknown dimension, so it can never
--     be re-added through the settings UI.
--
-- What this migration does:
--   1. Audits EVERY icp_dimensions row with dimension_key = 'tech_stack'
--      (all profiles, buyer-locked or not) into icp_weight_history, so the
--      pre-removal weights stay on record.
--   2. Deletes them all, locked or not. A buyer-locked tech_stack row is
--      still a retired row: keeping it would leave it inside the weight
--      vector and keep diluting every score, which would not be a complete
--      removal.
--   3. Resets every UNLOCKED row on the default profile's remaining five
--      dimensions to 20 (even 20/20/20/20/20 split, sums to 100).
--
-- Buyer-locked rows on the remaining five dimensions are NOT touched, on
-- any profile. Scoring normalizes by the actual weight sum, so a locked
-- vector that no longer sums to 100 still scores proportionally — e.g. a
-- buyer whose locked weights summed to 83 after the tech_stack row is
-- removed scores exactly as if those five weights were 83/83 of the total.
-- The buyer can re-tune to a clean 100-sum five-dimension vector through
-- the ICP settings UI, which requires exactly the five known dimensions
-- summing to 100 on the next save.
--
-- Non-default profiles keep their own unlocked weights (proportional
-- scoring makes the removal safe there too); only the default profile's
-- unlocked defaults are evened out, matching the fresh-install seed.
--
-- Lead-enrichment's tech_stack *field* (ExtractionExpert, IntentAnalyst,
-- agent_chat lead data, DorkLibrary, etc.) is untouched: that is observed
-- lead data, not a scoring dimension.
--
-- Idempotent: re-running finds no tech_stack rows (the audit SELECT then
-- inserts nothing, the DELETE matches nothing) and the UPDATE only touches
-- rows whose weight is not already 20.

START TRANSACTION;

-- 1. Audit trail for every tech_stack row being retired (all profiles,
--    locked or not; inserts nothing if the rows are already gone).
INSERT INTO icp_weight_history
    (profile_id, dimension_key, old_weight, new_weight, reason, sample_size, created_by)
SELECT d.profile_id, 'tech_stack', d.weight, 0,
       'dimension retired 2026-09-29: internal sales-tech stack is structurally unobservable; 17% weight was the top noise source in real-data calibration',
       24, 'user'
FROM icp_dimensions d
WHERE d.dimension_key = 'tech_stack';

-- 2. Retire the dimension everywhere, buyer-locked or not.
DELETE FROM icp_dimensions
WHERE dimension_key = 'tech_stack';

-- 3. Even 20/20/20/20/20 default split on the default profile's remaining
-- five dimensions (unlocked rows only; buyer-locked rows are never
-- overwritten).
UPDATE icp_dimensions
SET weight = 20
WHERE profile_id = 1
  AND dimension_key IN
      ('company_size', 'industry_fit', 'target_title', 'geography', 'trigger_signals')
  AND buyer_locked = 0
  AND weight <> 20;

COMMIT;
