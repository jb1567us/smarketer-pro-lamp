#!/usr/bin/env php
<?php
/**
 * Phase 4 JEV tests: EnrichSufficiencyAction data-completeness criteria.
 *
 * Zero DB, zero network:
 *   1. ruleCheck() fixtures: complete / sparse / hard-must-have / loop-guard.
 *   2. evaluate() off-mode verdict shape (source='rules', confidence 0.0).
 *   3. normalize() of raw JEV answers (proceed / re-enrich mapping,
 *      loop guard applied to JEV verdicts too).
 *   4. execute() is fail-soft (never throws; advisory in off/shadow).
 *
 * Usage: php tests/phase4_jev/test_enrich_sufficiency.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require p4j_repo_root() . '/includes/autoload.php';
require __DIR__ . '/fake_pdo.php';

use App\Actions\EnrichSufficiencyAction;

function enrichNotes(string $industry, string $pains, string $extra = ''): string
{
    $n = "[Enrichment 2026-09-28]:\nIndustry: {$industry}\nPain Points: {$pains}";
    return $extra !== '' ? $extra . "\n\n" . $n : $n;
}

function suffLead(array $over = []): array
{
    return array_merge([
        'id' => 1,
        'company_name' => 'Acme Corp',
        'website' => 'https://acme.test',
        'contact_name' => 'Amy Owner',
        'notes' => enrichNotes('B2B SaaS', 'Lead gen; follow-up'),
        'status' => 'Enriched',
    ], $over);
}

/** @return EnrichSufficiencyAction */
function suffAction(array $lead): EnrichSufficiencyAction
{
    p4j_inject_tier(['enabled' => false, 'mode' => 'off'], null);
    $pdo = ScriptedPdo::make([
        ['match' => 'FROM leads WHERE id', 'rows' => [$lead]],
    ]);
    return p4j_action(EnrichSufficiencyAction::class, $pdo);
}

// --- 1. ruleCheck fixtures ---------------------------------------------------
$action = suffAction(suffLead());
$r = $action->ruleCheck(suffLead());
check('complete enrichment proceeds', $r['decision'] === 'proceed', var_export($r, true));
check('complete enrichment completeness=1.0', $r['completeness'] === 1.0);
check('complete enrichment nothing missing', $r['missing'] === []);
check('attempts counted 0', $r['attempts'] === 0);

$r = $action->ruleCheck(suffLead(['notes' => enrichNotes('Unknown', 'Lead gen')]));
check('industry Unknown alone -> proceed (5/6, pain present)',
    $r['decision'] === 'proceed', var_export($r, true));
check('industry in missing', in_array('industry', $r['missing'], true));

$r = $action->ruleCheck(suffLead(['notes' => enrichNotes('B2B SaaS', 'None identified')]));
check('pain points "None identified" alone -> proceed (5/6, industry present)',
    $r['decision'] === 'proceed', var_export($r, true));
check('pain_points in missing', in_array('pain_points', $r['missing'], true));

$r = $action->ruleCheck(suffLead(['notes' => enrichNotes('Unknown', 'None identified')]));
check('BOTH deliverables missing -> re_enrich (enrichment yielded nothing)',
    $r['decision'] === 're_enrich', var_export($r, true));

$r = $action->ruleCheck(suffLead(['company_name' => '']));
check('missing company -> re_enrich (hard must-have)', $r['decision'] === 're_enrich');
$r = $action->ruleCheck(suffLead(['website' => '']));
check('missing website -> re_enrich (hard must-have)', $r['decision'] === 're_enrich');

$r = $action->ruleCheck(suffLead(['notes' => 'just a sales call note']));
check('no enrichment block -> re_enrich', $r['decision'] === 're_enrich');
check('completeness 0.5 below threshold', $r['completeness'] === 0.5, var_export($r, true));

$r = $action->ruleCheck(suffLead(['contact_name' => '']));
check('missing contact only -> proceed (4/6 >= threshold)',
    $r['decision'] === 'proceed' && $r['completeness'] === round(5 / 6, 3),
    var_export($r, true));

// --- 2. Loop guard -------------------------------------------------------------
$looped = suffLead(['notes' => enrichNotes('Unknown', 'None identified') . "\n\n[Re-enrich 1]\n\n[Re-enrich 2]"]);
$r = $action->ruleCheck($looped);
check('attempts counted 2', $r['attempts'] === 2, var_export($r, true));
check('MAX_REENRICH reached -> proceed (no infinite loop)', $r['decision'] === 'proceed');
check('DECISION threshold constant sane', EnrichSufficiencyAction::MAX_REENRICH === 2);

