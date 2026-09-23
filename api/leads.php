<?php
/**
 * Leads API Endpoint
 *
 * Actions (all require auth via \App\Auth::requireApiAuth):
 *   GET  api/leads.php                          list (limit, offset, search)
 *   GET  api/leads.php?action=traces&id=ID       agent traces for a lead
 *   POST api/leads.php                          create lead {company_name, email, ...}
 *   POST api/leads.php?action=delete&id=ID      delete lead
 *   POST api/leads.php?action=update            update lead {id, company_name, ...}
 *   POST api/leads.php?action=update_status     set status {id, status}
 *   POST api/leads.php?action=analyze&id=ID     run extraction/intent analysis
 *   POST api/leads.php?action=draft&id=ID       generate outreach draft
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
$pdo = \App\Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

function leads_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    /* ── READ ─────────────────────────────────────────── */
    if ($method === 'GET') {
        if ($action === 'traces') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) leads_error(400, 'Missing lead id');
            $stmt = $pdo->prepare("SELECT * FROM agent_traces WHERE lead_id = ? ORDER BY created_at DESC");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            exit;
        }

        // List leads (limit/offset/search)
        $limit  = isset($_GET['limit'])  ? min(max((int)$_GET['limit'], 1), 100) : 10;
        $offset = isset($_GET['offset']) ? max((int)$_GET['offset'], 0) : 0;
        $search = trim($_GET['search'] ?? '');

        $where = '';
        $params = [];
        if ($search !== '') {
            $where = "WHERE company_name LIKE ? OR contact_name LIKE ? OR email LIKE ? OR website LIKE ?";
            $like = "%{$search}%";
            $params = [$like, $like, $like, $like];
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM leads {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // $limit/$offset are cast ints — safe to interpolate (avoids LIMIT-placeholder issues)
        $stmt = $pdo->prepare("SELECT * FROM leads {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $leads = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data'    => $leads,
            'meta'    => [
                'count'     => count($leads),
                'total'     => $total,
                'limit'     => $limit,
                'offset'    => $offset,
                'timestamp' => date('c'),
            ],
        ]);
        exit;
    }

    /* ── WRITE ────────────────────────────────────────── */
    if ($method === 'POST') {
        // Delete a lead
        if ($action === 'delete') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) leads_error(400, 'Missing lead id');
            $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) leads_error(404, 'Lead not found');
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        // Update a lead (whitelist of real columns — never trust client field names)
        if ($action === 'update') {
            $id = (int)($input['id'] ?? 0);
            if ($id <= 0) leads_error(400, 'Missing lead id');
            $allowed = ['company_name', 'contact_name', 'email', 'website', 'status', 'notes', 'lead_score', 'source'];
            $set = [];
            $vals = [];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $input)) {
                    $set[] = "{$col} = ?";
                    $vals[] = $input[$col];
                }
            }
            if (!$set) leads_error(400, 'Nothing to update');
            $vals[] = $id;
            $stmt = $pdo->prepare("UPDATE leads SET " . implode(', ', $set) . " WHERE id = ?");
            $stmt->execute($vals);
            echo json_encode(['success' => true, 'id' => $id]);
            exit;
        }

        // Change a lead's status
        if ($action === 'update_status') {
            $id = (int)($input['id'] ?? 0);
            $status = trim($input['status'] ?? '');
            $valid = ['New', 'Enriched', 'Contacted', 'Qualified', 'Unqualified', 'Converted'];
            if ($id <= 0 || !in_array($status, $valid, true)) {
                leads_error(400, 'Missing or invalid id/status');
            }
            $stmt = $pdo->prepare("UPDATE leads SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            echo json_encode(['success' => true, 'id' => $id, 'status' => $status]);
            exit;
        }

        // Run extraction + intent analysis on a lead
        if ($action === 'analyze') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) leads_error(400, 'Missing lead id');
            $agent = new \App\Agents\ExtractionExpert($pdo);
            $result = $agent->processLead($id);
            if (empty($result['success'])) http_response_code(502);
            echo json_encode($result);
            exit;
        }

        // Generate an outreach draft for a lead
        if ($action === 'draft') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) leads_error(400, 'Missing lead id');
            $agent = new \App\Agents\EmailDraftingAgent($pdo);
            $result = $agent->draft($id);
            if (empty($result['success'])) http_response_code(502);
            echo json_encode($result);
            exit;
        }

        // Create a lead (default POST)
        if (empty($input['email']) || empty($input['company_name'])) {
            leads_error(400, 'Missing required fields');
        }
        $stmt = $pdo->prepare("INSERT INTO leads (company_name, contact_name, email, website, source) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $input['company_name'],
            $input['contact_name'] ?? '',
            $input['email'],
            $input['website'] ?? '',
            $input['source'] ?? 'API',
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    leads_error(405, 'Method not allowed');
} catch (Exception $e) {
    error_log('leads api error: ' . $e->getMessage());
    leads_error(500, 'Server error');
}
