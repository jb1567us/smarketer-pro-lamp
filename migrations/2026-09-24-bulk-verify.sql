-- ITEM2 (2026-09-24): bulk-verify job support on existing databases.
-- Fresh installs get these from schema.sql.
--
-- IDEMPOTENCY: safe to run multiple times. A bare MODIFY COLUMN under
-- STRICT mode fatals (ERROR 1265) when any row holds a value outside the
-- target ENUM, so the procedure widens each column only when every existing
-- row already fits the target vocabulary, and skips the ALTER entirely when
-- the column already carries the target definition. Unknown values are never
-- silently rewritten: if a row falls outside the vocabulary the column is
-- left untouched rather than risking data corruption.
DELIMITER $$
DROP PROCEDURE IF EXISTS item2_bulk_verify_guard$$
CREATE PROCEDURE item2_bulk_verify_guard()
guard: BEGIN
    DECLARE tbl_exists INT DEFAULT 0;
    DECLARE unknown_types INT DEFAULT 0;
    DECLARE unknown_status INT DEFAULT 0;
    DECLARE cur_type TEXT DEFAULT '';

    SELECT COUNT(*) INTO tbl_exists
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_queue';
    IF tbl_exists = 0 THEN
        -- Fresh database: schema.sql already defines the widened ENUMs.
        LEAVE guard;
    END IF;

    -- task_type: add 'BulkVerify' to the vocabulary (UNIFIED 2026-10-01:
    -- converged ENUM also carries 'SequenceSend' from repair/phase0-pipeline).
    SELECT COLUMN_TYPE INTO cur_type
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_queue' AND COLUMN_NAME = 'task_type';
    IF cur_type != '' AND LOCATE('BulkVerify', cur_type) = 0 THEN
        SELECT COUNT(*) INTO unknown_types FROM task_queue
        WHERE task_type NOT IN ('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft','BulkVerify','SequenceSend');
        IF unknown_types = 0 THEN
            ALTER TABLE task_queue
                MODIFY COLUMN task_type ENUM('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft','BulkVerify','SequenceSend') NOT NULL;
        END IF;
    END IF;

    -- status: converge on the canonical vocabulary (no-op when already there).
    SET cur_type = '';
    SELECT COLUMN_TYPE INTO cur_type
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_queue' AND COLUMN_NAME = 'status';
    IF cur_type != '' AND cur_type != 'enum(''Pending'',''In Progress'',''Completed'',''Failed'',''Cancelled'')' THEN
        SELECT COUNT(*) INTO unknown_status FROM task_queue
        WHERE status NOT IN ('Pending','In Progress','Completed','Failed','Cancelled');
        IF unknown_status = 0 THEN
            ALTER TABLE task_queue
                MODIFY COLUMN status ENUM('Pending','In Progress','Completed','Failed','Cancelled') DEFAULT 'Pending';
        END IF;
    END IF;
END$$
DELIMITER ;
CALL item2_bulk_verify_guard();
DROP PROCEDURE IF EXISTS item2_bulk_verify_guard;
