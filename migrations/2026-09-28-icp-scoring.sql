-- Weighted ICP scoring model (2026-09-28, subject agent 1/4).
--
-- Structured ICP data: profiles + six weighted dimensions + hard veto
-- exclusions + an audit trail for weight adjustments. The scorer itself
-- (subject agent 2/3) reads these tables; the weight auto-tuner (subject
-- agent 3) writes adjustment rows into icp_weight_history.
--
-- All statements are idempotent (safe to re-run on a migrated install).

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
-- weight: 0-100 per dimension; the set of weights on one profile must sum to 100
-- (enforced in App\Icp\IcpProfile::updateWeights, not in the DB).
-- buyer_locked: 1 once a human edits the weight by hand; auto-tuning skips
-- locked dimensions. The settings UI offers an unlock per dimension.
-- dimension_key: company_size | industry_fit | target_title | geography |
--                trigger_signals
-- (tech_stack was retired 2026-09-29; lead-enrichment keeps its own
-- tech_stack *field*, which is observed lead data, not a scoring dimension.)

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

-- Seed: one active default profile with equal-ish weights summing to 100 and
-- empty targets / exclusions. The buyer refines them from the settings UI.
INSERT IGNORE INTO icp_profiles (id, name, pain_statement, is_active)
VALUES (1, 'Default ICP', '', 1);

INSERT IGNORE INTO icp_dimensions (profile_id, dimension_key, weight, buyer_locked, target_config)
VALUES
    (1, 'company_size',   20, 0, '{"min_employees":null,"max_employees":null}'),
    (1, 'industry_fit',   20, 0, '{"include":[],"exclude":[]}'),
    (1, 'target_title',   20, 0, '{"titles":[]}'),
    (1, 'geography',      20, 0, '{"countries":[],"regions":[]}'),
    (1, 'trigger_signals',20, 0, '{"signals":[]}');

-- Scoring thresholds as settings rows: auto-qualify >= 75, human review
-- 50-75, disqualified < 50.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('icp_threshold_qualify', '75'),
    ('icp_threshold_review', '50');
