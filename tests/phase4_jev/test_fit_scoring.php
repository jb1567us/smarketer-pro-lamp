#!/usr/bin/env php
<?php
/**
 * Phase 4 JEV tests: weighted ICP fit scoring (subject 2/4, goal_67693fcbba4c).
 *
 * Covers ScoreLeadFitAction + the QualifyLeadAction rewrite that consumes it.
 * Zero network, zero DB:
 *   1. buildFitQuestions: one batched score question per ENABLED dimension
 *      (five for the default-off fixture — tech_stack is toggleable and
 *      disabled by default), 1-10 criteria, each referencing its dimension's
 *      target_config; the enabled tech_stack question carries the
 *      discoverability rubric.
 *   2. Hard veto (industry/company/domain/title/keyword): fit 0, no Jev call.
 *   3. normalizeJevAnswers: 1-10 -> 0-100 per dimension, weighted fit,
 *      threshold bands (qualified / needs_review / unqualified).
 *   4. off mode: legacy result returned, Jev never called (score() level).
 *   5. No usable ICP profile: legacy path, Jev never called.
 *   6. Shadow/live contracts at the DecisionTier level using the exact
 *      production shadowExtract/shadowAgree closures (the actions'
 *      timeout-override path builds its own provider, so a scripted provider
 *      is exercised without the override — same as test_decision_modes.php):
 *      per-dimension scores + weighted fit + agreement land in jev_shadow.jsonl.
 *   7. statusForVerdict: review band maps to the real 'needs_review'
 *      leads.status ENUM value (fail-closed: allowlist-gated out of every
 *      sequence path until a human approves the lead to 'Qualified').
 *   8. notesMarker formats: legacy byte-identical, jev with dimension
 *      breakdown, needs-review marker, veto marker.
 *   9. QualifyLeadAction::execute veto path end-to-end (fake PDO): status,
 *      lead_score, appended notes, existing notes preserved; missing lead
 *      throws.
 *
 * Usage: php tests/phase4_jev/test_fit_scoring.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require p4j_repo_root() . '/includes/autoload.php';
require_once __DIR__ . '/fake_pdo.php';

use App\Actions\QualifyLeadAction;
use App\Actions\ScoreLeadFitAction;
use App\Icp\IcpProfile;
use App\Jev\DecisionTier;
use App\Routers\SmartLLMRouter;

/** Scripted provider: answers queues or Throwables; records calls. */
class FitFakeJev extends \App\Jev\JevProvider
{
    public static array $script = [];
    public static int $calls = 0;
    public static ?array $lastCall = null;
    public function __construct() {} // skip key requirement
    public function systemOne($state, array $questions): array
    {
        self::$calls++;
        self::$lastCall = ['state' => $state, 'questions' => $questions];
        $next = array_shift(self::$script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
    public static function reset(): void
    {
        self::$script = [];
        self::$calls = 0;
        self::$lastCall = null;
    }
}

/** Fake PDO scripting the leads SELECT; captures the UPDATE params. */
class FitFakePdo extends FakePdo
{
    /** @var array<string,mixed>|null */
    public ?array $leadRow = null;
    public function prepare(string $query): \App\PDOStatement|false
    {
        $rows = [];
        if (stripos($query, 'SELECT * FROM leads WHERE id = ?') !== false && $this->leadRow !== null) {
            $rows = [$this->leadRow];
        }
        $stmt = FakeStatement::make($query, $rows);
        $this->prepared[] = $stmt;
        return $stmt;
    }
    /** Params of the leads UPDATE statement, or null. */
    public function updateParams(): ?array
    {
        foreach (array_reverse($this->prepared) as $stmt) {
            if (stripos($stmt->query, 'UPDATE leads SET lead_score') !== false && $stmt->executions !== []) {
                return $stmt->executions[0];
            }
        }
        return null;
    }
}

/** Resolved-profile snapshot (same shape as ScoreLeadFitAction::resolveProfile).
 *  Models the production default: tech_stack present but DISABLED. */
function fitProfile(array $overrides = []): array
{
    $dims = [];
    foreach (IcpProfile::DIMENSIONS as $d) {
        $dims[$d] = [
            'weight' => $d === 'tech_stack' ? 0 : 20,
            'buyer_locked' => false,
            'enabled' => $d !== 'tech_stack',
            'target_config' => [],
        ];
    }
    $base = [
        'id' => 1,
        'key' => 'Test ICP',
        'dimensions' => $dims,
        'weights' => [
            'company_size' => 20, 'industry_fit' => 20,
            'target_title' => 20, 'geography' => 20, 'trigger_signals' => 20,
            'tech_stack' => 0,
        ],
        'exclusions' => [],
        'thresholds' => ['qualify' => 75, 'review' => 50],
    ];
    return array_merge($base, $overrides);
}

function fitLead(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'company_name' => 'Acme Logistics',
        'contact_name' => 'Jane Ops',
        'email' => 'jane@acmelogistics.com',
        'website' => 'https://acmelogistics.com',
        'target_persona' => 'VP Operations',
        'country_code' => 'US',
        'notes' => 'Mid-market logistics firm, 250 employees. Prior enrichment research here.',
        'status' => 'Enriched',
        'lead_score' => 0,
    ], $overrides);
}