// --- 3. evaluate() off-mode verdict shape --------------------------------------
$v = suffAction(suffLead())->evaluate(1);
check('off-mode proceeds on complete', $v['decision'] === 'proceed', var_export($v, true));
check('off-mode source=rules', $v['source'] === 'rules');
check('off-mode confidence=0.0', $v['confidence'] === 0.0);
check('off-mode carries completeness', $v['completeness'] === 1.0);
check('off-mode carries missing', $v['missing'] === []);
check('off-mode carries attempts', $v['attempts'] === 0);
check('off-mode has latency_ms', is_int($v['latency_ms']));
check('off-mode has note', is_string($v['note'] ?? null) && $v['note'] !== '');

$v = suffAction(suffLead(['notes' => enrichNotes('Unknown', 'None identified')]))->evaluate(1);
check('off-mode re_enrich when enrichment yielded nothing', $v['decision'] === 're_enrich');

// --- 4. normalize() of raw JEV answers (via reflection) --------------------------
$ref = new ReflectionClass(EnrichSufficiencyAction::class);
$norm = $ref->getMethod('normalize');
$norm->setAccessible(true);
$inst = suffAction(suffLead());
$check = $inst->ruleCheck(suffLead());

$jevApprove = [
    'sufficient' => ['noul' => 0.9, 'confidence' => 0.85],
    'completeness' => ['score' => 3.6, 'confidence' => 0.8],
];
$n = $norm->invoke($inst, $jevApprove, $check);
check('JEV approve -> proceed', $n['decision'] === 'proceed');
check('JEV verdict source=jev', $n['source'] === 'jev');
check('JEV verdict confidence from answer', $n['confidence'] === 0.85);
check('JEV verdict has note', is_string($n['note'] ?? null) && $n['note'] !== '');

$jevReject = [
    'sufficient' => ['noul' => 0.2, 'confidence' => 0.9],
    'completeness' => ['score' => 1.0, 'confidence' => 0.9],
];
$n = $norm->invoke($inst, $jevReject, $check);
check('JEV reject -> re_enrich', $n['decision'] === 're_enrich');

// Loop guard applies to JEV verdicts too: reject past MAX_REENRICH -> proceed.
$checkLooped = $inst->ruleCheck($looped);
$n = $norm->invoke($inst, $jevReject, $checkLooped);
check('JEV reject past cap -> proceed (loop guard)', $n['decision'] === 'proceed');

// Already-normalized input passes through.
$already = ['decision' => 're_enrich', 'completeness' => 0.5, 'missing' => ['industry'],
            'attempts' => 1, 'confidence' => 0.0, 'source' => 'rules'];
$n = $norm->invoke($inst, $already, $check);
check('normalized input passes through', $n['decision'] === 're_enrich' && $n['source'] === 'rules');
check('passthrough gains note', is_string($n['note'] ?? null) && $n['note'] !== '');

// --- 5. extractDecision -----------------------------------------------------------
$ext = $ref->getMethod('extractDecision');
$ext->setAccessible(true);
check('extractDecision from JEV approve', $ext->invoke($inst, $jevApprove) === 'proceed');
check('extractDecision from JEV reject', $ext->invoke($inst, $jevReject) === 're_enrich');
check('extractDecision from legacy shape', $ext->invoke($inst, ['decision' => 'proceed']) === 'proceed');

// --- 6. execute() fail-soft ---------------------------------------------------------
$ok = suffAction(suffLead())->execute(1);
check('execute() returns true (advisory)', $ok === true);

// execute() on a missing lead must not throw (fail-soft).
p4j_inject_tier(['enabled' => false, 'mode' => 'off'], null);
$empty = p4j_action(EnrichSufficiencyAction::class, ScriptedPdo::make([
    ['match' => 'FROM leads WHERE id', 'rows' => []],
]));
check('execute() on missing lead does not throw', $empty->execute(999) === true);

// --- 7. Constants ----------------------------------------------------------------------
check('TIMEOUT_S <= 8', EnrichSufficiencyAction::TIMEOUT_S <= 8);
check('DECISION stable name', EnrichSufficiencyAction::DECISION === 'enrich_sufficiency.decide');

exit(p4j_summary('test_enrich_sufficiency.php'));
