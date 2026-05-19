<?php
/**
 * Dashboard Stats API
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
$pdo = \App\Database::getConnection();

try {
    $stats = [];
    
    // Total Leads
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leads");
    $stats['total_leads'] = $stmt->fetch()['count'];
    
    // Active Campaigns
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM campaigns WHERE is_active = 1");
    $stats['active_campaigns'] = $stmt->fetch()['count'];
    
    // Pending Tasks
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM task_queue WHERE status = 'Pending'");
    $stats['pending_tasks'] = $stmt->fetch()['count'];
    
    // Completed/Sent today
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM task_queue WHERE status = 'Completed' AND processed_at >= CURDATE()");
    $stats['completed_today'] = $stmt->fetch()['count'];

    echo json_encode([
        'success' => true, 
        'data' => $stats,
        'meta' => ['timestamp' => date('c')]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
