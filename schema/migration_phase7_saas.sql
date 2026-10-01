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
-- UNIFIED (2026-10-01): guarded — MariaDB's native IF NOT EXISTS for
-- columns; FK added only when the (table, column, referenced table) link
-- is absent from KEY_COLUMN_USAGE, so re-running never fatals.
ALTER TABLE campaigns ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL AFTER id;

-- 3. Update Leads to support User Isolation & Advanced Verification
ALTER TABLE leads ADD COLUMN IF NOT EXISTS user_id INT DEFAULT NULL AFTER id;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS trust_score INT DEFAULT 0 AFTER lead_score;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS trust_breakdown JSON DEFAULT NULL AFTER trust_score;
-- Canonical verification_status: A's 9-value union ENUM DEFAULT 'unknown'.
-- If the column already exists (e.g. an older VARCHAR/5-value ENUM), leave
-- it alone here — migrations/2026-09-24-itemb-enum-align.sql converges it
-- (guarded, with value sanitization).
ALTER TABLE leads ADD COLUMN IF NOT EXISTS verification_status ENUM('unverified','evidence_backed','dns_confirmed','cross_source_matched','gold_standard','unknown','valid','invalid','risky') NOT NULL DEFAULT 'unknown' AFTER status;
-- ITEM B (2026-09-24): ENUM widened to the union of bulk-verify/gate verdicts
-- and TrustScorer tiers; see migrations/2026-09-24-itemb-enum-align.sql.
ALTER TABLE leads ADD COLUMN IF NOT EXISTS mx_records TEXT DEFAULT NULL AFTER notes;
ALTER TABLE leads ADD COLUMN IF NOT EXISTS tech_stack TEXT DEFAULT NULL AFTER mx_records;

DELIMITER $$
DROP PROCEDURE IF EXISTS phase7_fk_guard$$
CREATE PROCEDURE phase7_fk_guard()
BEGIN
    -- campaigns.user_id -> users(id)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns'
          AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'
    ) AND EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'user_id'
    ) AND EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    ) THEN
        ALTER TABLE campaigns ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;
    -- leads.user_id -> users(id)
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads'
          AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'
    ) AND EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'user_id'
    ) AND EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
    ) THEN
        ALTER TABLE leads ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;
END$$
DELIMITER ;
CALL phase7_fk_guard();
DROP PROCEDURE IF EXISTS phase7_fk_guard;

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
-- UNIFIED (2026-10-01): guarded on information_schema.STATISTICS — re-run safe.
DELIMITER $$
DROP PROCEDURE IF EXISTS phase7_index_guard$$
CREATE PROCEDURE phase7_index_guard()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_verification'
    ) THEN
        CREATE INDEX idx_leads_verification ON leads(verification_status, trust_score);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads' AND INDEX_NAME = 'idx_leads_tenant'
    ) THEN
        CREATE INDEX idx_leads_tenant ON leads(user_id);
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND INDEX_NAME = 'idx_campaigns_tenant'
    ) THEN
        CREATE INDEX idx_campaigns_tenant ON campaigns(user_id);
    END IF;
END$$
DELIMITER ;
CALL phase7_index_guard();
DROP PROCEDURE IF EXISTS phase7_index_guard;

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
