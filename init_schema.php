<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();

$sql = "
CREATE TABLE IF NOT EXISTS agent_traces (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lead_id INT,
    persona VARCHAR(100),
    goal TEXT,
    context TEXT,
    reasoning_output TEXT,
    operational_mode ENUM('Production', 'Simulation') DEFAULT 'Production',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE CASCADE,
    INDEX idx_lead_persona (lead_id, persona)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $pdo->exec($sql);
    echo "Schema updated successfully.\n";
} catch (Exception $e) {
    echo "Error updating schema: " . $e->getMessage() . "\n";
}