/** @param array<string,float> $positions dimension key => Jev position 0..9 */
function dimAnswers(array $positions, float $conf = 0.9): array
{
    $a = [];
    foreach (IcpProfile::DIMENSIONS as $d) {
        $a['dim_' . $d] = [
            'score' => $positions[$d] ?? 0.0,
            'confidence' => $conf,
            'probabilities' => [],
        ];
    }
    return $a;
}

function fitScorer(): ScoreLeadFitAction
{
    $ref = new ReflectionClass(ScoreLeadFitAction::class);
    /** @var ScoreLeadFitAction $a */
    $a = $ref->newInstanceWithoutConstructor();
    return $a;
}

function fitQualifier(FitFakePdo $pdo): QualifyLeadAction
{
    $ref = new ReflectionClass(QualifyLeadAction::class);
    /** @var QualifyLeadAction $a */
    $a = $ref->newInstanceWithoutConstructor();
    foreach (['pdo' => $pdo, 'llmRouter' => new SmartLLMRouter($pdo)] as $prop => $val) {
        $p = $ref->getParentClass()->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($a, $val);
    }
    return $a;
}

$legacyFn = fn() => ['qualified' => true, 'score' => 80, 'reason' => 'legacy verdict', 'source' => 'llm'];
$prof = fitProfile();
$weights = $prof['weights'];
$thresholds = $prof['thresholds'];
// The fixture models the production default: tech_stack disabled, so the
// score() path asks five questions and the shadow record carries five pcts.
$enabledFive = ScoreLeadFitAction::enabledKeys($prof['dimensions']);
ScoreLeadFitAction::$profileOverride = $prof;

// --- 1. Question building ----------------------------------------------------
$qs = ScoreLeadFitAction::buildFitQuestions($prof['dimensions']);
check('buildFitQuestions emits five batched questions', count($qs) === 5);
$keysOk = true;
foreach (ScoreLeadFitAction::enabledKeys($prof['dimensions']) as $d) {
    $q = $qs['dim_' . $d] ?? null;
    if (!is_array($q) || ($q['type'] ?? '') !== 'score' || count($q['criteria'] ?? []) !== 10) {
        $keysOk = false;
    }
}
check('each enabled dimension gets a 10-level score question', $keysOk);
// The disabled tech_stack gets no question at all (zero tokens).
check('disabled tech_stack gets no question',
    !isset($qs['dim_tech_stack']));
check('criteria run 1 (worst) to 10 (best)',
    str_starts_with((string)($qs['dim_company_size']['criteria'][0] ?? ''), '1 —')
    && str_starts_with((string)($qs['dim_company_size']['criteria'][9] ?? ''), '10 —'));
check('instructions forbid inventing evidence on thin evidence',
    stripos((string)($qs['dim_company_size']['instructions'] ?? ''), 'never invent evidence') !== false);

$profTargets = fitProfile();
$profTargets['dimensions']['target_title']['target_config'] = ['titles' => ['VP Sales', 'CMO']];
$qs2 = ScoreLeadFitAction::buildFitQuestions($profTargets['dimensions']);
check("question instructions reference the dimension's target_config",
    stripos((string)($qs2['dim_target_title']['instructions'] ?? ''), 'VP Sales') !== false);

