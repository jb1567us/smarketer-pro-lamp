#!/usr/bin/env php
<?php
/**
 * MentionValidity predicate unit tests (D2-P1).
 *
 * Zero network, zero DB. Everything under test is a public static on
 * App\Evidence\MentionValidity:
 *   1. Happy path: known source + tier<=3 + fresh proof_of + scope covers
 *      -> allowed.
 *   2. Each failing gate: unknown source, tier 4, missing/stale evidence,
 *      scope mismatch, invalid input -> NOT allowed, with the right
 *      failed_check code.
 *   3. Recency boundary: 24mo passes, 25mo fails (RECENCY_BOUND_MONTHS).
 *   4. Scope ladder: broader covers narrower; equal covers; unknown scope
 *      values fail closed.
 *   5. Flag-not-drop: failing verdicts produce a Needs Review flag payload
 *      and notes marker; flagPayload() on a passing verdict throws loudly
 *      instead of silently queuing a bogus flag.
 *
 * Usage: php tests/evidence/test_mention_validity_unit.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\Evidence\MentionValidity;
use App\Evidence\SourceAuthority;

$pass = 0;
$fail = 0;
function mv_check(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  PASS: {$name}\n";
    } else {
        $fail++;
        echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/** Baseline mention that passes; tests override fields. */
function baseMention(array $overrides = []): array
{
    return array_merge([
        'claim' => 'Local HVAC shops grew 12% last year.',
        'source_id' => 'achr-news',
        'source' => [
            'source_kind' => 'trade_publication',
            'named_editorial_staff' => true,
            'corrections_policy' => true,
            'years_publishing' => 40,
            'authorship_verifiable' => true,
            'publication_date_verifiable' => true,
        ],
        'known_source' => true,
        'proof_of' => ['evidence_ts' => '2026-04-15', 'url' => 'https://example.com/article'],
        'source_scope' => 'national',
        'target_scope' => 'local',
        'as_of' => '2026-10-01',
    ], $overrides);
}

// --- 1. Happy path ------------------------------------------------------------
echo "1. happy path:\n";
$v = MentionValidity::evaluate(baseMention());
mv_check('all gates pass -> allowed', $v['allowed'] === true, json_encode($v['failed_checks']));
mv_check('no failed checks', $v['failed_checks'] === []);
mv_check('tier recorded as 2', $v['tier'] === 2, json_encode($v));
mv_check('4 checks evaluated', count($v['checks']) === 4, (string)count($v['checks']));
mv_check(
    'all check details non-empty',
    array_reduce($v['checks'], fn ($c, $x) => $c && $x['detail'] !== '', true)
);

// --- 2. Failing gates ----------------------------------------------------------
echo "2. failing gates:\n";

$v = MentionValidity::evaluate(baseMention(['known_source' => false]));
mv_check(
    'unknown source -> not allowed, unknown_source code',
    $v['allowed'] === false && in_array(MentionValidity::FAIL_UNKNOWN_SOURCE, $v['failed_checks'], true),
    json_encode($v['failed_checks'])
);

$v = MentionValidity::evaluate(baseMention([
    'source' => [
        'source_kind' => 'blog_newsletter',
        'authorship_verifiable' => true,
        'publication_date_verifiable' => true,
        'content_farm' => true,
    ],
]));
mv_check(
    'tier-4 source -> not allowed, tier_4_never_servable code',
    $v['allowed'] === false && $v['tier'] === 4
        && in_array(MentionValidity::FAIL_TIER_4, $v['failed_checks'], true),
    json_encode($v['failed_checks'])
);

$v = MentionValidity::evaluate(baseMention(['proof_of' => ['url' => 'https://example.com/x']]));
mv_check(
    'missing evidence_ts -> not allowed, missing_evidence code',
    $v['allowed'] === false && in_array(MentionValidity::FAIL_MISSING_PROOF, $v['failed_checks'], true),
    json_encode($v['failed_checks'])
);

$v = MentionValidity::evaluate(baseMention(['proof_of' => ['evidence_ts' => '2022-01-01']]));
mv_check(
    'stale evidence (57mo) -> not allowed, stale_evidence code',
    $v['allowed'] === false && in_array(MentionValidity::FAIL_STALE_PROOF, $v['failed_checks'], true),
    json_encode($v['failed_checks'])
);

$v = MentionValidity::evaluate(baseMention(['source_scope' => 'local', 'target_scope' => 'national']));
mv_check(
    'local source / national target -> not allowed, scope_mismatch code',
    $v['allowed'] === false && in_array(MentionValidity::FAIL_SCOPE, $v['failed_checks'], true),
    json_encode($v['failed_checks'])
);

$v = MentionValidity::evaluate(baseMention(['source_id' => '']));
mv_check(
    'empty source_id -> not allowed, invalid_input code',
    $v['allowed'] === false && in_array(MentionValidity::FAIL_INVALID_INPUT, $v['failed_checks'], true),
    json_encode($v['failed_checks'])
);

$v = MentionValidity::evaluate(['claim' => 'garbage input']);
mv_check(
    'structurally incomplete mention -> invalid_input',
    $v['allowed'] === false && $v['failed_checks'] === [MentionValidity::FAIL_INVALID_INPUT],
    json_encode($v)
);

$v = MentionValidity::evaluate(baseMention([
    'known_source' => false,
    'source_scope' => 'local',
    'target_scope' => 'national',
    'proof_of' => ['evidence_ts' => '2020-05-05'],
]));
mv_check(
    'multiple failures all reported (never first-failure-only)',
    $v['allowed'] === false && count($v['failed_checks']) === 3
        && in_array(MentionValidity::FAIL_UNKNOWN_SOURCE, $v['failed_checks'], true)
        && in_array(MentionValidity::FAIL_STALE_PROOF, $v['failed_checks'], true)
        && in_array(MentionValidity::FAIL_SCOPE, $v['failed_checks'], true),
    json_encode($v['failed_checks'])
);

