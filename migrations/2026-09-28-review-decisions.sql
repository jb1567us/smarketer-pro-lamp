-- ------------------------------------------------------------------
-- Human review decisions audit trail (goal_67693fcbba4c, review workflow).
--
-- Leads scoring in the 50-75 weighted-fit band land in leads.status =
-- 'Needs Review' (ENUM value added by
-- migrations/2026-09-28-needs-review-enum.sql; the review API and UI only
-- ever READ that value, they never write it). A human reviewer approves
-- (--> 'Qualified') or disqualifies (--> 'Unqualified') each lead from
-- api/review.php / review_queue.php.
--
-- Every approve/disqualify writes exactly one row here, transactionally
-- with the leads.status change (see App\ReviewQueue::transition). The row
-- captures who decided, when, what the status was before, and a fit_score
-- snapshot, so a decision is always auditable and never a blind write.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS — re-running is a clean no-op.
-- ------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS review_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    decision ENUM('approved', 'disqualified') NOT NULL,
    decided_by VARCHAR(255) NOT NULL,
    decided_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    previous_status VARCHAR(32) NOT NULL,
    fit_score_snapshot INT NULL,
    note TEXT NULL,
    INDEX idx_review_decision_lead (lead_id),
    INDEX idx_review_decision_time (decided_at),
    CONSTRAINT fk_review_decision_lead FOREIGN KEY (lead_id)
        REFERENCES leads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
