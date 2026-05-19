-- Database Schema for B2B Outreach Tool (LAMP Stack)

-- Campaigns table: Stores outreach sequences
CREATE TABLE IF NOT EXISTS campaigns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Leads table: Stores prospect information
CREATE TABLE IF NOT EXISTS leads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    contact_name VARCHAR(255),
    email VARCHAR(255) UNIQUE NOT NULL,
    website VARCHAR(255),
    status ENUM('New', 'Enriched', 'Contacted', 'Qualified', 'Unqualified', 'Converted', 'Drafted') DEFAULT 'New',
    lead_score INT DEFAULT 0,
    campaign_id INT NULL,
    source VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE SET NULL,
    INDEX idx_status_score (status, lead_score),
    INDEX idx_email (email),
    INDEX idx_campaign_id (campaign_id)
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
('firecrawl_api_key_backup', ''),
('browserless_api_key', ''),
('zenrows_api_key', ''),
('vercel_bridge_url', ''),
('scrapingant_api_key', ''),
('scrapingant_api_key_backup', ''),
('searxng_url', 'http://localhost:8080/search'),
('active_search_provider', 'searxng'),
('fallback_search_provider', 'searxng'),
('failover_threshold', '3'),
('search_consecutive_failures', '0');

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

-- API Usage Logs: Audits/logs all external API requests (Search, AI, Email) for rotation & free-tier quota limits
CREATE TABLE IF NOT EXISTS api_usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(50) NOT NULL COMMENT 'e.g. tavily, exa, scrapingant, gemini, brevo',
    api_key_masked VARCHAR(100) NOT NULL COMMENT 'Masked API key (or key index) to count usage per key',
    status ENUM('success', 'failed') DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    timestamp INT NOT NULL,
    INDEX idx_service_key_time (service_name, api_key_masked, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

