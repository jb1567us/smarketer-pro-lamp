-- Database Schema for B2B Outreach Tool (LAMP Stack)

-- Leads table: Stores prospect information
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    contact_name VARCHAR(255),
    email VARCHAR(255) UNIQUE NOT NULL,
    website VARCHAR(255),
    status ENUM('New', 'Enriched', 'Contacted', 'Qualified', 'Unqualified', 'Converted') DEFAULT 'New',
    lead_score INT DEFAULT 0,
    source VARCHAR(100),
    notes TEXT,
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
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Campaigns table: Stores outreach sequences
CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Templates table: Email/Message templates
CREATE TABLE IF NOT EXISTS templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT,
    subject VARCHAR(255),
    body TEXT,
    step_order INT DEFAULT 1,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
    INDEX idx_campaign_order (campaign_id, step_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Task Queue: Background tasks for cron processing
CREATE TABLE IF NOT EXISTS task_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT,
    task_type ENUM('Enrichment', 'EmailOutreach', 'SocialOutreach', 'Qualify', 'Enrich', 'Draft') NOT NULL,
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
    status ENUM('sent', 'failed', 'queued') DEFAULT 'sent',
    metadata_json TEXT,
    timestamp INT NOT NULL,
    INDEX idx_provider_time (provider_id, timestamp)
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
CREATE TABLE IF NOT EXISTS suppression_list (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    reason VARCHAR(50) NOT NULL DEFAULT 'unsubscribe',
    source VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_suppression_email (email),
    INDEX idx_suppression_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
