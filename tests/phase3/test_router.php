#!/usr/bin/env php
<?php
/**
 * Phase 3 test: ReplyRouter routing outcomes per intent + guardrails.
 *
 * Covers check categories (d) routing outcomes per intent and the
 * fail-closed guardrails:
 *   1. Each intent routes to its documented action (positive →
 *      human_follow_up, objection/referral/other → human_review,
 *      not_now → nurtured, bounce/out_of_office → auto_handled,
 *      unsubscribe → suppressed, hostile → archived).
 *   2. Guardrail 1: missing leadId AND/OR email → human_review, no crash.
 *   3. Guardrail 2: needs_human=true OR confidence < 0.55 → human_review
 *      for every intent — including heuristic-shaped verdicts
 *      (confidence 0.0, source 'heuristic'), which is the whole off-mode
 *      population. This fail-closed behavior is asserted deliberately.
 *   4. Lead id is resolved from email when only the email is known.
 *   5. Leads.status writes use only the existing ENUM values; notes are
 *      appended, never overwritten.
 *
 * unsubscribe/hostile cases call Compliance::suppress() → live DB. Without
 * a DB connection they SKIP (not FAIL).
 *
 * Usage: php tests/phase3/test_router.php
 * Needs: no DB, no network (except the two DB-dependent suppression cases).
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require phase3_repo_root() . '/includes/autoload.php';
require __DIR__ . '/fixtures.php';
require __DIR__ . '/fake_pdo.php';

use App\ReplyRouter;

/** Build a high-confidence auto-routable verdict for $intent. */
function autoVerdict(string $intent, int $urgency): array
{
    return [
        'intent'      => $intent,
        'needs_human' => false,
        'urgency'     => $urgency,
        'confidence'  => 1.0,
        'source'      => 'jev',
        'latency_ms'  => 12,
    ];
}

function statusWrites(FakePdo $pdo): array
{
    $out = [];
    foreach ($pdo->prepared as $s) {
        if (stripos($s->query, 'UPDATE leads SET status') !== false) {
            foreach ($s->executions as $params) {
                $out[] = $params[0] ?? null;
            }
        }
    }
    return $out;
}

function noteAppends(FakePdo $pdo): array
{
    $out = [];
    foreach ($pdo->prepared as $s) {
        if (stripos($s->query, 'UPDATE leads SET notes') !== false) {
            foreach ($s->executions as $params) {
                $out[] = $params[0] ?? '';
            }
        }
    }
    return $out;
}

$ALLOWED_STATUSES = ['New', 'Enriched', 'Contacted', 'Qualified', 'Unqualified', 'Converted', 'Drafted'];

// --- (d) routing outcomes per intent ---------------------------------------
foreach ($ROUTING_FIXTURES as $fx) {
    [$intent, $urgency, $expAction, $expHuman] = $fx;
    $pdo = new FakePdo();
    $router = new ReplyRouter($pdo);
    try {
        $res = $router->route(autoVerdict($intent, $urgency), 7, 'buyer@example.com');
    } catch (\Throwable $e) {
        if (in_array($intent, ['unsubscribe', 'hostile'], true)) {
            phase3_skip("routing {$intent} → {$expAction}",
                'Compliance::suppress() needs a live DB: ' . $e->getMessage());
            continue;
        }
        check("routing {$intent} does not throw", false, $e->getMessage());
        continue;
    }

    check("routing {$intent} → action={$expAction}", $res['action'] === $expAction,
        "got '{$res['action']}'");
    check("routing {$intent} routed_to_human=" . var_export($expHuman, true),
        $res['routed_to_human'] === $expHuman, 'got ' . var_export($res['routed_to_human'], true));
    check("routing {$intent} echoes intent", $res['intent'] === $intent);
    check("routing {$intent} echoes lead_id", $res['lead_id'] === 7);
    check("routing {$intent} writes audit trace",
        $pdo->sawSql("INSERT INTO agent_traces") && $pdo->sawSql('reply_routing_audit'));

    foreach (statusWrites($pdo) as $st) {
        check("routing {$intent} status '{$st}' is an existing ENUM value",
            in_array($st, $ALLOWED_STATUSES, true), 'not in ENUM');
    }
}

// Positive specifics: Qualified + owner-follow-up queue entry.
{
    $pdo = new FakePdo();
    (new ReplyRouter($pdo))->route(autoVerdict('positive', 8), 7, 'buyer@example.com');
    check('positive marks lead Qualified', in_array('Qualified', statusWrites($pdo), true));
    $notes = implode("\n", noteAppends($pdo));
    check('positive queues owner follow-up in human-review queue',
        stripos($notes, 'HUMAN REVIEW') !== false && $pdo->sawSql('reply_review'));
}

