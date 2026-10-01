-- Reintroduce tech_stack as a TOGGLEABLE ICP scoring dimension (2026-09-29).
--
-- Product decision (supersedes the same-day complete removal in
-- 2026-09-29-icp-default-weights.sql): signal strength depends on where the
-- tech lives. Enterprise stacks sit behind walls (undiscoverable -> noise),
-- but SMB/ecosystem tech (WordPress, Shopify, Wix) is publicly detectable,
-- and for a seller targeting a tech ecosystem the prospect's stack is the
-- primary signal. So tech_stack becomes an optional dimension, OFF by
-- default, enabled by the buyer for tech-targeted selling.
--
-- Mechanics:
--   - icp_dimensions gains an `enabled` flag (TINYINT 0/1, default 1).
--     The flag — not weight 0 — is what excludes a dimension: a disabled
--     dimension gets no scoring-prompt question (zero tokens) and is
--     excluded from aggregation, so default-off is mathematically identical
--     to the dimension not existing.
--   - When enabled, tech_stack is scored on DISCOVERABILITY (how much of
--     the target tech surface was actually found in the evidence), not on
--     stack "goodness". Never invent evidence; nothing found = low score.
--   - This migration re-inserts exactly one tech_stack row per profile with
--     enabled = 0 and weight = 0. It does NOT resurrect scoring for any
--     existing profile: everything defaults off, and the five core
--     dimensions (already enabled = 1 by the column default) are untouched.
--   - Buyer-locked rows are respected: the re-inserted row is unlocked
--     (weight 0, disabled); no existing row's weight or lock is changed.
--   - The re-add is audited once per profile in icp_weight_history.
--
-- Ordering note: run AFTER 2026-09-29-icp-default-weights.sql (which deleted
-- all tech_stack rows). On a fresh install that deletion is a no-op; the
-- `enabled` column already exists (added to the base 2026-09-28-icp-scoring.sql
-- CREATE TABLE), so step 1 below is a guarded no-op there and this file
-- simply adds the disabled row to the seeded profile.
--
-- Idempotent: the ALTER is guarded on information_schema; the INSERTs only
-- fire for profiles that still lack a tech_stack row, so re-running changes
-- nothing and writes no duplicate audit rows.

START TRANSACTION;

-- 1. The enabled flag. Existing rows (the five core dimensions) backfill to
--    1 = enabled; only tech_stack rows inserted below get 0.
SET @icp_have_enabled := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'icp_dimensions'
      AND COLUMN_NAME = 'enabled'
);
SET @icp_add_enabled := IF(@icp_have_enabled = 0,
    'ALTER TABLE icp_dimensions ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT 1');
PREPARE icp_add_enabled_stmt FROM @icp_add_enabled;
EXECUTE icp_add_enabled_stmt;
DEALLOCATE PREPARE icp_add_enabled_stmt;

-- 2. Audit the re-add (once per profile; runs before the row insert so the
--    NOT EXISTS guard below also guards the audit on re-runs).
INSERT INTO icp_weight_history
    (profile_id, dimension_key, old_weight, new_weight, reason, sample_size, created_by)
SELECT p.id, 'tech_stack', 0, 0,
       'tech_stack reintroduced as toggleable dimension, disabled by default (2026-09-29)',
       NULL, 'user'
FROM icp_profiles p
WHERE NOT EXISTS (
    SELECT 1 FROM icp_dimensions d
    WHERE d.profile_id = p.id AND d.dimension_key = 'tech_stack'
);

-- 3. One disabled tech_stack row per profile (weight 0, unlocked). Existing
--    profiles keep their current five-dimension weights untouched.
INSERT INTO icp_dimensions
    (profile_id, dimension_key, weight, buyer_locked, enabled, target_config)
SELECT p.id, 'tech_stack', 0, 0, 0, '{"tools":[]}'
FROM icp_profiles p
WHERE NOT EXISTS (
    SELECT 1 FROM icp_dimensions d
    WHERE d.profile_id = p.id AND d.dimension_key = 'tech_stack'
);

COMMIT;
