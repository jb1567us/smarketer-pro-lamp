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

// Item 8: leads.country_code exists only after the gaps migration. Probe once
// so add/edit degrade gracefully on pre-migration databases instead of
// fataling on an unknown column. Same pattern for campaigns (item 10) and
// leads.campaign_id: the list query only LEFT JOINs when both exist.
$hasCountryCol = false;
$hasCampaigns = false;
$hasLeadCampaignCol = false;
try {
    $colStmt = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() " .
        "AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'country_code' LIMIT 1"
    );
    $colStmt->execute();
    $hasCountryCol = (bool)$colStmt->fetch();

    $tblStmt = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() " .
        "AND TABLE_NAME = 'campaigns' LIMIT 1"
    );
    $tblStmt->execute();
    $hasCampaigns = (bool)$tblStmt->fetch();

    if ($hasCampaigns) {
        $campStmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() " .
            "AND TABLE_NAME = 'leads' AND COLUMN_NAME = 'campaign_id' LIMIT 1"
        );
        $campStmt->execute();
        $hasLeadCampaignCol = (bool)$campStmt->fetch();
    }
} catch (\Throwable $e) {
    $hasCountryCol = false;
    $hasCampaigns = false;
    $hasLeadCampaignCol = false;
}

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
        $whereJoined = '';
        $params = [];
        if ($search !== '') {
            $where = "WHERE company_name LIKE ? OR contact_name LIKE ? OR email LIKE ? OR website LIKE ?";
            $whereJoined = "WHERE l.company_name LIKE ? OR l.contact_name LIKE ? OR l.email LIKE ? OR l.website LIKE ?";
            $like = "%{$search}%";
            $params = [$like, $like, $like, $like];
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM leads {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // $limit/$offset are cast ints — safe to interpolate (avoids LIMIT-placeholder issues)
        // Phase 0 fix (was critical defect C5): the list now LEFT JOINs
        // campaigns so the dashboard shows the REAL campaign assignment.
        if ($hasCampaigns && $hasLeadCampaignCol) {
            $stmt = $pdo->prepare(
                "SELECT l.*, c.name AS campaign_name FROM leads l " .
                "LEFT JOIN campaigns c ON c.id = l.campaign_id " .
                "{$whereJoined} ORDER BY l.created_at DESC LIMIT {$limit} OFFSET {$offset}"
            );
        } else {
            $stmt = $pdo->prepare("SELECT * FROM leads {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}");
        }
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

            // Item 9: never store persona text in contact_name. When the caller
            // sets contact_name, route the value through the name/persona
            // mapper; persona-ish text is rescued into target_persona.
            if (array_key_exists('contact_name', $input)) {
                $personaWasProvided = array_key_exists('target_persona', $input);
                [$mappedName, $mappedPersona] = \App\LeadFields::mapContactAndPersona(
                    $input['contact_name'],
                    $input['target_persona'] ?? null
                );
                $input['contact_name'] = $mappedName;
                if ($personaWasProvided || $mappedPersona !== null) {
                    $input['target_persona'] = $mappedPersona;
                }
            }

            $allowed = ['company_name', 'contact_name', 'email', 'website', 'status', 'notes', 'lead_score', 'source', 'target_persona'];
            if ($hasCountryCol) { $allowed[] = 'country_code'; }
            // Phase 0 fix (was critical defect C5): campaign_id was silently
            // dropped on update even though the UI sends it.
            if ($hasLeadCampaignCol) { $allowed[] = 'campaign_id'; }
            $set = [];
            $vals = [];
            foreach ($allowed as $col) {
                if (array_key_exists($col, $input)) {
                    // Item 8: country_code is validated to ISO-3166-1 alpha-2; invalid → NULL (unknown)
                    if ($col === 'country_code') {
                        $vals[] = \App\Compliance::normalizeCountryCode($input[$col]);
                    } elseif ($col === 'campaign_id') {
                        // '' / 0 → NULL (unassign); otherwise a real campaign id.
                        $cid = (int)$input[$col];
                        $vals[] = $cid > 0 ? $cid : null;
                    } else {
                        $vals[] = $input[$col];
                    }
                    $set[] = "{$col} = ?";
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
            $valid = ['New', 'Enriched', 'Contacted', 'Qualified', 'Unqualified', 'Converted', 'Drafted'];
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

        // Generate an outreach draft for a lead.
        // Phase 0 fix (was critical defect C4): this used to route through
        // EmailDraftingAgent's two hardcoded templates ("not AI"). It now
        // runs the real LLM draft action, scoped to the lead's own campaign.
        if ($action === 'draft') {
            $id = (int)($_GET['id'] ?? 0);
            if ($id <= 0) leads_error(400, 'Missing lead id');
            try {
                $drafter = new \App\Actions\DraftOutreachAction(
                    $pdo,
                    new \App\Routers\SmartLLMRouter($pdo)
                );
                $draft = $drafter->buildDraft($id);
            } catch (\App\Exceptions\OutreachException $e) {
                // Buyer-actionable failures (no campaign, no templates, LLM
                // error): 502 with the message, not a bare 500.
                http_response_code(502);
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
            echo json_encode([
                'success' => true,
                'subject' => $draft['subject'],
                'body' => $draft['body'],
                'campaign_id' => $draft['campaign_id'],
                'campaign_name' => $draft['campaign_name'],
            ]);
            exit;
        }

        // Create a lead (default POST)
        if (empty($input['email']) || empty($input['company_name'])) {
            leads_error(400, 'Missing required fields');
        }
        // Item 9: persona text goes to target_persona only; contact_name is set
        // solely from an actual person name, else NULL.
        [$contactName, $targetPersona] = \App\LeadFields::mapContactAndPersona(
            $input['contact_name'] ?? null,
            $input['target_persona'] ?? null
        );
        $leadCols = ['company_name', 'contact_name', 'email', 'website', 'source', 'target_persona', 'notes'];
        $leadVals = [
            $input['company_name'],
            $contactName,
            $input['email'],
            $input['website'] ?? '',
            $input['source'] ?? 'API',
            $targetPersona,
            // Phase 0 fix: the add-lead form sends notes but they were dropped.
            trim((string)($input['notes'] ?? '')),
        ];
        if ($hasCountryCol) {
            $leadCols[] = 'country_code';
            // Item 8: recipient country (ISO-3166-1 alpha-2); invalid → NULL (unknown)
            $leadVals[] = \App\Compliance::normalizeCountryCode($input['country_code'] ?? null);
        }
        // Phase 0 fix (was critical defect C5): the add-lead form sends
        // campaign_id but it was silently dropped on create.
        if ($hasLeadCampaignCol) {
            $leadCols[] = 'campaign_id';
            $cid = (int)($input['campaign_id'] ?? 0);
            $leadVals[] = $cid > 0 ? $cid : null;
        }
        $placeholders = implode(', ', array_fill(0, count($leadCols), '?'));
        $stmt = $pdo->prepare("INSERT INTO leads (" . implode(', ', $leadCols) . ") VALUES ({$placeholders})");
        $stmt->execute($leadVals);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        exit;
    }

    leads_error(405, 'Method not allowed');
} catch (Exception $e) {
    error_log('leads api error: ' . $e->getMessage());
    leads_error(500, 'Server error');
}