// --- 2. Hard veto ------------------------------------------------------------
p4j_inject_tier(['enabled' => false, 'mode' => 'off'], new FitFakeJev());
$vetoCases = [
    'industry exclusion matches enrichment notes' => [
        [['exclusion_type' => 'industry', 'value' => 'gambling', 'note' => 'compliance']],
        fitLead(['notes' => 'They run a gambling affiliate portal.']),
    ],
    'company exclusion matches company_name' => [
        [['exclusion_type' => 'company', 'value' => 'acme']],
        fitLead(),
    ],
    'domain exclusion matches email domain' => [
        [['exclusion_type' => 'domain', 'value' => 'spamco.com']],
        fitLead(['email' => 'x@spamco.com', 'website' => 'https://example.com']),
    ],
    'domain exclusion matches website subdomain' => [
        [['exclusion_type' => 'domain', 'value' => 'spamco.com']],
        fitLead(['email' => 'x@other.com', 'website' => 'https://mail.spamco.com/path']),
    ],
    'title exclusion matches contact title context' => [
        [['exclusion_type' => 'title', 'value' => 'intern']],
        fitLead(['contact_name' => 'Jane Intern']),
    ],
    'keyword exclusion matches anywhere' => [
        [['exclusion_type' => 'keyword', 'value' => 'crypto casino']],
        fitLead(['notes' => 'Mentions a crypto casino side project.']),
    ],
];
foreach ($vetoCases as $name => [$exclusions, $lead]) {
    FitFakeJev::reset();
    ScoreLeadFitAction::$profileOverride = fitProfile(['exclusions' => $exclusions]);
    $r = fitScorer()->score($lead, $legacyFn);
    check("veto: {$name} -> fit 0 / unqualified",
        $r['fit_score'] === 0 && $r['qualified'] === false && $r['verdict'] === 'unqualified');
    check("veto: {$name} -> source 'veto', no Jev call",
        $r['source'] === 'veto' && FitFakeJev::$calls === 0 && $r['veto'] !== null);
    check("veto: {$name} -> reason cites the exclusion",
        stripos($r['reason'], 'EXCLUDED by ICP exclusion') !== false
        && stripos($r['reason'], (string)$exclusions[0]['value']) !== false);
}
ScoreLeadFitAction::$profileOverride = $prof;

// --- 3. Weighted math (normalizeJevAnswers, direct) ---------------------------
$all9 = array_fill_keys(IcpProfile::DIMENSIONS, 9.0);
$n = ScoreLeadFitAction::normalizeJevAnswers(dimAnswers($all9), $weights, $thresholds);
check('math: all-9 fit = 100, verdict qualified',
    $n['fit_score'] === 100 && $n['verdict'] === 'qualified' && $n['qualified'] === true);
check('math: all-9 dimensions are 10/10 with 100.0 pcts',
    $n['dimensions'] === array_fill_keys(IcpProfile::DIMENSIONS, 10)
    && $n['dimension_pcts'] === array_fill_keys(IcpProfile::DIMENSIONS, 100.0));

$all45 = array_fill_keys(IcpProfile::DIMENSIONS, 4.5);
$n = ScoreLeadFitAction::normalizeJevAnswers(dimAnswers($all45), $weights, $thresholds);
check('math: all-4.5 fit = 50, verdict needs_review',
    $n['fit_score'] === 50 && $n['verdict'] === 'needs_review' && $n['qualified'] === false);
check('math: position 4.5 -> 6/10 display, 50.0 pct',
    $n['dimensions']['company_size'] === 6 && $n['dimension_pcts']['company_size'] === 50.0);

$mixed = [
    'company_size' => 9.0, 'industry_fit' => 9.0,
    'target_title' => 9.0, 'geography' => 0.0, 'trigger_signals' => 0.0,
];
$n = ScoreLeadFitAction::normalizeJevAnswers(dimAnswers($mixed), $weights, $thresholds);
// (100*20 + 100*20 + 100*20 + 0*20 + 0*20) / 100 = 60
check('math: weighted fit applies profile weights (60, needs_review)',
    $n['fit_score'] === 60 && $n['verdict'] === 'needs_review');
check('math: position 0 clamps to 1/10 and 0.0 pct',
    $n['dimensions']['geography'] === 1 && $n['dimension_pcts']['geography'] === 0.0);