// not_now specifics: Contacted + +90d target recorded in notes.
{
    $pdo = new FakePdo();
    (new ReplyRouter($pdo))->route(autoVerdict('not_now', 2), 7, 'buyer@example.com');
    check('not_now marks lead Contacted (no nurture ENUM exists)',
        in_array('Contacted', statusWrites($pdo), true));
    $notes = implode("\n", noteAppends($pdo));
    $target = date('Y-m-d', strtotime('+90 days'));
    check("not_now records +90d re-contact target {$target} in notes",
        strpos($notes, $target) !== false, 'notes: ' . substr($notes, 0, 120));
}

// bounce/out_of_office: status untouched, notes+trace only.
{
    foreach (['bounce', 'out_of_office'] as $intent) {
        $pdo = new FakePdo();
        (new ReplyRouter($pdo))->route(autoVerdict($intent, 1), 7, 'buyer@example.com');
        check("{$intent} leaves leads.status untouched", statusWrites($pdo) === []);
        check("{$intent} still writes notes + audit trace",
            noteAppends($pdo) !== [] && $pdo->sawSql('reply_routing_audit'));
    }
}

// --- Guardrail 1: missing identity → fail-closed human review ---------------
{
    $pdo = new FakePdo();
    $res = (new ReplyRouter($pdo))->route(autoVerdict('unsubscribe', 1), null, null);
    check('guardrail 1: null lead + null email → human_review',
        $res['action'] === 'human_review' && $res['routed_to_human'] === true);

    $pdo = new FakePdo();
    $res = (new ReplyRouter($pdo))->route(autoVerdict('positive', 8), 7, null);
    check('guardrail 1: missing email → human_review',
        $res['action'] === 'human_review' && $res['routed_to_human'] === true);

    $pdo = new FakePdo(); // no email→lead mapping: lookup misses
    $res = (new ReplyRouter($pdo))->route(autoVerdict('positive', 8), null, 'ghost@example.com');
    check('guardrail 1: unknown email resolves to no lead → human_review',
        $res['action'] === 'human_review' && $res['lead_id'] === null);
}

// --- Guardrail 2: needs_human / low confidence → human review ---------------
foreach (['positive', 'unsubscribe', 'not_now', 'hostile', 'bounce', 'other'] as $intent) {
    $pdo = new FakePdo();
    $v = autoVerdict($intent, 5);
    $v['needs_human'] = true;
    $res = (new ReplyRouter($pdo))->route($v, 7, 'buyer@example.com');
    check("guardrail 2: needs_human=true forces human_review ({$intent})",
        $res['action'] === 'human_review');

    $pdo = new FakePdo();
    $v = autoVerdict($intent, 5);
    $v['confidence'] = 0.4;
    $res = (new ReplyRouter($pdo))->route($v, 7, 'buyer@example.com');
    check("guardrail 2: confidence 0.4 < 0.55 forces human_review ({$intent})",
        $res['action'] === 'human_review');
}

// --- Off-mode population: heuristic verdicts never auto-route -------------
// Heuristic results always carry confidence 0.0, so in JEV-off mode every
// reply — including unsubscribe and hostile — lands in human review.
foreach (['unsubscribe', 'hostile', 'bounce', 'positive'] as $intent) {
    $pdo = new FakePdo();
    $res = (new ReplyRouter($pdo))->route([
        'intent' => $intent, 'needs_human' => false, 'urgency' => 1,
        'confidence' => 0.0, 'source' => 'heuristic', 'latency_ms' => 3,
        'note' => 'Jev unavailable or off; keyword heuristics only.',
    ], 7, 'buyer@example.com');
    check("off-mode fail-closed: heuristic {$intent} (conf 0.0) → human_review",
        $res['action'] === 'human_review' && $res['routed_to_human'] === true,
        "got '{$res['action']}'");
}

// --- Lead id resolved from email -------------------------------------------
{
    $pdo = new FakePdo();
    $pdo->emailToLeadId = ['buyer@example.com' => 42];
    $res = (new ReplyRouter($pdo))->route(autoVerdict('bounce', 1), null, 'Buyer@Example.com');
    check('lead id resolved from email (case-insensitive)', $res['lead_id'] === 42,
        'got ' . var_export($res['lead_id'], true));
    check('resolved lead auto-handles bounce', $res['action'] === 'auto_handled');
}

// --- Router never auto-sends: no EmailSender/OutreachAction references -------
{
    $src = file_get_contents(phase3_repo_root() . '/includes/ReplyRouter.php');
    check('ReplyRouter never sends: no EmailSender reference', stripos($src, 'EmailSender') === false);
    check('ReplyRouter never sends: no mail() call', stripos($src, 'mail(') === false);
    check('ReplyRouter never sends: no ->send( call', stripos($src, '->send(') === false);
}

exit(phase3_summary('test_router.php'));
