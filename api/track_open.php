<?php
/**
 * Open-tracking pixel (PUBLIC — no login auth, tokenized like the webhooks).
 *
 * Recipients' mail clients GET this URL from the <img> pixel embedded in
 * sequence emails. Authentication is the 64-hex unguessable track_token in
 * ?t= — it cannot be enumerated, and the response is byte-identical whether
 * the token exists or not (1x1 transparent GIF, HTTP 200, no-cache), so the
 * endpoint is not an oracle for valid tokens.
 *
 * Opens are attributed by \App\SequenceManager::recordOpen(), which never
 * throws: a tracking failure must never break the pixel response.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/autoload.php';

// 1x1 transparent GIF.
const TRACK_OPEN_GIF = 'R0lGODlhAQABAIAAAP///////yH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

try {
    $token = $_GET['t'] ?? '';
    if (is_string($token) && $token !== '') {
        \App\SequenceManager::recordOpen(
            null,
            $token,
            $_SERVER['REMOTE_ADDR'] ?? null,
            is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : null
        );
    }
} catch (\Throwable $e) {
    error_log('[track_open] ' . $e->getMessage());
}

header('Content-Type: image/gif');
header('Content-Length: ' . strlen(base64_decode(TRACK_OPEN_GIF)));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
echo base64_decode(TRACK_OPEN_GIF);
