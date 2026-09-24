<?php
/**
 * Campaigns & Templates API
 *
 * Actions (all require auth via \App\Auth::requireApiAuth):
 *   GET  api/campaigns.php?type=campaigns                    list campaigns
 *   POST api/campaigns.php?type=campaigns                    create {name, description}
 *   POST api/campaigns.php?type=campaigns&action=update     update {id, name, description}
 *   POST api/campaigns.php?type=campaigns&action=delete     delete {id}
 *   POST api/campaigns.php?type=campaigns&action=toggle     toggle active {id}
 *   POST api/campaigns.php?type=campaigns&action=resume     MANUAL resume of an
 *        auto-paused campaign {id}: clears status='paused' back to 'active',
 *        clears paused_reason/paused_at, restores is_active=1. Requires the
 *        item-4 pause columns; 400 when they are not installed.
 *   GET  api/campaigns.php?type=templates&campaign_id=ID     list templates
 *   POST api/campaigns.php?type=templates                    create {campaign_id, subject, body, step_order}
 *   POST api/campaigns.php?type=templates&action=update     update {id, subject, body, step_order}
 *   POST api/campaigns.php?type=templates&action=delete     delete {id}
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
$pdo = \App\Database::getConnection();

$type   = $_GET['type'] ?? 'campaigns';
$action = $_GET['action'] ?? null;
$method = $_SERVER['REQUEST_METHOD'];

function campaigns_error(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/**
 * Honest pre-send notice (Fix 1: provider-first sending, no deliverability
 * promises). Confirms which provider/account will send when a campaign is
 * launched or resumed. Values are read from settings at call time.
 */
function campaign_send_notice(): array {
    $provider = strtolower(trim((string)(\App\Database::getSetting('active_email_provider', 'smtp') ?: 'smtp')));
    $sender   = (string)(\App\Database::getSetting('email_sender', '') ?: '');

    // "Configured" = credentials stored for this provider. Never returned,
    // only a boolean: secrets stay out of API responses.
    $configured = false;
    if (in_array($provider, \App\SendNotice::API_PROVIDERS, true)) {
        $configured = (string)(\App\Database::getSetting("{$provider}_api_key", '') ?: '') !== '';
    } elseif (in_array($provider, \App\SendNotice::SMTP_PROVIDERS, true)) {
        $pass = (string)(\App\Database::getSetting("{$provider}_smtp_pass", '') ?: '');
        $pass = $pass !== '' ? $pass : (string)(\App\Database::getSetting('smtp_pass', '') ?: '');
        $configured = $pass !== '';
    }

    return \App\SendNotice::build($provider, $sender, $configured);
}

