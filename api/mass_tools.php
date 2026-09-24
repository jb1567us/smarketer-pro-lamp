<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
$pdo = \App\Database::getConnection();
require_once '../includes/SimpleHarvester.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if ($action === 'harvest') {
        $keywords = explode("\n", $input['keywords'] ?? '');
        $singleQuery = $input['query'] ?? null;
        $useQueue = $input['async'] ?? false;
        $campaignId = !empty($input['campaign_id']) ? (int)$input['campaign_id'] : null;
        $leadPersona = !empty($input['lead_persona']) ? trim($input['lead_persona']) : '';
        
        // Save provider selection if present
        if (isset($input['provider'])) {
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_search_provider', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$input['provider'], $input['provider']]);
        }

        // ---- ASYNC MODE: Insert into job queue ----
        if ($useQueue) {
            $jobIds = [];
            $queries = $singleQuery ? [$singleQuery] : array_filter(array_map('trim', $keywords));
            
            foreach ($queries as $q) {
                $stmt = $pdo->prepare("INSERT INTO jobs (type, payload, priority) VALUES ('harvest', ?, ?)");
                $stmt->execute([
                    json_encode([
                        'query' => $q,
                        'provider' => $input['provider'] ?? null,
                        'limit' => 50,
                        'campaign_id' => $campaignId,
                        'lead_persona' => $leadPersona
                    ]),
                    0
                ]);
                $jobIds[] = $pdo->lastInsertId();
            }
            
            echo json_encode([
                'success' => true, 
                'mode' => 'queued', 
                'job_ids' => $jobIds,
                'message' => count($jobIds) . ' job(s) queued. They will be processed by the cron worker.'
            ]);
            exit;
        }

        // ---- SYNC MODE: Process immediately ----
        $harvester = new SimpleHarvester();
        $results = [];
        $errors = [];

        // Item 4: score harvest results with the real TrustScorer before
        // staging/returning. TrustScorer is a pure function (microseconds);
        // only the per-domain DNS probe is network I/O, cached per batch and
        // fail-soft, with a settings kill-switch for broken resolvers.
        $harvestDnsEnabled = SimpleHarvester::isHarvestDnsEnabled($pdo);

        if ($singleQuery) {
             try {
                $results = $harvester->harvest($singleQuery, 50);
                $results = SimpleHarvester::scoreHarvestResults($results, null, $harvestDnsEnabled);
                // Stage results
                SimpleHarvester::stageResults($pdo, $results, $singleQuery, $campaignId, $leadPersona);
             } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
             }
        } else {
             $nonEmptyKeywords = array_filter(array_map('trim', $keywords));
             foreach ($nonEmptyKeywords as $kw) {
                try {
                    $res = $harvester->harvest($kw, 10);
                    $res = SimpleHarvester::scoreHarvestResults($res, null, $harvestDnsEnabled);
                    // Stage individual batch results
                    SimpleHarvester::stageResults($pdo, $res, $kw, $campaignId, $leadPersona);
                    $results = array_merge($results, $res);
                } catch (Exception $e) {
                    $errors[] = "Error for '{$kw}': " . $e->getMessage();
                }
             }
             if (empty($results) && !empty($errors)) {
                 echo json_encode(['success' => false, 'message' => 'All queries failed.', 'errors' => $errors]);
                 exit;
             }
        }

        echo json_encode(['success' => true, 'mode' => 'sync', 'data' => $results, 'errors' => $errors]);
    }
    elseif ($action === 'extract') {
        // ---- NEW: URL Extraction endpoint ----
        $url = $input['url'] ?? '';
        if (empty($url)) {
            echo json_encode(['success' => false, 'message' => 'No URL provided.']);
            exit;
        }
        
        $result = \App\ExtractionEngine::fullExtract($url);
        echo json_encode(['success' => true, 'data' => $result]);
    }
    elseif ($action === 'generate_dorks') {
        // ---- Dork Generation endpoint (provider-aware) ----
        $keyword = $input['keyword'] ?? '';
        $location = $input['location'] ?? '';
        $persona = $input['persona'] ?? null;
        $expandLocations = $input['expand'] ?? false;
        
        if (empty($keyword)) {
            echo json_encode(['success' => false, 'message' => 'Keyword is required.']);
            exit;
        }

        // Fetch active search provider to generate optimized dorks
        $providerStmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_search_provider'");
        $providerStmt->execute();
        $providerRow = $providerStmt->fetch();
        $activeProvider = $providerRow ? $providerRow['setting_value'] : null;

        $locations = $expandLocations 
            ? \App\DorkLibrary::expandLocation($location) 
            : [$location];
        
        $allDorks = [];
        foreach ($locations as $loc) {
            $dorks = \App\DorkLibrary::generateDorks($keyword, $loc, $persona, $activeProvider);
            foreach ($dorks as $key => $dork) {
                $allDorks[] = ['type' => $key, 'location' => $loc, 'query' => $dork];
            }
        }

        echo json_encode([
            'success' => true, 
            'data' => $allDorks, 
            'count' => count($allDorks),
            'provider' => $activeProvider ?? 'default',
            'presets' => \App\DorkLibrary::getPersonaPresets()
        ]);
    }
    elseif ($action === 'add_proxies') {
        require_once '../includes/ProxyManager.php';
        $proxies_str = $input['proxies'] ?? '';
        $proxies = array_filter(array_map('trim', explode("\n", $proxies_str)));
        
        // 1. Save raw settings for UI reloads
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('proxies', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$proxies_str, $proxies_str]);
        
        // 2. Load structured proxies & ensure schema exists first
        $pm = new ProxyManager($pdo);

        // 3. Clear old proxies to prevent lingering ghosts
        $pdo->exec("DELETE FROM proxies");
        
        $count = $pm->addProxies($proxies);
        echo json_encode(['success' => true, 'count' => $count]);
    }
    elseif ($action === 'test_proxies') {
        // Proxy fleet completely disabled globally. Immediate success returned.
        echo json_encode([
            'success' => true, 
            'tested' => 0,
            'passed' => 0,
            'failed' => 0,
            'results' => []
        ]);
    }
}
elseif ($method === 'GET') {
    if ($action === 'stats') {
        // Proxies completely disabled. Return 0 active/dead proxies immediately.
        echo json_encode([
            'success' => true,
            'data' => [
                'active' => 0,
                'dead' => 0
            ]
        ]);
    }
    elseif ($action === 'job_status') {
        // ---- NEW: Poll job status ----
        $jobIds = $_GET['ids'] ?? '';
        if (empty($jobIds)) {
            echo json_encode(['success' => false, 'message' => 'No job IDs provided.']);
            exit;
        }
        
        $ids = array_map('intval', explode(',', $jobIds));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $stmt = $pdo->prepare("SELECT id, type, status, result, error_message, attempts, created_at, completed_at FROM jobs WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        $jobs = $stmt->fetchAll();
        
        // Parse JSON result fields
        foreach ($jobs as &$job) {
            if ($job['result']) {
                $job['result'] = json_decode($job['result'], true);
            }
        }
        
        echo json_encode(['success' => true, 'jobs' => $jobs]);
    }
    elseif ($action === 'persona_presets') {
        // ---- NEW: Get available persona presets ----
        echo json_encode(['success' => true, 'presets' => \App\DorkLibrary::getPersonaPresets()]);
    }
    else {
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
}
else {
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
}
?>
