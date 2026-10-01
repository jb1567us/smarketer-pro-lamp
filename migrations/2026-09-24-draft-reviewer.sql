-- Phase 2 (2026-09-24): draft reviewer loop — visible drafts table.
-- Every generated draft is persisted here (not just appended to lead notes),
-- so drafts are reviewable in the lead drawer and the JEV reviewer has a
-- stable record to accept / send back for revision / escalate to a human.
CREATE TABLE IF NOT EXISTS drafts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NOT NULL,
    campaign_id INT NOT NULL,
    template_id INT NULL,
    subject VARCHAR(500) NOT NULL DEFAULT '',
    body MEDIUMTEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending_review',
    reviewer_notes TEXT NULL,
    attempts TINYINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_drafts_lead (lead_id),
    INDEX idx_drafts_status (status)
) ENGINE=InnoDB;
-- status: pending_review | approved | needs_human | superseded
--   pending_review: draft written, awaiting (JEV or human) review
--   approved:       reviewer accepted the draft
--   needs_human:    escalated — reviewer rejected after max revisions,
--                   reviewer errored/timed out (fail-closed), or JEV disabled
--   superseded:    replaced by a newer revision after reviewer feedback
