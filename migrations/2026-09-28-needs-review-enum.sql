-- ------------------------------------------------------------------
-- leads.status: add 'Needs Review' ENUM value (goal_67693fcbba4c, schema
-- subject 1/4).
--
-- The 50-75 weighted-fit review band now persists as a genuine
-- leads.status value instead of being folded into 'Unqualified' with a
-- notes marker. The value is appended (not inserted mid-list) so existing
-- ENUM ordinals — and every row already stored — are untouched.
--
-- Routing behavior is unchanged by this migration alone: 'Needs Review'
-- is NOT added to any eligible/blocked/terminal list
-- (SequenceManager::ELIGIBLE_LEAD_STATUSES, SendGateAction::BLOCKED_STATUSES,
-- FollowUpTimingAction::TERMINAL_STATUSES); that belongs to the routing
-- subject.
--
-- Idempotent: re-applying the identical MODIFY COLUMN is a semantic
-- no-op — MySQL/MariaDB accept it without error and the column
-- definition, ordinals, and stored rows are unchanged. The acceptance
-- gate is tests/needs_review/test_migration_idempotency.php, which
-- applies this file twice to a scratch DB.
--
-- Splitter-safe by design: the repo's migration test harness applies
-- this file by stripping '--' lines and exploding on ';', so this file
-- deliberately uses NO stored procedures and NO DELIMITER blocks — every
-- ';'-terminated chunk must be a standalone statement.
-- ------------------------------------------------------------------
ALTER TABLE `leads` MODIFY COLUMN `status`
    ENUM('New','Enriched','Contacted','Qualified','Unqualified','Converted','Drafted','Needs Review')
    DEFAULT 'New';
