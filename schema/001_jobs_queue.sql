-- =============================================================================
-- Migration 001: Job Queue System
-- Run this SQL on your shared hosting MySQL (phpMyAdmin or cPanel SQL runner).
-- =============================================================================

CREATE TABLE IF NOT EXISTS jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL COMMENT 'e.g. harvest, extract, enrich',
    payload JSON NOT NULL COMMENT 'Serialized task data (query, provider, etc.)',
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    result JSON DEFAULT NULL COMMENT 'Stored results on completion',
    error_message TEXT DEFAULT NULL,
    attempts INT DEFAULT 0,
    max_attempts INT DEFAULT 3,
    priority INT DEFAULT 0 COMMENT 'Higher = processed first',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_at TIMESTAMP NULL DEFAULT NULL,
    completed_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status_priority (status, priority DESC),
    INDEX idx_type (type),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    level ENUM('info', 'warn', 'error', 'success') DEFAULT 'info',
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
    INDEX idx_job_id (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
