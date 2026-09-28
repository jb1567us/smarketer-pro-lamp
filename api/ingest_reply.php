<?php
/**
 * api/ingest_reply.php — authenticated ingestion seam for inbound reply
 * classification (Phase 4's n8n automation posts here).
 *
 * AUTH: dashboard session OR the per-install ingest API key
 * (\App\ApiAuth::ingestKey()) presented as header `X-Api-Key` or
 * `Authorization: Bearer <key>`. Sessions keep working for manual callers.
 *
 * Request (POST, application/json):
 *   {
 *     "subject":        "Re: your proposal",   // required, max 500 chars
 *     "body":           "Thanks, let's talk…", // required, max 20000 chars
 *     "thread_context": "…",                   // optional, max 20000 chars
 *     "from":           "buyer@example.com",   // optional, must be a valid email
 *     "message_id":     "<abc@mail.example>"   // optional, max 500 chars — the
 *                                              // email Message-ID; enables
 *                                              // idempotent redelivery
 *   }
 *
 * Response 200 (routing is NEVER auto-applied — the caller decides):
 *   {
 *     "success": true,
 *     "duplicate": false,        // true when this message_id (or identical
 *                                // content) was already classified — verdict
 *                                // below is the cached one
 *     "verdict": {
 *       "intent": "positive",
 *       "needs_human": true,
 *       "urgency": 8,            // 1-10 int from ClassifyReplyAction
 *       "confidence": 0.82,
 *       "source": "jev",
 *       "latency_ms": 1234,
 *       "note": null             // heuristic verdicts carry a human-readable note instead
 *     },
 *     "routed": false,
 *     "meta": {
 *       "received_at": "2026-09-28T13:41:00-05:00",
 *       "subject_length": 17,
 *       "body_length": 23,
 *       "from_provided": true
 *     }
 *   }
 *
 * Errors:
 *   401 {"success":false,"error":"Authentication required"}   — not logged in
 *   405 {"success":false,"error":"Method not allowed"}        — non-POST
 *   400 {"success":false,"error":"…"}                         — bad input
 *   413 {"success":false,"error":"Payload too large"}        — body > 256 KiB
 *   429 {"success":false,"error":"Rate limit exceeded"}      — > 60 req/min/IP
 *   500 {"success":false,"error":"…"}                         — classifier failure
 *
 * Every request is appended to logs/ingest_replies.jsonl.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
// Session auth stays valid; automation uses the per-install API key.
\App\Auth::requireApiAuth(\App\ApiAuth::ingestKey());   // 401 JSON when unauthenticated

// Classification already enforces its own timeout inside the action;
// this guards total request execution on top.
if (function_exists('set_time_limit')) {
    @set_time_limit(90);
}

function ingest_error(int $code, string $message, ?array $logEntry = null): void
{
    if ($logEntry !== null) {
        $logEntry['error'] = $message;
        $logEntry['http_status'] = $code;
        \App\ReplyIntake::logIntake($logEntry);
    }
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Payload cap BEFORE reading the body: a declared Content-Length over the
// ceiling is rejected outright; the post-read check below covers chunked
// bodies that lie about their length.
$declaredLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declaredLength > \App\ApiAuth::MAX_BODY_BYTES) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Payload too large']);
    exit;
}

// Per-IP rate limit (60 req/min). Rejected requests are logged for ops.
$rate = \App\ApiAuth::rateCheck($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!$rate['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . (int)$rate['retry_after']);
    echo json_encode(['success' => false, 'error' => 'Rate limit exceeded']);
    \App\ReplyIntake::logIntake([
        'source'      => 'api_ingest',
        'received_at' => date('c'),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'error'       => 'Rate limit exceeded',
        'http_status' => 429,
    ]);
    exit;
}

$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > \App\ApiAuth::MAX_BODY_BYTES) {
    ingest_error(413, 'Payload too large', [
        'source'      => 'api_ingest',
        'received_at' => date('c'),
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
}
$data = json_decode($raw, true);

$baseLog = [
    'source'      => 'api_ingest',
    'received_at' => date('c'),
    'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
];

if (!is_array($data)) {
    ingest_error(400, 'Invalid JSON payload', $baseLog);
}

$subject       = isset($data['subject']) ? (string)$data['subject'] : '';
$body          = isset($data['body']) ? (string)$data['body'] : '';
$threadContext = isset($data['thread_context']) ? (string)$data['thread_context'] : '';
$from          = isset($data['from']) ? trim((string)$data['from']) : '';
$messageId     = isset($data['message_id']) ? trim((string)$data['message_id']) : '';

$subject = trim($subject);
$body    = trim($body);

$baseLog['subject']        = mb_substr($subject, 0, 200);
$baseLog['from']           = $from !== '' ? $from : null;

if ($subject === '') {
    ingest_error(400, 'Field "subject" is required and must be a non-empty string', $baseLog);
}
if ($body === '') {
    ingest_error(400, 'Field "body" is required and must be a non-empty string', $baseLog);
}
if (strlen($subject) > 500) {
    ingest_error(400, 'Field "subject" exceeds the 500-character limit', $baseLog);
}
if (strlen($body) > 20000) {
    ingest_error(400, 'Field "body" exceeds the 20000-character limit', $baseLog);
}
if (strlen($threadContext) > 20000) {
    ingest_error(400, 'Field "thread_context" exceeds the 20000-character limit', $baseLog);
}
if (strlen($messageId) > 500) {
    ingest_error(400, 'Field "message_id" exceeds the 500-character limit', $baseLog);
}
if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
    ingest_error(400, 'Field "from" must be a valid email address', $baseLog);
}

// Idempotency: a redelivered message_id (or byte-identical content without
// one) returns the cached verdict instead of re-classifying. The duplicate
// is still logged so ops can audit redeliveries.
$dedupeKey = \App\ApiAuth::dedupeKey($messageId, $subject, $body, $from);
$cached = \App\ApiAuth::dedupeGet($dedupeKey);
if ($cached !== null) {
    $baseLog['duplicate'] = true;
    $baseLog['dedupe_key'] = substr($dedupeKey, 0, 12) . '…';
    \App\ReplyIntake::logIntake($baseLog);
    echo json_encode([
        'success'   => true,
        'duplicate' => true,
        'verdict'   => $cached,
        'routed'    => false,
        'meta'      => [
            'received_at'    => $baseLog['received_at'],
            'subject_length' => strlen($subject),
            'body_length'    => strlen($body),
            'from_provided'  => $from !== '',
        ],
    ]);
    exit;
}

try {
    $verdict = \App\ReplyIntake::classifyReply($subject, $body, $threadContext);
} catch (\Throwable $e) {
    ingest_error(500, 'Classification failed: ' . $e->getMessage(), $baseLog);
}

\App\ApiAuth::dedupePut($dedupeKey, $verdict);

$baseLog['duplicate'] = false;
$baseLog['verdict'] = $verdict;
\App\ReplyIntake::logIntake($baseLog);

echo json_encode([
    'success'   => true,
    'duplicate' => false,
    'verdict'   => $verdict,
    'routed'    => false,
    'meta'      => [
        'received_at'    => $baseLog['received_at'],
        'subject_length' => strlen($subject),
        'body_length'    => strlen($body),
        'from_provided'  => $from !== '',
    ],
]);
