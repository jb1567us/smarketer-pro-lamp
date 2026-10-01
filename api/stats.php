<?php
/**
 * Dashboard Stats API
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
$pdo = \App\Database::getConnection();

try {
    $stats = [];
    
    // Total Leads
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leads");
    $stats['total_leads'] = $stmt->fetch()['count'];
    
    // Qualified Leads
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leads WHERE status = 'Qualified'");
    $stats['qualified'] = $stmt->fetch()['count'];

    // Contacted Leads
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leads WHERE status = 'Contacted'");
    $stats['contacted'] = $stmt->fetch()['count'];

    // Converted Leads
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM leads WHERE status = 'Converted'");
    $stats['converted'] = $stmt->fetch()['count'];

    // Active Campaigns
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM campaigns WHERE is_active = 1");
    $stats['active_campaigns'] = $stmt->fetch()['count'];
    
    // Pending Tasks
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM task_queue WHERE status = 'Pending'");
    $stats['pending_tasks'] = $stmt->fetch()['count'];
    
    // Completed/Sent today
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM task_queue WHERE status = 'Completed' AND processed_at >= CURDATE()");
    $stats['completed_today'] = $stmt->fetch()['count'];

    // FIX2 verification funnel: honest data-quality stages. Total raw
    // harvested, actually-verified counts (valid/invalid/risky/unknown),
    // suppressed-excluded, and mailable = verified valid AND not suppressed.
    // Degrades to zeros when the verification columns/tables are absent.
    foreach (\App\FunnelStats::compute($pdo) as $key => $value) {
        $stats['funnel_' . $key] = $value;
    }

    // Compliance item 4: campaigns auto-paused by the complaint/bounce
    // monitor. The dashboard polls this endpoint, so a banner can read
    // data.paused_campaigns; the campaigns list (SELECT *) also carries the
    // status/paused_reason/paused_at columns once the item-4 DDL is applied.
    // Degrades to an empty list when the pause columns are not installed.
    $stats['paused_campaigns'] = [];
    try {
        $col = $pdo->query(
            "SELECT COUNT(*) AS c FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'status'"
        )->fetch();
        if ($col && (int)$col['c'] > 0) {
            $stmt = $pdo->query(
                "SELECT id, name, paused_reason, paused_at FROM campaigns WHERE status = 'paused' ORDER BY paused_at DESC"
            );
            $stats['paused_campaigns'] = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log('[stats.php] paused_campaigns lookup failed: ' . $e->getMessage());
    }

    // Phase 4: per-campaign sequence stats (enrollments, sends, opens,
    // replies, stops). Degrades to an empty list when the Phase-4 tables
    // are not installed.
    $stats['campaign_sequences'] = [];
    try {
        $stats['campaign_sequences'] = \App\SequenceManager::campaignStats($pdo);
    } catch (Exception $e) {
        error_log('[stats.php] campaign_sequences lookup failed: ' . $e->getMessage());
    }

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
