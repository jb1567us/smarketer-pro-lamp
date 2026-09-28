<?php
/**
 * Send Email API Endpoint
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
$pdo = \App\Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $leadId = isset($input['lead_id']) ? (int)$input['lead_id'] : null;
    $subject = $input['subject'] ?? '';
    $body = $input['body'] ?? '';

    if (!$leadId) {
        throw new Exception("Missing Lead ID");
    }
    if (empty($subject) || empty($body)) {
        throw new Exception("Subject and Body are required");
    }

    // 1. Fetch the lead
    $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([$leadId]);
    $lead = $stmt->fetch();
    if (!$lead) {
        throw new Exception("Lead not found");
    }

    $to = $lead['email'];

    // Campaign attribution (item 10): prefer an explicit request value, fall
    // back to the lead's assigned campaign. Written to email_logs only when
    // the compliance DDL has added the column (graceful pre-migration).
    $campaignId = isset($input['campaign_id']) ? (int)$input['campaign_id'] : 0;
    if ($campaignId <= 0 && isset($lead['campaign_id'])) {
        $campaignId = (int)$lead['campaign_id'];
    }
    $campaignId = $campaignId > 0 ? $campaignId : null;
    $hasLogCampaignCol = false;
    if ($campaignId !== null) {
        try {
            $colProbe = $pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS " .
                "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_logs' AND COLUMN_NAME = 'campaign_id' LIMIT 1"
            );
            $colProbe->execute();
            $hasLogCampaignCol = (bool)$colProbe->fetch();
        } catch (\Exception $e) {
            $hasLogCampaignCol = false;
        }
    }

    if (empty($to)) {
        throw new Exception("Lead does not have a valid email address");
    }
    if (\App\EmailSender::isPlaceholderAddress($to)) {
        throw new Exception("Lead has no verified email address (placeholder only). Resolve a real address before sending.");
    }

    // Compliance pre-checks (fail fast with a clear, buyer-actionable error).
    // EmailSender::send() re-enforces these at the choke point as a backstop.
    if (\App\Compliance::isSuppressed($to)) {
        throw new Exception("This address is on the suppression list (opt-out, bounce, or complaint) and cannot be mailed.");
    }
    // CASL is enforced AND audited at the EmailSender::send() choke point
    // (Compliance::requireCompliantSend → casl_decisions). No duplicate gate
    // here: one send attempt = one CASL decision = one audit row.

    // Phase 0 fix (was high-priority gap H3): duplicate-send protection.
    // Refuse to mail an address we already sent to unless the caller passes
    // force_resend:true explicitly (deliberate resend, not an accident).
    $forceResend = !empty($input['force_resend']);
    if (!$forceResend) {
        try {
            $dupStmt = $pdo->prepare(
                "SELECT provider_id, campaign_id, status, timestamp, metadata_json " .
                "FROM email_logs WHERE lead_email = ? AND status IN ('sent','queued') " .
                "ORDER BY timestamp DESC LIMIT 1"
            );
            $dupStmt->execute([$to]);
            $prior = $dupStmt->fetch();
            if ($prior) {
                $when = date('Y-m-d H:i', (int)$prior['timestamp']);
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'error' => "Already sent to {$to} on {$when} via {$prior['provider_id']}. " .
                               "Pass force_resend:true to send again deliberately.",
                    'already_sent' => [
                        'provider' => $prior['provider_id'],
                        'campaign_id' => $prior['campaign_id'],
                        'status' => $prior['status'],
                        'sent_at' => date('c', (int)$prior['timestamp']),
                    ],
                ]);
                exit;
            }
        } catch (\Throwable $e) {
            // Fail open on lookup failure (a missing email_logs table must not
            // block sending); the send itself is still logged below.
            error_log('[send_email] duplicate-send lookup failed: ' . $e->getMessage());
        }
    }

    // 2. Fetch Email and SMTP settings
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('active_email_provider', 'email_sender', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption')");
    $settings = $stmt->fetchAll(\App\PDO::FETCH_KEY_PAIR);

    $provider = $settings['active_email_provider'] ?? 'smtp';
    $senderEmail = $settings['email_sender'] ?? 'onboarding@resend.dev';
    $smtpHost = $settings['smtp_host'] ?? '';
    $smtpPort = $settings['smtp_port'] ?? '587';
    $smtpUser = $settings['smtp_user'] ?? '';
    $smtpPass = $settings['smtp_pass'] ?? '';
    $smtpEnc = $settings['smtp_encryption'] ?? 'tls';

    // Phase 4: send gate (send_gate.final_decision) — final go/no-go before
    // a queued send. SHADOW-FIRST: in off/shadow modes the gate evaluates
    // and logs (JEV answers + agreement land in logs/jev_shadow.jsonl) but
    // the verdict never blocks. In live mode a denied verdict returns 403.
    // Compliance gates (suppression, sender identity, CASL, verification,
    // unsubscribe, quota) run inside the gate BEFORE any JEV verdict can
    // approve; EmailSender::send()'s Compliance choke point remains the
    // backstop.
    try {
        $gateAction = new \App\Actions\SendGateAction($pdo, new \App\Routers\SmartLLMRouter($pdo));
        $gate = $gateAction->gate($leadId, [
            'subject' => $subject,
            'body' => $body,
            'provider' => $provider,
            'campaign_id' => $campaignId,
        ]);
        if (\App\Jev\DecisionTier::mode() === 'live' && !$gate['allowed']) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Send gate denied: ' . implode('; ', $gate['blockers']),
                'gate' => $gate,
            ]);
            exit;
        }
    } catch (\Throwable $e) {
        // Gate failure must never change behavior outside live mode; in live
        // mode SendGateAction::gate is itself fail-closed.
        error_log('[send_email] send gate evaluation failed: ' . $e->getMessage());
    }

    $sent = false;
    $error = null;

    if ($provider === 'smart_rotation') {
        // Route via Smart Failover Router
        try {
            $router = new \App\Routers\SmartEmailRouter($pdo);
            $sent = $router->send($to, $subject, $body);
            if (!$sent) {
                $error = "Smart Rotation failed to send email using any configured provider.";
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        // Route via single designated provider
        try {
            // Resolve designated API Key or SMTP Password
            $apiKey = \App\Database::getSetting("{$provider}_api_key");
            if (empty($apiKey)) {
                $apiKey = in_array($provider, ['smtp', 'custom_smtp', 'sendpulse', 'amazon_ses', 'zoho_smtp', 'netcore_smtp']) ? $smtpPass : $smtpUser;
            }

            $sent = \App\EmailSender::send($to, $subject, $body, $provider, $apiKey, $senderEmail, $smtpHost);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    if ($sent) {
        // 3. Update Lead Status to 'Contacted'
        $stmt = $pdo->prepare("UPDATE leads SET status = 'Contacted' WHERE id = ?");
        $stmt->execute([$leadId]);

        // 4. Log the send event in email_logs
        $meta = json_encode([
            'subject' => $subject,
            'delivered' => true,
            'provider' => $provider
        ]);
        if ($hasLogCampaignCol) {
            $stmt = $pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, campaign_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$to, $provider, $campaignId, 'sent', $meta, time()]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$to, $provider, 'sent', $meta, time()]);
        }

        echo json_encode([
            'success' => true,
            'message' => "Email sent successfully via " . ucfirst($provider) . "!",
            'lead_id' => $leadId,
            'status' => 'Contacted'
        ]);
    } else {
        // Log the failure event
        $meta = json_encode(['subject' => $subject, 'error' => $error, 'provider' => $provider]);
        if ($hasLogCampaignCol) {
            $stmt = $pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, campaign_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$to, $provider, $campaignId, 'failed', $meta, time()]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$to, $provider, 'failed', $meta, time()]);
        }

        echo json_encode([
            'success' => false,
            'error' => ucfirst($provider) . ' Delivery Failed: ' . $error
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