try {
    if ($type === 'campaigns') {
        if ($method === 'GET') {
            $stmt = $pdo->query("SELECT * FROM campaigns ORDER BY created_at DESC");
            $data = $stmt->fetchAll();
            // Attach per-campaign outcome metrics (fail-soft: any missing
            // table/column yields zeros rather than breaking the endpoint).
            foreach ($data as &$c) {
                $cid = (int)($c['id'] ?? 0);
                $c['metrics'] = ['sent' => 0, 'queued' => 0, 'failed' => 0];
                if ($cid <= 0) continue;
                try {
                    $m = $pdo->prepare(
                        "SELECT status, COUNT(*) AS n FROM email_logs WHERE campaign_id = ? GROUP BY status"
                    );
                    $m->execute([$cid]);
                    foreach ($m->fetchAll() as $row) {
                        if ($row['status'] === 'sent') $c['metrics']['sent'] = (int)$row['n'];
                        elseif (in_array($row['status'], ['failed', 'bounced'], true)) $c['metrics']['failed'] += (int)$row['n'];
                        elseif ($row['status'] === 'queued') $c['metrics']['queued'] += (int)$row['n'];
                    }
                    $q = $pdo->prepare(
                        "SELECT COUNT(*) AS n FROM task_queue WHERE task_type = 'EmailOutreach' " .
                        "AND status IN ('Pending','In Progress') " .
                        "AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.campaign_id')) = CAST(? AS CHAR)"
                    );
                    $q->execute([$cid]);
                    $c['metrics']['queued'] += (int)($q->fetch()['n'] ?? 0);
                } catch (\Throwable $e) {
                    // keep zeros
                }
            }
            unset($c);
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'meta'    => ['count' => count($data)],
            ]);
            exit;
        }

        if ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true) ?? [];

            if ($action === 'update') {
                $id   = (int)($data['id'] ?? 0);
                $name = trim($data['name'] ?? '');
                if ($id <= 0 || $name === '') campaigns_error(400, 'Missing id or name');
                $stmt = $pdo->prepare("UPDATE campaigns SET name = ?, description = ? WHERE id = ?");
                $stmt->execute([$name, $data['description'] ?? '', $id]);
                // Optional: clear/set the DNS preflight override without SQL
                // (setCampaignOverride is a no-op until the column is migrated).
                if (array_key_exists('dns_preflight_override', $data)) {
                    \App\DnsAuth::setCampaignOverride($pdo, $id, !empty($data['dns_preflight_override']));
                }
                echo json_encode(['success' => true, 'id' => $id]);
                exit;
            }

            if ($action === 'delete') {
                $id = (int)($data['id'] ?? 0);
                if ($id <= 0) campaigns_error(400, 'Missing id');
                $stmt = $pdo->prepare("DELETE FROM campaigns WHERE id = ?");
                $stmt->execute([$id]);
                if ($stmt->rowCount() === 0) campaigns_error(404, 'Campaign not found');
                echo json_encode(['success' => true, 'id' => $id]);
                exit;
            }

            if ($action === 'resume') {
                // MANUAL resume of an auto-paused campaign. There is no
                // automatic resume: the admin reviews the pause reason, fixes
                // the underlying list/reputation problem, then resumes here.
                $id = (int)($data['id'] ?? 0);
                if ($id <= 0) campaigns_error(400, 'Missing id');
                $col = $pdo->query(
                    "SELECT COUNT(*) AS c FROM information_schema.COLUMNS " .
                    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'campaigns' AND COLUMN_NAME = 'status'"
                )->fetch();
                if (!$col || (int)$col['c'] === 0) {
                    campaigns_error(400, 'Pause columns not installed; nothing to resume');
                }
                $stmt = $pdo->prepare(
                    "UPDATE campaigns SET status = 'active', paused_reason = NULL, paused_at = NULL, is_active = 1 " .
                    "WHERE id = ?"
                );
                $stmt->execute([$id]);
                if ($stmt->rowCount() === 0) campaigns_error(404, 'Campaign not found');
                echo json_encode([
                    'success' => true, 'id' => $id, 'status' => 'active',
                    'send_notice' => campaign_send_notice(),
                ]);
                exit;
            }

            if ($action === 'toggle') {
                $id = (int)($data['id'] ?? 0);
                if ($id <= 0) campaigns_error(400, 'Missing id');
                $stmt = $pdo->prepare("SELECT id, is_active FROM campaigns WHERE id = ?");
                $stmt->execute([$id]);
                $campaign = $stmt->fetch();
                if (!$campaign) campaigns_error(404, 'Campaign not found');

                // Preflight gate: only when this toggle ACTIVATES the campaign
                // (inactive -> active = start of sending). Pausing is never gated.
                $preflight = null;
                if (!(bool)$campaign['is_active']) {
                    $overrideRequested = !empty($data['override_dns_preflight']);
                    $gate = \App\DnsAuth::campaignStartGate($pdo, $id, $overrideRequested);
                    $preflight = $gate['report'];
                    if ($gate['override_granted']) {
                        \App\DnsAuth::setCampaignOverride($pdo, $id, true);
                    }
                    if (!$gate['allowed']) {
                        http_response_code(422);
                        echo json_encode([
                            'success' => false,
                            'error' => $gate['block_message'],
                            'dns_preflight' => $preflight,
                        ]);
                        exit;
                    }
                }

                $stmt = $pdo->prepare("UPDATE campaigns SET is_active = NOT is_active WHERE id = ?");
                $stmt->execute([$id]);
                $stmt = $pdo->prepare("SELECT is_active FROM campaigns WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) campaigns_error(404, 'Campaign not found');
                $resp = ['success' => true, 'id' => $id, 'is_active' => (bool)$row['is_active']];
                if ($preflight !== null) {
                    $resp['dns_preflight'] = $preflight;
                }
                if ((bool)$row['is_active']) {
                    // Activating = launch: attach the honest pre-send notice.
                    $resp['send_notice'] = campaign_send_notice();
                }
                echo json_encode($resp);
                exit;
            }

            // Create
            $name = trim($data['name'] ?? '');
            if ($name === '') campaigns_error(400, 'Campaign name is required');
            $stmt = $pdo->prepare("INSERT INTO campaigns (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $data['description'] ?? '']);
            echo json_encode([
                'success' => true,
                'data'    => ['id' => $pdo->lastInsertId()],
                'meta'    => ['timestamp' => date('c')],
            ]);
            exit;
        }
    } elseif ($type === 'templates') {
        if ($method === 'GET') {
            $campaign_id = (int)($_GET['campaign_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT * FROM templates WHERE campaign_id = ? ORDER BY step_order ASC");
            $stmt->execute([$campaign_id]);
            $data = $stmt->fetchAll();
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'meta'    => ['count' => count($data)],
            ]);
            exit;
        }

        if ($method === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true) ?? [];

            if ($action === 'update') {
                $id      = (int)($data['id'] ?? 0);
                $subject = trim($data['subject'] ?? '');
                if ($id <= 0 || $subject === '') campaigns_error(400, 'Missing id or subject');
                $stmt = $pdo->prepare("UPDATE templates SET subject = ?, body = ?, step_order = ? WHERE id = ?");
                $stmt->execute([$subject, $data['body'] ?? '', (int)($data['step_order'] ?? 1), $id]);
                echo json_encode(['success' => true, 'id' => $id]);
                exit;
            }

            if ($action === 'delete') {
                $id = (int)($data['id'] ?? 0);
                if ($id <= 0) campaigns_error(400, 'Missing id');
                $stmt = $pdo->prepare("DELETE FROM templates WHERE id = ?");
                $stmt->execute([$id]);
                if ($stmt->rowCount() === 0) campaigns_error(404, 'Template not found');
                echo json_encode(['success' => true, 'id' => $id]);
                exit;
            }

            // Create
            $stmt = $pdo->prepare("INSERT INTO templates (campaign_id, subject, body, step_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                (int)($data['campaign_id'] ?? 0),
                $data['subject'] ?? '',
                $data['body'] ?? '',
                (int)($data['step_order'] ?? 1),
            ]);
            echo json_encode([
                'success' => true,
                'data'    => ['id' => $pdo->lastInsertId()],
                'meta'    => ['timestamp' => date('c')],
            ]);
            exit;
        }
    }

    campaigns_error(405, 'Method not allowed');
} catch (Exception $e) {
    error_log('campaigns api error: ' . $e->getMessage());
    campaigns_error(500, 'Server error');
}
