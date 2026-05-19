<?php
/**
 * Leads API Endpoint
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
$pdo = \App\Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'list';
        try {
            if ($action === 'traces') {
                $id = isset($_GET['id']) ? (int)$_GET['id'] : null;
                if (!$id) throw new Exception("Missing Lead ID");
                
                $stmt = $pdo->prepare("SELECT * FROM agent_traces WHERE lead_id = ? ORDER BY created_at DESC");
                $stmt->execute([$id]);
                $traces = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $traces]);
                break;
            }

            // List leads
            $limit = isset($_GET['limit']) ? min((int)$_GET['limit'], 100) : 10;
            $stmt = $pdo->prepare("SELECT leads.*, campaigns.name AS campaign_name FROM leads LEFT JOIN campaigns ON leads.campaign_id = campaigns.id ORDER BY leads.created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            $leads = $stmt->fetchAll();
            echo json_encode([
                'success' => true, 
                'data' => $leads,
                'meta' => [
                    'count' => count($leads),
                    'timestamp' => date('c')
                ]
            ]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    case 'POST':
        $action = $_GET['action'] ?? 'create';
        $input = json_decode(file_get_contents('php://input'), true);

        try {
            if ($action === 'delete') {
                $id = $_GET['id'] ?? $input['id'] ?? null;
                if (!$id) throw new Exception("Missing ID for deletion");
                
                $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Lead deleted']);
                break;
            }

            if ($action === 'update') {
                if (empty($input['id'])) throw new Exception("Missing ID for update");
                
                $stmt = $pdo->prepare("UPDATE leads SET company_name = ?, contact_name = ?, email = ?, campaign_id = ? WHERE id = ?");
                $campaignId = !empty($input['campaign_id']) ? (int)$input['campaign_id'] : null;
                $stmt->execute([
                    $input['company_name'],
                    $input['contact_name'] ?? '',
                    $input['email'],
                    $campaignId,
                    $input['id']
                ]);
                echo json_encode(['success' => true, 'message' => 'Lead updated']);
                break;
            }

            if ($action === 'analyze') {
                $id = $_GET['id'] ?? $input['id'] ?? null;
                if (!$id) throw new Exception("Missing ID for analysis");

                // 1. Extraction
                require_once __DIR__ . '/../includes/Agents/ExtractionExpert.php';
                $expert = new \App\Agents\ExtractionExpert($pdo);
                $extractResult = $expert->processLead($id);

                // 2. Intent Analysis
                require_once __DIR__ . '/../includes/Agents/IntentAnalyst.php';
                $analyst = new \App\Agents\IntentAnalyst($pdo);
                $analysisResult = $analyst->analyze($id, $extractResult);

                echo json_encode([
                    'success' => true,
                    'extraction' => $extractResult,
                    'analysis' => $analysisResult
                ]);
                break;
            }

            if ($action === 'draft') {
                $id = $_GET['id'] ?? $input['id'] ?? null;
                if (!$id) throw new Exception("Missing ID for drafting");

                require_once __DIR__ . '/../includes/Agents/EmailDraftingAgent.php';
                $drafter = new \App\Agents\EmailDraftingAgent($pdo);
                $result = $drafter->draft($id);
                echo json_encode($result);
                break;
            }

            // Default: Create lead
            if (empty($input['email']) || empty($input['company_name'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                break;
            }

            $stmt = $pdo->prepare("INSERT INTO leads (company_name, contact_name, email, website, source, campaign_id) VALUES (?, ?, ?, ?, ?, ?)");
            $campaignId = !empty($input['campaign_id']) ? (int)$input['campaign_id'] : null;
            $stmt->execute([
                $input['company_name'],
                $input['contact_name'] ?? '',
                $input['email'],
                $input['website'] ?? '',
                $input['source'] ?? 'API',
                $campaignId
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        break;
}
?>
