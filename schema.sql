-- Database Schema for B2B Outreach Tool (LAMP Stack)

-- Leads table: Stores prospect information
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    contact_name VARCHAR(255),
    email VARCHAR(255) UNIQUE NOT NULL,
    website VARCHAR(255),
    status ENUM('New', 'Enriched', 'Contacted', 'Qualified', 'Unqualified', 'Converted', 'Drafted', 'Needs Review') DEFAULT 'New',
    lead_score INT DEFAULT 0,
    source VARCHAR(100),
    notes TEXT,
    campaign_id INT NULL,
    country_code CHAR(2) NULL,
    lawful_basis VARCHAR(50) NULL,
    consent_status ENUM('unknown','implied','express') NOT NULL DEFAULT 'unknown',
    consent_proof TEXT NULL,
    verification_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
    verified_at TIMESTAMP NULL,
    is_role_based TINYINT(1) NOT NULL DEFAULT 0,
    target_persona VARCHAR(255) NULL,
    email_source VARCHAR(50) NULL,
    source_url TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status_score (status, lead_score),
    INDEX idx_email (email),
    INDEX idx_leads_campaign (campaign_id),
    INDEX idx_leads_country (country_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Campaigns table: Stores outreach sequences
CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    paused_reason VARCHAR(255) NULL,
    paused_at TIMESTAMP NULL,
    daily_send_cap INT NULL,
    dns_preflight_override TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Templates table: Email/Message templates
CREATE TABLE IF NOT EXISTS templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT,
    subject VARCHAR(255),
    body TEXT,
    step_order INT DEFAULT 1,
    delay_days INT NOT NULL DEFAULT 3,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_campaign_order (campaign_id, step_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Phase 4: campaign sequences — enrollments, per-step sends, timeline.
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

-- Task Queue: Background tasks for cron processing
CREATE TABLE IF NOT EXISTS task_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT,
    task_type ENUM('Enrichment', 'EmailOutreach', 'SocialOutreach', 'Qualify', 'Enrich', 'Draft', 'SequenceSend') NOT NULL,
    payload JSON,
    status ENUM('Pending', 'In Progress', 'Completed', 'Failed') DEFAULT 'Pending',
    scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    retry_count INT DEFAULT 0,
    error_message TEXT,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_status_scheduled_type (status, scheduled_at, task_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cron process locks (single-flight guard so overlapping cron runs
-- can never double-process the queue)
CREATE TABLE IF NOT EXISTS cron_locks (
    lock_name VARCHAR(100) PRIMARY KEY,
    locked_at TIMESTAMP NULL,
    pid INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- System Settings
CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(100) PRIMARY KEY,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
('tavily_api_key', ''),
('exa_api_key', ''),
('firecrawl_api_key', ''),
('browserless_api_key', ''),
('zenrows_api_key', ''),
('vercel_bridge_url', ''),
('scrapingant_api_key', ''),
('searxng_url', 'http://localhost:8080/search'),
('active_search_provider', 'searxng');

-- Influencers: Social media candidates
CREATE TABLE IF NOT EXISTS influencers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255),
    platform VARCHAR(50) NOT NULL,
    handle VARCHAR(100) NOT NULL,
    url VARCHAR(255),
    follower_count INT DEFAULT 0,
    engagement_rate DECIMAL(5,2),
    status ENUM('New', 'Vetted', 'Contacted', 'Rejected') DEFAULT 'New',
    tags TEXT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_platform_handle (platform, handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agent Traces: Stores reasoning history (mirroring Golden Master NL mapping)
CREATE TABLE IF NOT EXISTS agent_traces (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT NULL,
    persona VARCHAR(100),
    goal TEXT,
    context TEXT,
    reasoning_output TEXT,
    operational_mode ENUM('Production', 'Simulation') DEFAULT 'Production',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_lead_persona (lead_id, persona)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email Logs: Tracks sending history for Rate Limiting
CREATE TABLE IF NOT EXISTS email_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_email VARCHAR(255) NOT NULL,
    provider_id VARCHAR(50) NOT NULL,
    provider_msg_id VARCHAR(255),
    campaign_id INT NULL,
    status ENUM('sent', 'failed', 'queued', 'bounced') DEFAULT 'sent',
    metadata_json TEXT,
    timestamp INT NOT NULL,
    INDEX idx_provider_time (provider_id, timestamp),
    INDEX idx_email_campaign (campaign_id, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- API Usage Logs: Per-key daily usage for the Provider Quota & Health Monitor
-- (written by SmartRotationManager::logCall, read by api/quota_status.php)
CREATE TABLE IF NOT EXISTS api_usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(100) NOT NULL,
    api_key_masked VARCHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL,
    error_message TEXT,
    timestamp INT NOT NULL,
    INDEX idx_service_time (service_name, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email suppression list: opt-outs, bounces, complaints. Checked on every send.
-- GDPR redesign (item 5): email is NULLABLE (erasure removes plaintext PII);
-- email_hash keeps a keyed hash so "do not contact" survives erasure.
CREATE TABLE IF NOT EXISTS suppression_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NULL,
    email_hash VARCHAR(64) NULL,
    reason VARCHAR(50) NOT NULL DEFAULT 'unsubscribe',
    source VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppression_email (email),
    UNIQUE KEY uq_suppression_hash (email_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Webhook events: raw inbound bounce/complaint events (items 1-2).
-- Canonical DDL mirrors ComplaintHandler::TABLE_DDL exactly.
CREATE TABLE IF NOT EXISTS webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(50) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    email VARCHAR(255) NOT NULL DEFAULT '',
    payload_json TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_we_email (email),
    INDEX idx_we_provider_event (provider, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DNS preflight cache: SPF/DKIM/DMARC check results per domain (item 6).
CREATE TABLE IF NOT EXISTS domain_auth_cache (
    domain VARCHAR(255) NOT NULL PRIMARY KEY,
    result_json TEXT NOT NULL,
    checked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- CASL audit log: every recipient-country gate decision (item 8).
CREATE TABLE IF NOT EXISTS casl_decisions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    country_code CHAR(2) NULL,
    decision ENUM('allow','block') NOT NULL,
    rule VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_casl_email (email),
    INDEX idx_casl_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Weighted ICP scoring model (2026-09-28). Structured ICP data: profiles +
-- six weighted dimensions + hard veto exclusions + weight-adjustment audit.
-- Mirrors migrations/2026-09-28-icp-scoring.sql.

-- ICP profiles: multiple allowed, exactly one active at a time.
CREATE TABLE IF NOT EXISTS icp_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    pain_statement TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_icp_profile_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The six scoring dimensions with their weights and targets.
CREATE TABLE IF NOT EXISTS icp_dimensions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_id INT NOT NULL,
    dimension_key VARCHAR(48) NOT NULL,
    weight INT NOT NULL DEFAULT 0,
    buyer_locked TINYINT(1) NOT NULL DEFAULT 0,
    target_config JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_icp_dimension (profile_id, dimension_key),
    CONSTRAINT fk_icp_dimension_profile FOREIGN KEY (profile_id)
        REFERENCES icp_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- dimension_key: company_size | industry_fit | tech_stack | target_title |
--                geography | trigger_signals
-- buyer_locked: 1 once a human edits the weight by hand; auto-tuning skips
-- locked dimensions.

-- Anti-persona: hard veto list. Any match disqualifies the lead outright.
CREATE TABLE IF NOT EXISTS icp_exclusions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_id INT NOT NULL,
    exclusion_type VARCHAR(32) NOT NULL,
    value VARCHAR(255) NOT NULL,
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_icp_exclusion (profile_id, exclusion_type, value),
    CONSTRAINT fk_icp_exclusion_profile FOREIGN KEY (profile_id)
        REFERENCES icp_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- exclusion_type: industry | company | domain | title | keyword

-- Audit trail for weight adjustments (manual edits and auto-tuning).
CREATE TABLE IF NOT EXISTS icp_weight_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    profile_id INT NOT NULL,
    dimension_key VARCHAR(48) NOT NULL,
    old_weight INT NOT NULL,
    new_weight INT NOT NULL,
    reason VARCHAR(255) NULL,
    sample_size INT NULL,
    created_by VARCHAR(64) NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_icp_weight_hist (profile_id, dimension_key, created_at),
    CONSTRAINT fk_icp_weight_hist_profile FOREIGN KEY (profile_id)
        REFERENCES icp_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- created_by: user | auto_tuner

-- Seed: one active default profile, equal-ish weights summing to 100, empty
-- targets / exclusions.
INSERT IGNORE INTO icp_profiles (id, name, pain_statement, is_active)
VALUES (1, 'Default ICP', '', 1);

INSERT IGNORE INTO icp_dimensions (profile_id, dimension_key, weight, buyer_locked, target_config)
VALUES
    (1, 'company_size',   17, 0, '{"min_employees":null,"max_employees":null}'),
    (1, 'industry_fit',   17, 0, '{"include":[],"exclude":[]}'),
    (1, 'tech_stack',     17, 0, '{"keywords":[]}'),
    (1, 'target_title',   17, 0, '{"titles":[]}'),
    (1, 'geography',      16, 0, '{"countries":[],"regions":[]}'),
    (1, 'trigger_signals',16, 0, '{"signals":[]}');

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('icp_threshold_qualify', '75'),
    ('icp_threshold_review', '50');
