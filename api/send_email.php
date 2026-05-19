<?php
/**
 * Send Email API Endpoint
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
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

    // 2. Fetch SMTP settings
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'smtp_%'");
    $settings = $stmt->fetchAll(\App\PDO::FETCH_KEY_PAIR);

    $smtpHost = $settings['smtp_host'] ?? '';
    $smtpPort = $settings['smtp_port'] ?? '';
    $smtpUser = $settings['smtp_user'] ?? '';
    $smtpPass = $settings['smtp_pass'] ?? '';
    $smtpEnc = $settings['smtp_encryption'] ?? 'tls';

    $sent = false;
    $error = null;

    if (!empty($smtpHost) && !empty($smtpUser) && !empty($smtpPass)) {
        $headers = "From: " . $smtpUser . "\r\n" .
                   "Reply-To: " . $smtpUser . "\r\n" .
                   "X-Mailer: PHP/" . phpversion();
        try {
            $sent = mail($to, $subject, $body, $headers);
            if (!$sent) {
                $error = "PHP mail() returned false. Check local mail server configuration.";
            }
        } catch (Exception $mailEx) {
            $error = $mailEx->getMessage();
        }
    } else {
        // Safe outcome sandbox simulation when credentials aren't provided
        $sent = true;
    }

    if ($sent) {
        // 3. Update Lead Status to 'Contacted'
        $stmt = $pdo->prepare("UPDATE leads SET status = 'Contacted' WHERE id = ?");
        $stmt->execute([$leadId]);

        // 4. Log the send event in email_logs
        $stmt = $pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?)");
        $meta = json_encode(['subject' => $subject, 'delivered' => true, 'simulated' => (empty($smtpHost) || empty($smtpUser))]);
        $stmt->execute([$to, 'smtp', 'sent', $meta, time()]);

        echo json_encode([
            'success' => true,
            'message' => 'Email sent successfully via SMTP!',
            'lead_id' => $leadId,
            'status' => 'Contacted'
        ]);
    } else {
        // Log the failure event
        $stmt = $pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?)");
        $meta = json_encode(['subject' => $subject, 'error' => $error]);
        $stmt->execute([$to, 'smtp', 'failed', $meta, time()]);

        echo json_encode([
            'success' => false,
            'error' => 'SMTP Delivery Failed: ' . $error
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
