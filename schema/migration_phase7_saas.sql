-- =========================================================================
-- MIGRATION PHASE 7: SaaS Multi-Tenant & Advanced Lead Verification
-- =========================================================================

-- 1. SaaS Multi-Tenant Users Table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Update Campaigns to support User Isolation
ALTER TABLE campaigns ADD COLUMN user_id INT DEFAULT NULL AFTER id;
ALTER TABLE campaigns ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- 3. Update Leads to support User Isolation & Advanced Verification
ALTER TABLE leads ADD COLUMN user_id INT DEFAULT NULL AFTER id;
ALTER TABLE leads ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE leads 
ADD COLUMN trust_score INT DEFAULT 0 AFTER lead_score,
ADD COLUMN trust_breakdown JSON DEFAULT NULL AFTER trust_score,
ADD COLUMN verification_status ENUM('unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard','unknown','valid','invalid','risky') NOT NULL DEFAULT 'unknown' AFTER status,
-- ITEM B (2026-09-24): ENUM widened to the union of bulk-verify/gate verdicts
-- and TrustScorer tiers; see migrations/2026-09-24-itemb-enum-align.sql.
ADD COLUMN mx_records TEXT DEFAULT NULL AFTER notes,
ADD COLUMN tech_stack TEXT DEFAULT NULL AFTER mx_records;

-- 4. Enable Tenant-Specific Settings (Overrides global defaults)
CREATE TABLE IF NOT EXISTS user_settings (
    user_id INT,
    setting_key VARCHAR(100),
    setting_value TEXT,
    PRIMARY KEY (user_id, setting_key),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Outbound Proxy Registry (Client-Only Usage)
CREATE TABLE IF NOT EXISTS user_proxies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    ip VARCHAR(50) NOT NULL,
    port INT NOT NULL,
    username VARCHAR(100) DEFAULT NULL,
    password VARCHAR(100) DEFAULT NULL,
    protocol ENUM('http', 'socks4', 'socks5') DEFAULT 'http',
    status ENUM('active', 'dead') DEFAULT 'active',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY idx_user_ip_port (user_id, ip, port)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Add Indexes to boost background queue efficiency
CREATE INDEX idx_leads_verification ON leads(verification_status, trust_score);
CREATE INDEX idx_leads_tenant ON leads(user_id);
CREATE INDEX idx_campaigns_tenant ON campaigns(user_id);

-- 7. Insert new settings keys for B2B Verification
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
('dns_check_timeout', '5.0'),
('evidence_fresh_days', '45'),
('export_min_confidence', '70'),
('discovery_strict_geo', '0.8'),
('discovery_strict_intent', '0.6'),
('proxy_enabled', 'false'),
('proxy_socks_url', 'https://raw.githubusercontent.com/TheSpeedX/SOCKS-List/master/http.txt'),
('proxy_verify_url', 'http://httpbin.org/ip'),
('email_daily_limit', '100'),
('email_sender', 'onboarding@resend.dev'),
('llm_default_mode', 'router'),
('openrouter_model', 'google/gemini-2.0-flash-exp:free');
