-- Phase 4 (2026-09-28): campaign sequences — enrollments, per-step sends,
-- open/reply tracking, and the campaign timeline.
--
-- A launch enrolls each eligible lead (one row in sequence_enrollments) and
-- queues step 1 as a `SequenceSend` task_queue row. The queue worker executes
-- it through SendSequenceStepAction (throttle-gated, compliance-gated,
-- Safety/Simulated-mode aware). Each successful send schedules the next step
-- per the template's delay_days cadence; any reply, unsubscribe, or bounce
-- stops the enrollment and cancels its queued sends.
--
-- All statements are idempotent (safe to re-run on a migrated install).

CREATE TABLE IF NOT EXISTS sequence_enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    lead_id INT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    current_step INT NOT NULL DEFAULT 1,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    stopped_at TIMESTAMP NULL,
    stop_reason VARCHAR(255) NULL,
    UNIQUE KEY uq_enrollment (campaign_id, lead_id),
    INDEX idx_enroll_lead (lead_id),
    INDEX idx_enroll_status (campaign_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- status: active | completed | stopped_reply | stopped_unsubscribe |
--         stopped_bounce | stopped_complaint | stopped_hostile | stopped_manual

CREATE TABLE IF NOT EXISTS sequence_sends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_id INT NOT NULL,
    campaign_id INT NOT NULL,
    lead_id INT NOT NULL,
    template_id INT NULL,
    step_order INT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'queued',
    scheduled_at TIMESTAMP NULL,
    sent_at TIMESTAMP NULL,
    task_id INT NULL,
    track_token CHAR(64) NULL,
    subject VARCHAR(500) NULL,
    body MEDIUMTEXT NULL,
    open_count INT NOT NULL DEFAULT 0,
    first_opened_at TIMESTAMP NULL,
    last_opened_at TIMESTAMP NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_track_token (track_token),
    INDEX idx_send_enroll (enrollment_id, step_order),
    INDEX idx_send_status (campaign_id, status, scheduled_at),
    INDEX idx_send_lead (lead_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- status: queued | sending | sent | simulated | failed | skipped | cancelled
-- track_token: 64-hex unguessable token for the open-tracking pixel.

-- Campaign timeline: every measurable sequence event lands here, including
-- reply-classification verdicts attached from api/ingest_reply.php.
CREATE TABLE IF NOT EXISTS sequence_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT NOT NULL,
    lead_id INT NULL,
    send_id INT NULL,
    event_type VARCHAR(48) NOT NULL,
    detail TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_evt_campaign (campaign_id, created_at),
    INDEX idx_evt_lead (lead_id, created_at),
    INDEX idx_evt_send (send_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- event_type: enrolled | launch | queued | sent | simulated | failed |
--             skipped | opened | replied | classified | sequence_stopped |
--             sequence_completed | campaign_stopped

-- Cadence: days to wait after THIS step sends before the next step is queued.
ALTER TABLE templates ADD COLUMN IF NOT EXISTS delay_days INT NOT NULL DEFAULT 3;

-- Queue task type for per-step sequence sends (processed by
-- SendSequenceStepAction via TaskProcessor, throttle-gated in
-- cron/process_queue.php alongside EmailOutreach/SocialOutreach).
--
-- UNIFIED (2026-10-01): the vocabulary is the UNION of both lines'
-- task types — 'BulkVerify' (commercial/fixes-4) AND 'SequenceSend'
-- (repair/phase0-pipeline). Guarded: widens only when every stored row
-- already fits the union, so re-running never narrows the ENUM or
-- fatals on unknown values.
--
-- Splitter-safe by design: the repo's migration test harness
-- (tests/needs_review/common.php nr_apply_migration, tests/phase4)
-- strips '--' lines and explodes on ';', so this uses the @var +
-- PREPARE/EXECUTE idiom (same as 2026-09-29-icp-tech-stack-toggle.sql) —
-- NO stored procedures, NO DELIMITER blocks.
SET @p4_have_taskq := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_queue'
);
SET @p4_cur_type := (
    SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_queue' AND COLUMN_NAME = 'task_type'
);
SET @p4_need_widen := IF(
    @p4_have_taskq > 0
    AND @p4_cur_type IS NOT NULL
    AND (LOCATE('SequenceSend', @p4_cur_type) = 0 OR LOCATE('BulkVerify', @p4_cur_type) = 0)
    AND NOT EXISTS (
        SELECT 1 FROM task_queue
        WHERE task_type NOT IN ('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft','BulkVerify','SequenceSend')
    ),
    1, 0
);
SET @p4_sql := IF(@p4_need_widen = 1,
    'ALTER TABLE task_queue MODIFY COLUMN task_type ENUM(''Enrichment'',''EmailOutreach'',''SocialOutreach'',''Qualify'',''Enrich'',''Draft'',''BulkVerify'',''SequenceSend'') NOT NULL',
    'SELECT 1');
PREPARE p4_widen_stmt FROM @p4_sql;
EXECUTE p4_widen_stmt;
DEALLOCATE PREPARE p4_widen_stmt;
