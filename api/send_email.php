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
        $stmt = $pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?)");
        $meta = json_encode([
            'subject' => $subject, 
            'delivered' => true, 
            'provider' => $provider
        ]);
        $stmt->execute([$to, $provider, 'sent', $meta, time()]);

        echo json_encode([
            'success' => true,
            'message' => "Email sent successfully via " . ucfirst($provider) . "!",
            'lead_id' => $leadId,
            'status' => 'Contacted'
        ]);
    } else {
        // Log the failure event
        $stmt = $pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?)");
        $meta = json_encode(['subject' => $subject, 'error' => $error, 'provider' => $provider]);
        $stmt->execute([$to, $provider, 'failed', $meta, time()]);

        echo json_encode([
            'success' => false,
            'error' => ucfirst($provider) . ' Delivery Failed: ' . $error
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
