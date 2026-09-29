<?php
/**
 * Human review queue API (goal_67693fcbba4c, review workflow).
 *
 * All actions require an authenticated session (human reviewers only —
 * no API-key bypass, so review_decisions.decided_by always names the
 * logged-in user). State-changing actions additionally require CSRF.
 *
 * Actions (allowlist: \App\ReviewQueue::ACTIONS):
 *   GET  api/review.php?action=list[&filter=queue|decided][&limit=&offset=]
 *        paginated 'Needs Review' leads with per-dimension scores and fit
 *        score (filter=queue, default), or the decided history with
 *        who/when per item (filter=decided).
 *   POST api/review.php?action=approve      {lead_id}  -> Qualified
 *   POST api/review.php?action=disqualify   {lead_id}  -> Unqualified
 *
 * POST bodies accept JSON (Content-Type: application/json) or form fields.
 * The CSRF token travels via the X-CSRF-Token header (preferred) or a
 * `csrf_token` body field. approve/disqualify are guarded: the lead must
 * actually be in 'Needs Review' (409 otherwise); a re-POST of an already
 * recorded decision returns {already_decided: true} without duplicating
 * the audit row.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';

// Auth first, before any input is parsed or any work is done.
\App\Auth::requireApiAuth(); // 401 JSON when not logged in; no API-key path
$pdo = \App\Database::getConnection();

function review_error(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function review_input(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = $method === 'POST' ? review_input() : [];
$action = (string)($_GET['action'] ?? $input['action'] ?? 'list');

if (!in_array($action, \App\ReviewQueue::ACTIONS, true)) {
    review_error(400, 'Unknown action. Allowed: ' . implode(', ', \App\ReviewQueue::ACTIONS));
}

try {
    if ($action === 'list') {
        $filter = (string)($_GET['filter'] ?? 'list');
        if ($filter === 'list') {
            $filter = \App\ReviewQueue::FILTER_QUEUE; // accept filter=list as the default alias
        }
        if (!in_array($filter, [\App\ReviewQueue::FILTER_QUEUE, \App\ReviewQueue::FILTER_DECIDED], true)) {
            review_error(400, "Invalid filter. Allowed: queue, decided");
        }
        // The decided history reads the audit table; the pending queue does not.
        if ($filter === \App\ReviewQueue::FILTER_DECIDED && !\App\ReviewQueue::auditTableExists($pdo)) {
            review_error(503, 'Review queue not migrated: apply migrations/2026-09-28-review-decisions.sql');
        }
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        $result = \App\ReviewQueue::listQueue($pdo, $filter, $limit, $offset);
        echo json_encode(['success' => true, 'filter' => $filter] + $result);
        exit;
    }

    // approve / disqualify: state-changing -> CSRF enforced before any write.
    if ($method !== 'POST') {
        review_error(405, 'This action requires POST');
    }
    \App\Auth::requireCsrf(); // 403 JSON on missing/invalid token

    // Writes are transactional with the audit row: refuse on a pre-migration
    // install rather than changing a status with no audit trail.
    if (!\App\ReviewQueue::auditTableExists($pdo)) {
        review_error(503, 'Review queue not migrated: apply migrations/2026-09-28-review-decisions.sql');
    }

    $leadIdRaw = $input['lead_id'] ?? null;
    if (!is_scalar($leadIdRaw) || !ctype_digit((string)$leadIdRaw) || (int)$leadIdRaw <= 0) {
        review_error(400, 'Missing or invalid lead_id');
    }
    $leadId = (int)$leadIdRaw;

    $decision = $action === 'approve'
        ? \App\ReviewQueue::DECISION_APPROVED
        : \App\ReviewQueue::DECISION_DISQUALIFIED;

    $decidedBy = \App\Auth::currentUsername();
    if ($decidedBy === null || $decidedBy === '') {
        review_error(401, 'Authentication required');
    }

    $outcome = \App\ReviewQueue::transition($pdo, $leadId, $decision, $decidedBy);
    echo json_encode(['success' => true] + $outcome);
    exit;
} catch (\App\Exceptions\OutreachException $e) {
    $msg = $e->getMessage();
    // Transition-guard violations are client conflicts, not server errors.
    $code = str_contains($msg, 'not awaiting review')
        || str_contains($msg, 'changed state during review')
        || str_contains($msg, 'not found')
        ? 409 : 400;
    review_error($code, $msg);
} catch (\Throwable $e) {
    error_log('[api/review.php] ' . $e->getMessage());
    review_error(500, 'Review request failed');
}
