-- P3 (2026-10-01): per-section grounding QA for the draft reviewer.
--
-- Adds the one-shot anchor-regeneration marker to the drafts table.
-- SectionGroundingAction performs AT MOST ONE anchored regeneration per
-- draft lineage: it marks grounding_regen=1 on both the original draft and
-- the regenerated row, and refuses to regenerate when the marker is set.
-- Fail-closed: when this migration has not been applied (column absent),
-- the QA pass runs scoring but marks the draft "un-regenerated" instead of
-- attempting a regeneration it could not deduplicate.
--
-- Idempotent: the ALTER is guarded on information_schema, so re-running
-- changes nothing. Existing rows backfill to 0 = never anchor-regenerated.

START TRANSACTION;

SET @sg_have_regen := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'drafts'
      AND COLUMN_NAME = 'grounding_regen'
);
SET @sg_add_regen := IF(@sg_have_regen = 0,
    'ALTER TABLE drafts ADD COLUMN grounding_regen TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE sg_add_regen_stmt FROM @sg_add_regen;
EXECUTE sg_add_regen_stmt;
DEALLOCATE PREPARE sg_add_regen_stmt;

COMMIT;
