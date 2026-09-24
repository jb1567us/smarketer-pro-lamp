-- =========================================================================
-- ITEM B (2026-09-24): verification_status ENUM alignment migration.
-- =========================================================================
-- Root cause: two independent vocabularies share leads.verification_status,
-- but the column had two contradictory definitions:
--   * schema.sql + migrations/2026-09-23-compliance.sql : VARCHAR(20) DEFAULT 'unknown'
--   * schema/migration_phase7_saas.sql                  : ENUM('unverified','evidence_backed',
--       'dns_confirmed','cross_source_matched','gold_standard') DEFAULT 'unverified'
-- On a database where the phase-7 ENUM is in effect, the bulk-verify job and
-- the send gate writing 'unknown' / 'valid' / 'invalid' / 'risky' fatals under
-- STRICT SQL mode (ERROR 1265: Data truncated for column 'verification_status').
--
-- Fix: converge every definition on ONE union ENUM (see schema.sql), and run
-- this migration on existing databases:
--   1. add the column if it is missing entirely (very old DBs);
--   2. sanitize any value outside the vocabulary to 'unknown';
--   3. convert the column to the union ENUM.
--
-- IDEMPOTENCY: safe to run multiple times. The procedure no-ops when the
-- column already matches the target definition exactly (checked against
-- INFORMATION_SCHEMA). The sanitize UPDATE only touches out-of-vocabulary
-- rows. The verification index is created only if missing.
--
-- POSITIONAL-REMAP SAFETY: the target ENUM keeps the phase-7 value order as
-- a prefix and appends the verify vocabulary. The conversion stages through
-- VARCHAR(20) first, so even a hypothetical direct ENUM->ENUM ALTER can
-- never reinterpret stored values by position.
-- =========================================================================

-- 0. Bail out cleanly if the leads table itself does not exist yet
--    (fresh DBs that only ran schema.sql already have the final column).
DELIMITER $$
DROP PROCEDURE IF EXISTS itemb_enum_align$$
CREATE PROCEDURE itemb_enum_align()
align: BEGIN
    DECLARE cur_type TEXT DEFAULT '';
    DECLARE tbl_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO tbl_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads';

    IF tbl_exists = 0 THEN
        -- Nothing to align: schema.sql is the source of truth for new tables.
        LEAVE align;
    END IF;

    -- 1. Add the column if missing (very old databases).
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'leads'
          AND COLUMN_NAME = 'verification_status'
    ) THEN
        ALTER TABLE leads ADD COLUMN `verification_status`
            ENUM('unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard','unknown','valid','invalid','risky')
            NOT NULL DEFAULT 'unknown';
        LEAVE align;
    END IF;

    SELECT COLUMN_TYPE INTO cur_type
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'leads'
      AND COLUMN_NAME = 'verification_status'
    LIMIT 1;

    IF cur_type = 'enum(''unverified'',''evidence_backed'',''dns_confirmed'',''cross_source_matched'',''gold_standard'',''unknown'',''valid'',''invalid'',''risky'')' THEN
        -- Already aligned: nothing to do.
        LEAVE align;
    END IF;

    -- 2. Stage through VARCHAR so ENUM->ENUM positional remapping can never
    --    misinterpret existing values; ENUM->VARCHAR converts by string.
    IF cur_type <> 'varchar(20)' THEN
        ALTER TABLE leads MODIFY COLUMN `verification_status`
            VARCHAR(20) NOT NULL DEFAULT 'unknown';
    END IF;

    -- 3. Sanitize anything outside the vocabulary to 'unknown':
    --    legacy junk, '' left by old non-strict truncations, NULLs.
    UPDATE leads SET verification_status = 'unknown'
    WHERE verification_status IS NULL
       OR verification_status NOT IN (
            'unverified','evidence_backed','dns_confirmed','cross_source_matched',
            'gold_standard','unknown','valid','invalid','risky'
          );

    -- 4. Final conversion. VARCHAR->ENUM converts by string value: safe.
    ALTER TABLE leads MODIFY COLUMN `verification_status`
        ENUM('unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard','unknown','valid','invalid','risky')
        NOT NULL DEFAULT 'unknown';
END align$$
DELIMITER ;

CALL itemb_enum_align();
DROP PROCEDURE IF EXISTS itemb_enum_align;

-- 5. Verification index (phase-7 created it; schema.sql now includes it;
--    databases that went through the VARCHAR path never got it).
DELIMITER $$
DROP PROCEDURE IF EXISTS itemb_verif_idx$$
CREATE PROCEDURE itemb_verif_idx()
BEGIN
    -- Needs trust_score (item 4 migration) to exist; otherwise skip quietly.
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'leads'
          AND COLUMN_NAME = 'trust_score'
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'leads'
          AND INDEX_NAME = 'idx_leads_verification'
    ) THEN
        CREATE INDEX idx_leads_verification ON leads(verification_status, trust_score);
    END IF;
END$$
DELIMITER ;

CALL itemb_verif_idx();
DROP PROCEDURE IF EXISTS itemb_verif_idx;
