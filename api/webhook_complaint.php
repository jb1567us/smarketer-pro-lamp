<?php

/**
 * Complaint webhook endpoint (PUBLIC — no login auth).
 *
 * Receives spam-complaint feedback-loop events from email providers and
 * suppresses the reporting address so it is never mailed again.
 *
 * Accepts:
 *   - SendGrid event webhook: JSON array of event objects ("event": "spamreport").
 *   - Generic single event: JSON object {email, event: "complaint"|"spamreport", provider}.
 *
 * Authentication: delegated to App\Webhooks\WebhookAuth::fromRequest() when
 * includes/Webhooks/WebhookAuth.php is present (SendGrid ECDSA via the
 * `sendgrid_webhook_public_key` setting, else HMAC-SHA256 under the
 * per-install `webhook_secret` setting — both compared timing-safe). If that
 * file is absent, an inline HMAC-SHA256 fallback verifies the raw request
 * body against the X-Webhook-Signature header ("sha256=<hex>", bare hex also
 * accepted) with the same `webhook_secret` setting.
 *
 * A missing/unconfigured secret or a bad signature returns 401; nothing in
 * any error response reveals whether an email exists or is suppressed.
 *
 * Returns JSON: {"received": N, "processed": N, "suppressed": N}
 *
 * Setup: set the `webhook_secret` setting (System Settings or the settings
 * table) to a random value and configure the provider to sign webhook bodies
 * with HMAC-SHA256 under that secret. SendGrid's native event webhook signs
 * with ECDSA instead — that verification lives in the sibling
 * includes/Webhooks/WebhookAuth.php (compliance/gaps-item1); see the TODO
 * below.
 */

declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';

use App\Database;
use App\Webhooks\ComplaintHandler;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

/** Generic failure responder — messages never carry email addresses or hints. */
$fail = static function (int $code, string $error): void {
    http_response_code($code);
    echo json_encode(['error' => $error]);
    exit;
};

try {
    $rawBody = (string)file_get_contents('php://input');
    if ($rawBody === '') {
        $fail(400, 'invalid_payload');
    }
    if (strlen($rawBody) > 5 * 1024 * 1024) {
        $fail(413, 'payload_too_large');
    }

    // --- Authentication --------------------------------------------------
    // Primary verifier: the sibling branch (compliance/gaps-item1) provides
    // includes/Webhooks/WebhookAuth.php, which verifies SendGrid's native
    // ECDSA event-webhook signature (X-Twilio-Email-Event-Webhook-Signature /
    // -Timestamp, key from the `sendgrid_webhook_public_key` setting) and
    // HMAC-SHA256 under the same per-install `webhook_secret` setting used by
    // the inline fallback below. SendGrid-signed requests are never
    // downgraded to HMAC inside WebhookAuth.
    $authFile = __DIR__ . '/../includes/Webhooks/WebhookAuth.php';
    if (is_file($authFile)) {
        require_once $authFile;
    }
    $authorized = false;
    if (class_exists(\App\Webhooks\WebhookAuth::class)
        && method_exists(\App\Webhooks\WebhookAuth::class, 'fromRequest')
    ) {
        [$ok] = \App\Webhooks\WebhookAuth::fromRequest($rawBody);
        $authorized = (bool)$ok;
    } else {
        // Inline HMAC fallback — runs only when WebhookAuth.php is absent.
        // TODO(consolidation): once WebhookAuth.php is guaranteed present on
        // main, delete this fallback so there is exactly one webhook verifier.
        $secret = (string)(Database::getSetting('webhook_secret', '') ?? '');
        $signature = (string)(
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']
            ?? $_SERVER['HTTP_X_WEBHOOK_HMAC']
            ?? ''
        );
        $authorized = ComplaintHandler::verifyHmac($rawBody, $signature, $secret);
    }
    if (!$authorized) {
        error_log('[webhook_complaint] rejected: bad signature from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        $fail(401, 'unauthorized');
    }

    // --- Payload ----------------------------------------------------------
    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        $fail(400, 'invalid_payload');
    }

    $provider = strtolower(trim((string)(
        $_SERVER['HTTP_X_WEBHOOK_PROVIDER'] ?? $_GET['provider'] ?? ''
    )));
    if (array_is_list($payload)) {
        // SendGrid-style: array of event objects.
        $events = $payload;
        $provider = $provider !== '' ? $provider : 'sendgrid';
    } else {
        // Generic single event object: {email, event, provider?}.
        $events = [$payload];
        if ($provider === '') {
            $p = $payload['provider'] ?? '';
            $provider = (is_string($p) && trim($p) !== '') ? strtolower(trim($p)) : 'generic';
        }
    }
    // Keep provider storage-safe and cap the batch (abuse guard).
    $provider = (string)preg_replace('/[^a-z0-9_\-]/', '', $provider);
    if ($provider === '') {
        $provider = 'generic';
    }
    $events = array_slice($events, 0, 1000);

    $result = ComplaintHandler::process($events, $provider);

    http_response_code(200);
    echo json_encode([
        'received' => $result['received'],
        'processed' => $result['processed'],
        'suppressed' => $result['suppressed'],
    ]);
} catch (\Throwable $e) {
    error_log('[webhook_complaint] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'internal_error']);
}