$all0 = array_fill_keys(IcpProfile::DIMENSIONS, 0.0);
$n = ScoreLeadFitAction::normalizeJevAnswers(dimAnswers($all0), $weights, $thresholds);
check('math: all-0 fit = 0, verdict unqualified',
    $n['fit_score'] === 0 && $n['verdict'] === 'unqualified');

$clamped = ScoreLeadFitAction::normalizeJevAnswers(
    dimAnswers(['company_size' => 99.0, 'industry_fit' => -5.0]), $weights, $thresholds);
check('math: out-of-range positions clamp to the 0-9 spectrum',
    $clamped['dimension_pcts']['company_size'] === 100.0
    && $clamped['dimension_pcts']['industry_fit'] === 0.0);

$n = ScoreLeadFitAction::normalizeJevAnswers(dimAnswers($all9, 0.7), $weights, $thresholds);
check('math: confidence = min of answer confidences', abs($n['confidence'] - 0.7) < 1e-9);

// --- 4. Off mode: legacy path exactly as before (score() level) ---------------
p4j_inject_tier(['enabled' => false, 'mode' => 'off'], new FitFakeJev());
FitFakeJev::reset();
$r = fitScorer()->score(fitLead(), $legacyFn);
check('off: legacy result returned', $r['qualified'] === true && $r['fit_score'] === 80);
check('off: source legacy, reason preserved',
    $r['source'] === 'legacy' && $r['reason'] === 'legacy verdict');
check('off: Jev provider never called', FitFakeJev::$calls === 0);
check('off: mode() reports off', DecisionTier::mode() === 'off');

// --- 5. No usable profile: fail-closed to legacy ------------------------------
ScoreLeadFitAction::$profileOverride = false; // force "no profile"
FitFakeJev::reset();
$r = fitScorer()->score(fitLead(), $legacyFn);
check('no profile: legacy result returned', $r['source'] === 'legacy' && $r['fit_score'] === 80);
check('no profile: Jev never called', FitFakeJev::$calls === 0);
check('no profile: reason notes the missing profile',
    stripos($r['reason'], 'no active ICP profile') !== false);
ScoreLeadFitAction::$profileOverride = $prof;

// --- 6a. Shadow at the DecisionTier level -------------------------------------
$shadowLog = rtrim(sys_get_temp_dir(), '/\\') . '/fit_shadow_' . getmypid() . '.jsonl';
@unlink($shadowLog);
p4j_inject_tier(['enabled' => true, 'mode' => 'shadow', 'shadow_log' => $shadowLog], new FitFakeJev());
FitFakeJev::reset();
$agreeLegacy = fn() => ['qualified' => true, 'score' => 95, 'reason' => 'legacy', 'source' => 'llm'];
FitFakeJev::$script = [dimAnswers($all9)];
$out = DecisionTier::decide(
    'lead_fit.score_fit',
    ['lead_context' => 'x'],
    ScoreLeadFitAction::buildFitQuestions($prof['dimensions']),
    $agreeLegacy,
    ScoreLeadFitAction::shadowExtract($weights, $thresholds, $enabledFive),
    ScoreLeadFitAction::shadowAgree($thresholds)
);
check('shadow: legacy result returned (zero behavior change)', $out === $agreeLegacy());
$rec = null;
foreach ((array)@file($shadowLog) as $line) {
    $row = json_decode($line, true);
    if (is_array($row) && ($row['decision'] ?? '') === 'lead_fit.score_fit') {
        $rec = $row;
    }
}
check('shadow: one JSONL record for lead_fit.score_fit', $rec !== null);
check('shadow: jev_value carries weighted fit + per-dimension pcts',
    $rec !== null
    && ($rec['jev_value']['fit_score'] ?? null) == 100
    && ($rec['jev_value']['verdict'] ?? '') === 'qualified'
    && count($rec['jev_value']['dimensions'] ?? []) === 5
    && ($rec['jev_value']['dimensions']['company_size'] ?? null) == 100.0);
check('shadow: agreement computed (fit 100 vs legacy 95 -> agree)',
    $rec !== null && ($rec['agree'] ?? null) === true);
