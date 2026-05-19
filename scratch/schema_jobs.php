<?php
require_once __DIR__ . '/../includes/autoload.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/PDO.php';

use App\Database;

try {
    $pdo = Database::getConnection();
    
    // Create jobs table
    $sqlJobs = "
    CREATE TABLE IF NOT EXISTS jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL, -- e.g., 'harvest'
        payload JSON NOT NULL, -- The search query and provider
        status VARCHAR(20) DEFAULT 'pending', -- pending, processing, completed, failed
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    
    // Create job_logs table
    $sqlLogs = "
    CREATE TABLE IF NOT EXISTS job_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        message TEXT,
        level VARCHAR(20) DEFAULT 'info', -- info, error, success
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";

    $pdo->exec($sqlJobs);
    echo "Table 'jobs' created or already exists.\n";
    
    $pdo->exec($sqlLogs);
    echo "Table 'job_logs' created or already exists.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
