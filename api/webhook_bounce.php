<?php

/**
 * Bounce webhook endpoint (PUBLIC — no login auth).
 *
 * Providers (SendGrid, etc.) cannot hold app credentials, so this endpoint
 * authenticates via \App\Webhooks\WebhookAuth instead of
 * \App\Auth::requireApiAuth():
 *   - SendGrid: signed event webhook (ECDSA), key from the
 *     'sendgrid_webhook_public_key' setting.
 *   - Generic: HMAC-SHA256 of the raw body with the per-install
 *     'webhook_secret' setting, via header X-Webhook-Signature or ?sig=.
 *
 * SendGrid POSTs a JSON array of events. Response is aggregate counts only —
 * it never reveals whether any particular email exists in the system.
 */
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';

use App\Webhooks\BounceHandler;
use App\Webhooks\WebhookAuth;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['received' => 0, 'processed' => 0, 'suppressed' => 0]);
    exit;
}

// Generic 401 body: identical for every failure so nothing about the
// installation (or any email) leaks to an unauthenticated caller.
$deny = static function (): void {
    http_response_code(401);
    echo json_encode(['received' => 0, 'processed' => 0, 'suppressed' => 0]);
    exit;
};

try {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        $deny();
    }

    [$ok, $provider] = WebhookAuth::fromRequest($raw);
    if (!$ok) {
        $deny();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['received' => 0, 'processed' => 0, 'suppressed' => 0]);
        exit;
    }
    // SendGrid sends an array of events; accept a single event object too.
    $events = array_is_list($data) ? $data : [$data];

    $result = BounceHandler::handle($provider, $events);
    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('[webhook_bounce] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['received' => 0, 'processed' => 0, 'suppressed' => 0]);
}