check('shadow: raw jev_answers logged with the record',
    $rec !== null && isset($rec['jev_answers']['dim_company_size']['score']));
@unlink($shadowLog);

// shadow disagreement case: legacy says unqualified/low score
$shadowLog2 = rtrim(sys_get_temp_dir(), '/\\') . '/fit_shadow2_' . getmypid() . '.jsonl';
@unlink($shadowLog2);
p4j_inject_tier(['enabled' => true, 'mode' => 'shadow', 'shadow_log' => $shadowLog2], new FitFakeJev());
FitFakeJev::reset();
$disLegacy = fn() => ['qualified' => false, 'score' => 20, 'reason' => 'legacy', 'source' => 'llm'];
FitFakeJev::$script = [dimAnswers($all9)];
DecisionTier::decide(
    'lead_fit.score_fit',
    ['lead_context' => 'x'],
    ScoreLeadFitAction::buildFitQuestions($prof['dimensions']),
    $disLegacy,
    ScoreLeadFitAction::shadowExtract($weights, $thresholds, $enabledFive),
    ScoreLeadFitAction::shadowAgree($thresholds)
);
$rec2 = null;
foreach ((array)@file($shadowLog2) as $line) {
    $row = json_decode($line, true);
    if (is_array($row) && ($row['decision'] ?? '') === 'lead_fit.score_fit') {
        $rec2 = $row;
    }
}
check('shadow: disagreement logged when verdicts diverge',
    $rec2 !== null && ($rec2['agree'] ?? null) === false
    && ($rec2['llm_value']['verdict'] ?? '') === 'unqualified');
@unlink($shadowLog2);

// --- 6b. Live at the DecisionTier level ---------------------------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new FitFakeJev());
FitFakeJev::reset();
FitFakeJev::$script = [dimAnswers($all9, 0.9)];
$answers = DecisionTier::decide(
    'lead_fit.score_fit',
    ['lead_context' => 'x'],
    ScoreLeadFitAction::buildFitQuestions($prof['dimensions']),
    $legacyFn,
    ScoreLeadFitAction::shadowExtract($weights, $thresholds, $enabledFive),
    ScoreLeadFitAction::shadowAgree($thresholds)
);
check('live: Jev answers returned, not the legacy fallback',
    is_array($answers) && isset($answers['dim_company_size']['score']));
$norm = ScoreLeadFitAction::normalizeJevAnswers($answers, $weights, $thresholds);
check('live: answers normalize to qualified / fit 100',
    $norm['qualified'] === true && $norm['fit_score'] === 100);

p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new FitFakeJev());
FitFakeJev::reset();
FitFakeJev::$script = [dimAnswers($all9, 0.5)]; // below min_confidence 0.65
$answers = DecisionTier::decide(
    'lead_fit.score_fit',
    ['lead_context' => 'x'],
    ScoreLeadFitAction::buildFitQuestions($prof['dimensions']),
    $legacyFn,
    ScoreLeadFitAction::shadowExtract($weights, $thresholds, $enabledFive),
    ScoreLeadFitAction::shadowAgree($thresholds)
);
check('live: low confidence escalates to legacy', $answers === $legacyFn());

p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new FitFakeJev());
FitFakeJev::reset();
FitFakeJev::$script = [new \App\Jev\JevException('boom')];
$answers = DecisionTier::decide(
    'lead_fit.score_fit',
    ['lead_context' => 'x'],
    ScoreLeadFitAction::buildFitQuestions($prof['dimensions']),
    $legacyFn,
    ScoreLeadFitAction::shadowExtract($weights, $thresholds, $enabledFive),
    ScoreLeadFitAction::shadowAgree($thresholds)
);
check('live: Jev error fails over to legacy', $answers === $legacyFn());

// --- 7. Status mapping ---------------------------------------------------------
check("statusForVerdict: qualified -> 'Qualified'",
    QualifyLeadAction::statusForVerdict('qualified') === 'Qualified');
check("statusForVerdict: needs_review -> 'Needs Review' (real status; allowlist-gated out of sequences)",
    QualifyLeadAction::statusForVerdict('needs_review') === 'Needs Review');
check("statusForVerdict: unqualified -> 'Unqualified'",
    QualifyLeadAction::statusForVerdict('unqualified') === 'Unqualified');
