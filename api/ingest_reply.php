<?php
/**
 * api/ingest_reply.php — authenticated ingestion seam for inbound reply
 * classification (Phase 4's n8n automation posts here).
 *
 * Request (POST, application/json):
 *   {
 *     "subject":        "Re: your proposal",   // required, max 500 chars
 *     "body":           "Thanks, let's talk…", // required, max 20000 chars
 *     "thread_context": "…",                   // optional, max 20000 chars
 *     "from":           "buyer@example.com"    // optional, must be a valid email
 *   }
 *
 * Response 200 (routing is NEVER auto-applied — the caller decides):
 *   {
 *     "success": true,
 *     "verdict": {
 *       "intent": "positive",
 *       "needs_human": true,
 *       "urgency": 8,            // 1-10 int from ClassifyReplyAction
 *       "confidence": 0.82,
 *       "source": "jev",
 *       "latency_ms": 1234,
 *       "note": null              // heuristic verdicts carry a human-readable note instead
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
 *   500 {"success":false,"error":"…"}                         — classifier failure
 *
 * Every request is appended to logs/ingest_replies.jsonl.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();   // 401 JSON when unauthenticated

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

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);

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
if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) {
    ingest_error(400, 'Field "from" must be a valid email address', $baseLog);
}

try {
    $verdict = \App\ReplyIntake::classifyReply($subject, $body, $threadContext);
} catch (\Throwable $e) {
    ingest_error(500, 'Classification failed: ' . $e->getMessage(), $baseLog);
}

$baseLog['verdict'] = $verdict;
\App\ReplyIntake::logIntake($baseLog);

echo json_encode([
    'success' => true,
    'verdict' => $verdict,
    'routed'  => false,
    'meta'    => [
        'received_at'    => $baseLog['received_at'],
        'subject_length' => strlen($subject),
        'body_length'    => strlen($body),
        'from_provided'  => $from !== '',
    ],
]);
