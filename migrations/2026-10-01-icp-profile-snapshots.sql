-- P4: versioned, pinned, read-only ICP profile evidence snapshots
-- (PI -> LAMP integration map section 4b, workstream P4).
--
-- Problem (run-6 phantom-config lesson): LAMP's IcpProfile is live DB state;
-- nothing pinned what a scoring run saw, so a past run's inputs were not
-- reproducible. This migration adds the two tables that make "what did the
-- run see?" auditable forever:
--
--   icp_profile_snapshots — one row per DISTINCT profile state. The row
--     carries the full scoring-relevant state (all dimension weights,
--     buyer_locked + enabled flags incl. tech_stack, target_configs,
--     exclusions, thresholds) as canonical JSON plus a content_hash
--     (UNIQUE). Identical profile state reuses the existing row, so a
--     snapshot write is idempotent — no duplicate rows, no bloat.
--   lead_scoring_runs — one row per scoring run, pinning
--     (profile_snapshot_id, evidence_set_hash): the snapshot the run saw
--     plus a hash over the profile state AND the lead/evidence inputs the
--     run consumed. Rollback = repoint: re-execute against a named earlier
--     snapshot id to reproduce the run's inputs exactly.
--
-- Immutability: snapshots are INSERT-only. BEFORE UPDATE / BEFORE DELETE
-- triggers raise SQLSTATE 45000, so any attempted mutation fails loudly
-- instead of silently rewriting history.
--
-- Lighter than a full KG by design (map section 4b P4): immutable profile
-- snapshots, NOT a separate SQLite file.
--
-- Idempotent: table CREATEs are guarded on information_schema; triggers are
-- dropped-then-created. Safe to re-run.
--
-- Product rules honored: purely additive observability — no verdict, score,
-- status, or JEV-mode behavior changes. Human review stays on leads and
-- outbound sends (flag-not-drop); the determination path is untouched.

START TRANSACTION;

-- 1. The immutable snapshot store. -------------------------------------------
SET @p4_have_snap := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'icp_profile_snapshots'
);
SET @p4_add_snap := IF(@p4_have_snap = 0,
    'CREATE TABLE icp_profile_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        profile_id INT NOT NULL,
        profile_name VARCHAR(255) NOT NULL DEFAULT '''',
        content_hash CHAR(64) NOT NULL,
        snapshot JSON NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_content_hash (content_hash),
        KEY idx_profile_id (profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT 1');
PREPARE p4_add_snap_stmt FROM @p4_add_snap;
EXECUTE p4_add_snap_stmt;
DEALLOCATE PREPARE p4_add_snap_stmt;

-- 2. The per-run pin record. ---------------------------------------------------
SET @p4_have_runs := (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lead_scoring_runs'
);
SET @p4_add_runs := IF(@p4_have_runs = 0,
    'CREATE TABLE lead_scoring_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        lead_id INT NOT NULL,
        profile_snapshot_id BIGINT UNSIGNED NULL,
        evidence_set_hash CHAR(64) NULL,
        fit_score INT NULL,
        verdict VARCHAR(32) NOT NULL DEFAULT '''',
        source VARCHAR(32) NOT NULL DEFAULT '''',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_lead_id (lead_id),
        KEY idx_snapshot_id (profile_snapshot_id),
        CONSTRAINT fk_scoring_runs_snapshot
            FOREIGN KEY (profile_snapshot_id)
            REFERENCES icp_profile_snapshots (id)
            ON DELETE RESTRICT ON UPDATE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    'SELECT 1');
PREPARE p4_add_runs_stmt FROM @p4_add_runs;
EXECUTE p4_add_runs_stmt;
DEALLOCATE PREPARE p4_add_runs_stmt;

-- 3. Immutability guards: snapshots may be inserted, never mutated. -----------
DROP TRIGGER IF EXISTS trg_icp_profile_snapshots_no_update;
CREATE TRIGGER trg_icp_profile_snapshots_no_update
BEFORE UPDATE ON icp_profile_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'icp_profile_snapshots is immutable: updates are forbidden';

DROP TRIGGER IF EXISTS trg_icp_profile_snapshots_no_delete;
CREATE TRIGGER trg_icp_profile_snapshots_no_delete
BEFORE DELETE ON icp_profile_snapshots
FOR EACH ROW
SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'icp_profile_snapshots is immutable: deletes are forbidden';

COMMIT;