check("statusForVerdict: unknown verdict -> 'Unqualified' (fail-closed)",
    QualifyLeadAction::statusForVerdict('bogus') === 'Unqualified');

// --- 8. Notes markers ------------------------------------------------------------
$date = date('Y-m-d');
$marker = QualifyLeadAction::notesMarker(
    ['source' => 'legacy', 'fit_score' => 80, 'reason' => 'legacy verdict'], 'Qualified');
check('notesMarker: legacy keeps the exact historical format',
    $marker === "\n\n[Qualification {$date}]: Qualified (score 80) — legacy verdict");

$marker = QualifyLeadAction::notesMarker([
    'source' => 'jev', 'verdict' => 'qualified', 'fit_score' => 100,
    'profile' => 'Test ICP', 'reason' => 'Jev weighted ICP fit 100/100',
    'dimensions' => array_fill_keys(IcpProfile::DIMENSIONS, 10),
    'thresholds' => $thresholds,
], 'Qualified');
check('notesMarker: jev qualified carries fit + per-dimension breakdown',
    str_contains($marker, 'Qualified (fit 100/100, ICP "Test ICP")')
    && str_contains($marker, 'Dimensions: company_size=10/10')
    && str_contains($marker, 'trigger_signals=10/10'));

$marker = QualifyLeadAction::notesMarker([
    'source' => 'jev', 'verdict' => 'needs_review', 'fit_score' => 62,
    'profile' => 'Test ICP', 'reason' => 'Jev weighted ICP fit 62/100',
    'dimensions' => array_fill_keys(IcpProfile::DIMENSIONS, 6),
    'thresholds' => $thresholds,
], 'needs_review');
check('notesMarker: review band is flagged Needs Review with the threshold',
    str_contains($marker, 'Needs Review (fit 62/100, below qualify threshold 75)'));

$marker = QualifyLeadAction::notesMarker([
    'source' => 'veto', 'verdict' => 'unqualified', 'fit_score' => 0,
    'profile' => 'Test ICP',
    'reason' => 'EXCLUDED by ICP exclusion [industry: "gambling"] — compliance. Fit score forced to 0; no Jev call made.',
    'dimensions' => [], 'thresholds' => $thresholds,
], 'Unqualified');
check('notesMarker: veto cites the matched exclusion',
    str_contains($marker, 'Unqualified (fit 0/100)')
    && str_contains($marker, 'EXCLUDED by ICP exclusion [industry: "gambling"]'));

// --- 9. execute() end-to-end: veto path (fake PDO) -------------------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], new FitFakeJev()); // veto ignores mode
FitFakeJev::reset();
ScoreLeadFitAction::$profileOverride = fitProfile([
    'exclusions' => [['exclusion_type' => 'industry', 'value' => 'gambling', 'note' => 'compliance']],
]);
$pdo = new FitFakePdo();
$pdo->leadRow = fitLead(['notes' => 'They run a gambling portal. Prior enrichment research here.']);
$ok = fitQualifier($pdo)->execute(7);
$upd = $pdo->updateParams();
check('execute: veto path returns true', $ok === true);
check('execute: veto persists lead_score 0 + Unqualified status',
    $upd !== null && (int)$upd[0] === 0 && $upd[1] === 'Unqualified' && (int)$upd[3] === 7);
check('execute: notes appended (existing notes preserved) with EXCLUDED marker',
    $upd !== null
    && str_starts_with((string)$upd[2], 'They run a gambling portal. Prior enrichment research here.')
    && str_contains((string)$upd[2], '[Qualification ')
    && str_contains((string)$upd[2], 'EXCLUDED by ICP exclusion'));
check('execute: veto path made no Jev call even in live mode', FitFakeJev::$calls === 0);

// missing lead throws (unchanged behavior)
$pdo2 = new FitFakePdo();
$pdo2->leadRow = null;
$threw = false;
try {
    fitQualifier($pdo2)->execute(999);
} catch (\App\Exceptions\OutreachException $e) {
    $threw = str_contains($e->getMessage(), 'Lead ID 999 not found');
}
check('execute: missing lead still throws OutreachException', $threw);

ScoreLeadFitAction::$profileOverride = null;
DecisionTier::resetForTests();

exit(p4j_summary('test_fit_scoring.php'));