// --- 3. Recency boundary -------------------------------------------------------
echo "3. recency boundary (24mo):\n";
$v = MentionValidity::evaluate(baseMention(['proof_of' => ['evidence_ts' => '2024-10-01']]));
mv_check('evidence exactly 24mo old -> allowed', $v['allowed'] === true, json_encode($v['failed_checks']));
$v = MentionValidity::evaluate(baseMention(['proof_of' => ['evidence_ts' => '2024-09-01']]));
mv_check(
    'evidence 25mo old -> stale, flagged',
    $v['allowed'] === false && in_array(MentionValidity::FAIL_STALE_PROOF, $v['failed_checks'], true),
    json_encode($v['failed_checks'])
);
$v = MentionValidity::evaluate(baseMention(['proof_of' => ['evidence_ts' => 'not-a-date']]));
mv_check('unparseable evidence_ts -> missing_evidence (fail closed)', in_array(
    MentionValidity::FAIL_MISSING_PROOF, $v['failed_checks'], true
));
$v = MentionValidity::evaluate(baseMention(['proof_of' => ['evidence_ts' => '2027-01-01']]));
mv_check('future evidence_ts -> missing_evidence (fail closed)', in_array(
    MentionValidity::FAIL_MISSING_PROOF, $v['failed_checks'], true
));
mv_check('monthsOld boundary: 2024-10-01 -> 24', MentionValidity::monthsOld('2024-10-01', '2026-10-01') === 24);
mv_check('monthsOld day-edge: 2026-09-02 vs 2026-10-01 -> 0', MentionValidity::monthsOld('2026-09-02', '2026-10-01') === 0);
mv_check('monthsOld future -> null', MentionValidity::monthsOld('2027-01-01', '2026-10-01') === null);
mv_check('RECENCY_BOUND_MONTHS === 24', MentionValidity::RECENCY_BOUND_MONTHS === 24);

// --- 4. Scope ladder -----------------------------------------------------------
echo "4. scope ladder:\n";
mv_check('equal scopes cover', MentionValidity::scopeCovers('local', 'local'));
mv_check('national covers local', MentionValidity::scopeCovers('national', 'local'));
mv_check('online-global covers national', MentionValidity::scopeCovers('online-global', 'national'));
mv_check('local does NOT cover national', !MentionValidity::scopeCovers('local', 'national'));
mv_check('state does NOT cover regional', !MentionValidity::scopeCovers('state', 'regional'));
mv_check('unknown source scope fails closed', !MentionValidity::scopeCovers('mars', 'local'));
mv_check('unknown target scope fails closed', !MentionValidity::scopeCovers('national', 'mars'));
$v = MentionValidity::evaluate(baseMention(['source_scope' => 'mars', 'target_scope' => 'local']));
mv_check(
    'unknown source scope value -> scope_mismatch (fail closed)',
    $v['allowed'] === false && in_array(MentionValidity::FAIL_SCOPE, $v['failed_checks'], true)
);

// --- 5. Flag-not-drop ----------------------------------------------------------
echo "5. flag-not-drop (human review routing):\n";
$bad = baseMention(['known_source' => false, 'proof_of' => ['evidence_ts' => '2020-01-01']]);
$v = MentionValidity::evaluate($bad);
$payload = MentionValidity::flagPayload($v, $bad);
mv_check("flag kind is 'evidence_flag'", $payload['kind'] === 'evidence_flag');
mv_check(
    "flag carries Needs Review semantics",
    $payload['review_status'] === \App\ReviewQueue::REVIEW_STATUS
        && $payload['review_status'] === 'Needs Review',
    json_encode($payload['review_status'])
);
mv_check('flag preserves the claim (never silently dropped)', $payload['claim'] === $bad['claim']);
mv_check(
    'flag carries failed check codes',
    $payload['failed_checks'] === [MentionValidity::FAIL_UNKNOWN_SOURCE, MentionValidity::FAIL_STALE_PROOF],
    json_encode($payload['failed_checks'])
);
mv_check('flag explanation is non-empty', $payload['explanation'] !== '');
mv_check('flag suggested_action is non-empty', $payload['suggested_action'] !== '');
mv_check('flag tier reflects the grade', $payload['graded_tier'] === 2);

$marker = MentionValidity::notesMarker($v, $bad, '2026-10-01');
mv_check('notes marker starts with blank lines + [EvidenceFlag date]', str_starts_with($marker, "\n\n[EvidenceFlag 2026-10-01]"));
mv_check('notes marker says NOT servable', str_contains($marker, 'NOT servable'));
mv_check('notes marker names Needs Review', str_contains($marker, 'Needs Review'));
mv_check('notes marker lists failed codes', str_contains($marker, 'unknown_source'));

$threw = false;
try {
    MentionValidity::flagPayload(MentionValidity::evaluate(baseMention()), baseMention());
} catch (\InvalidArgumentException) {
    $threw = true;
}
mv_check('flagPayload() on a passing verdict throws (loud, not silent)', $threw);

// Flag payload tier must agree with the standalone grader.
mv_check(
    'verdict tier agrees with SourceAuthority::grade',
    MentionValidity::evaluate($bad)['tier'] === SourceAuthority::grade($bad['source'])
);

echo "  -- test_mention_validity_unit.php: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
