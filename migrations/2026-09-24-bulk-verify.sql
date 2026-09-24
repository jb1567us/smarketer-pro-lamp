-- ITEM2 (2026-09-24): bulk-verify job support on existing databases.
-- Safe to run multiple times: MODIFY COLUMN to the same definition is a no-op.
-- Fresh installs get these from schema.sql.

ALTER TABLE task_queue
    MODIFY COLUMN task_type ENUM('Enrichment','EmailOutreach','SocialOutreach','Qualify','Enrich','Draft','BulkVerify') NOT NULL;

ALTER TABLE task_queue
    MODIFY COLUMN status ENUM('Pending','In Progress','Completed','Failed','Cancelled') DEFAULT 'Pending';
