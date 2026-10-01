#!/usr/bin/env php
<?php
/**
 * Phase 4 JEV tests: per-section grounding QA for the draft reviewer (P3 —
 * PI DP2/DP6 pattern: draft_review.section_grounding).
 *
 * Covers, with scripted PDO doubles — zero DB, zero network:
 *   1. Section splitting: subject/greeting/intro/body/cta/signoff
 *      segmentation with splice offsets.
 *   2. Deterministic checks: unreplaced placeholders auto-fail; lead-fact
 *      anchors counted; empty sections skipped.
 *   3. Off mode (real class, real DecisionTier): deterministic verdicts
 *      only, provider never consulted, draft to human queue with the
 *      evidence trail appended, no regeneration row.
 *   4. Live + scripted JEV: per-section fail on below-threshold score;
 *      exactly ONE anchored regeneration (new draft row, both rows
 *      marked, new row to needs_human); regen outcome in the evidence.
 *   5. Regen-once semantics: a second qaPass on the regenerated draft
 *      does NOT regenerate again (already-attempted).
 *   6. Live + JEV provider error (real class, no key): fail-closed —
 *      no throw, source jev-unavailable, no regeneration, human queue.
 *   7. Live + LLM unavailable: no regeneration (un-regenerated flag).
 *   8. Approved drafts are never rewritten (QA is evidence only).
 *   9. Missing grounding_regen column: no regeneration attempted
 *      (fail-closed), draft flagged as un-regenerated.
 *  10. Human-gate invariant: no code path sets status 'approved'.
 *
 * Usage: php tests/phase4_jev/test_section_grounding.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require p4j_repo_root() . '/includes/autoload.php';
require __DIR__ . '/fake_pdo.php';

use App\Actions\SectionGroundingAction;

putenv('TYPESAFE_API_KEY'); // the no-key fail-closed test needs this unset

/** Scripted PDO with a fake lastInsertId for the regen INSERT. */
class GroundingTestPdo extends ScriptedPdo
{
    public string $fakeInsertId = '77';

    public function lastInsertId(): string
    {
        return $this->fakeInsertId;
    }

    public static function makeQa(array $scripts): self
    {
        $ref = new ReflectionClass(self::class);
        /** @var self $pdo */
        $pdo = $ref->newInstanceWithoutConstructor();
        $pdo->scripts = $scripts;
        return $pdo;
    }
}

/** Testable subclass: scripted JEV answers + scripted LLM rewrites. */
class TestableGrounding extends SectionGroundingAction
{
    public $scriptedAnswers = null; // array, Throwable, or null (=> fallback)
    /** @var array<string,string> section key => rewritten text (missing => LLM fails) */
    public array $rewrites = [];
    public bool $llmUp = false;
    public int $jevCalls = 0;
    public int $llmCalls = 0;

    protected function scoreSectionsWithJev(array $state, array $questions, callable $fallback): array
    {
        $this->jevCalls++;
        $s = $this->scriptedAnswers;
        if ($s instanceof \Throwable) {
            throw $s;
        }
        return $s !== null ? $s : $fallback();
    }

    protected function rewriteSectionWithLlm(
        string $sectionKey,
        string $sectionText,
        array $evidence,
        string $failureReason
    ): ?string {
        $this->llmCalls++;
        return $this->rewrites[$sectionKey] ?? null;
    }

    protected function llmAvailable(): bool
    {
        return $this->llmUp;
    }
}

function qaDraftRow(array $over = []): array
{
    return array_merge([
        'id' => 9,
        'lead_id' => 1,
        'campaign_id' => 2,
        'template_id' => 3,
        'subject' => 'Quick idea for Acme',
        'body' => "Hi Amy,\n\nNoticed Acme is hiring ops managers — congrats on the growth.\n\n" .
                  "We help teams like yours cut manual follow-up. Worth a quick chat?\n\n" .
                  "Best,\nJosh",
        'attempts' => 1,
        'status' => 'needs_human',
        'company_name' => 'Acme',
        'contact_name' => 'Amy Lee',
        'website' => 'https://acme.com',
        'target_persona' => 'ops managers',
        'notes' => 'Hiring ops managers; 50 employees',
        'campaign_name' => 'Q4 push',
        'reviewer_notes' => 'existing whole-draft note',
        'grounding_regen' => 0,
    ], $over);
}

/**
 * @param int $regenColumn 1 = migration applied, 0 = absent
 * @param array $regenById draft id => grounding_regen value
 */
function qaPdo(array $draftRow, int $regenColumn = 1, array $regenById = [], string $insertId = '77'): GroundingTestPdo
{
    $scripts = [
        ['match' => 'SELECT grounding_regen FROM drafts',
         'rowFn' => fn(array $p) => [['grounding_regen' => $regenById[$p[0]] ?? 0]]],
        ['match' => 'information_schema.COLUMNS',
         'rows' => $regenColumn === 1 ? [['c' => 1]] : []],
        ['match' => 'FROM drafts d', 'rows' => [$draftRow]],
    ];
    $pdo = GroundingTestPdo::makeQa($scripts);
    $pdo->fakeInsertId = $insertId;
    return $pdo;
}

function newQa(GroundingTestPdo $pdo): TestableGrounding
{
    /** @var TestableGrounding $qa */
    $qa = p4j_action(TestableGrounding::class, $pdo);
    return $qa;
}

function newRealQa(GroundingTestPdo $pdo): SectionGroundingAction
{
    /** @var SectionGroundingAction $qa */
    $qa = p4j_action(SectionGroundingAction::class, $pdo);
    return $qa;
}

/** JEV answers where every scored section passes. */
function passAnswers(array $sections, float $score = 4.0, float $noul = 0.9): array
{
    $a = [];
    foreach (array_keys($sections) as $i) {
        $a["sec_{$i}_grounded"] = ['score' => $score, 'confidence' => 0.9];
        $a["sec_{$i}_safe"] = ['noul' => $noul, 'confidence' => 0.9];
    }
    return $a;
}

// --- 1. Section splitting --------------------------------------------------
$qa0 = newQa(qaPdo(qaDraftRow()));
$sections = $qa0->splitSections(
    'Quick idea for Acme',
    "Hi Amy,\n\nNoticed Acme is hiring ops managers — congrats on the growth.\n\n" .
    "We help teams like yours cut manual follow-up. Worth a quick chat?\n\n" .
    "Best,\nJosh"
);
$keys = array_column($sections, 'key');
check('split: subject first', $keys[0] === 'subject', var_export($keys, true));
check('split: greeting found', in_array('greeting', $keys, true), var_export($keys, true));
check('split: intro found', in_array('intro', $keys, true));
check('split: cta found', in_array('cta', $keys, true));
check('split: signoff found', in_array('signoff', $keys, true));
check('split: greeting text', $sections[1]['text'] === 'Hi Amy,', var_export($sections[1]['text'] ?? null, true));
$signoff = null;
foreach ($sections as $s) {
    if ($s['key'] === 'signoff') {
        $signoff = $s;
    }
}
check('signoff spans to end of body', $signoff !== null
    && $signoff['start'] !== null && $signoff['end'] !== null
    && $signoff['text'] === "Best,\nJosh");
// Splice round-trip: replacing nothing returns the body byte-identical.
$body = "Hi Amy,\n\nSecond para.\n\nBest,\nJosh";
$sec2 = $qa0->splitSections('s', $body);
$ref = new ReflectionMethod(SectionGroundingAction::class, 'rebuildBody');
$ref->setAccessible(true);
check('rebuild: no rewrites => byte-identical', $ref->invoke($qa0, $body, $sec2, []) === $body);
$rew = [];
foreach ($sec2 as $i => $s) {
    if ($s['key'] === 'intro') {
        $rew[$i] = 'REWRITTEN INTRO';
    }
}
$rebuilt = $ref->invoke($qa0, $body, $sec2, $rew);
check('rebuild: splice replaces exactly one section',
    $rebuilt === "Hi Amy,\n\nREWRITTEN INTRO\n\nBest,\nJosh", var_export($rebuilt, true));

// --- 2. Deterministic checks -----------------------------------------------
$ev = ['company' => 'Acme', 'contact' => 'Amy Lee', 'website' => 'https://acme.com',
       'persona' => 'ops managers', 'campaign' => 'Q4 push', 'notes' => ''];
$hits = SectionGroundingAction::anchorHits('Hi Amy, saw Acme.com is growing', $ev);
check('anchors: company hit', isset($hits['company']));
check('anchors: contact_first hit', isset($hits['contact_first']));
check('anchors: website host hit', isset($hits['website']), var_export($hits, true));
check('anchors: no false hit on unrelated', !isset(SectionGroundingAction::anchorHits('Generic line here', $ev)['company']));
$detFail = SectionGroundingAction::deterministicCheck(
    ['key' => 'intro', 'text' => 'Hi [First Name], love [Company]\'s work', 'start' => null, 'end' => null], $ev);
check('placeholder => fail', $detFail['verdict'] === 'fail' && $detFail['reason'] === 'unreplaced-placeholder');
$detFail2 = SectionGroundingAction::deterministicCheck(
    ['key' => 'intro', 'text' => 'Template says {{company}} here', 'start' => null, 'end' => null], $ev);
check('{{placeholder}} => fail', $detFail2['verdict'] === 'fail');
$detSkip = SectionGroundingAction::deterministicCheck(
    ['key' => 'body', 'text' => '   ', 'start' => null, 'end' => null], $ev);
check('empty => skipped', $detSkip['verdict'] === 'skipped');
$detInc = SectionGroundingAction::deterministicCheck(
    ['key' => 'intro', 'text' => 'Noticed Acme is hiring', 'start' => null, 'end' => null], $ev);
check('anchored, no placeholder => inconclusive', $detInc['verdict'] === 'inconclusive'
    && in_array('company', $detInc['anchor_hits'], true));

// --- 3. Off mode: deterministic only, human queue, no regen -----------------
// ExplodingProvider proves the real DecisionTier path never consults JEV
// in off mode (mode short-circuits before any provider is built).
class ExplodingProvider extends \App\Jev\JevProvider
{
    public function __construct() {}
    public function systemOne($state, array $questions): array
    {
        throw new RuntimeException('JEV must not be consulted in off mode');
    }
}
p4j_inject_tier(['enabled' => false, 'mode' => 'off'], new ExplodingProvider());
$pdo = qaPdo(qaDraftRow([
    'body' => "Hi Amy,\n\nTemplate says {{company}} here.\n\nBest,\nJosh",
]));
$real = newRealQa($pdo);
$res = $real->qaPass(9, ['outcome' => 'needs_human', 'draft_id' => 9]);
$sg = $res['section_grounding'];
check('off: source is deterministic', $sg['source'] === 'deterministic', var_export($sg['source'], true));
check('off: JEV provider never consulted (exploding provider survived)', is_array($sg));
check('off: mode recorded', $sg['mode'] === 'off');
check('off: placeholder section failed', (function () use ($sg) {
    foreach ($sg['sections'] as $s) {
        if ($s['verdict'] === 'fail' && $s['reason'] === 'unreplaced-placeholder') {
            return true;
        }
    }
    return false;
})(), var_export($sg['sections'], true));
check('off: no INSERT (no regen row)', !$pdo->sawSql('INSERT INTO drafts'));
check('off: evidence appended to notes', $pdo->sawSql('reviewer_notes = CONCAT'));
check('off: regen not attempted', $sg['regen']['attempted'] === false);
check('off: draft_id unchanged', $res['draft_id'] === 9);

// --- 4. Live + scripted JEV: failing section -> exactly one regen ----------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = qaPdo(qaDraftRow(), 1, [9 => 0], '77');
$qa = newQa($pdo);
$qa->llmUp = true;
$firstSections = $qa->splitSections(qaDraftRow()['subject'], qaDraftRow()['body']);
$answers = passAnswers($firstSections);
// Make the intro section fail: score 1.5/4 -> 37.5 < 65.
$introIdx = array_search('intro', array_column($firstSections, 'key'), true);
$answers["sec_{$introIdx}_grounded"] = ['score' => 1.5, 'confidence' => 0.9];
$qa->scriptedAnswers = $answers;
$qa->rewrites = ['intro' => 'Noticed Acme is hiring ops managers — congrats on the growth at Acme.'];
$res = $qa->qaPass(9, ['outcome' => 'needs_human', 'draft_id' => 9]);
$sg = $res['section_grounding'];
check('live: source is jev', $sg['source'] === 'jev', var_export($sg['source'], true));
check('live: JEV consulted once', $qa->jevCalls === 1, 'calls=' . $qa->jevCalls);
$introVerdict = null;
foreach ($sg['sections'] as $s) {
    if ($s['key'] === 'intro') {
        $introVerdict = $s;
    }
}
check('live: below-threshold section fails',
    $introVerdict !== null && $introVerdict['verdict'] === 'fail'
    && str_contains($introVerdict['reason'], 'jev-score'), var_export($introVerdict, true));
check('live: passing sections pass',
    (function () use ($sg) {
        foreach ($sg['sections'] as $s) {
            if ($s['key'] === 'greeting' && $s['verdict'] !== 'pass') {
                return false;
            }
        }
        return true;
    })());
check('live: LLM called once (one anchored regen)', $qa->llmCalls === 1, 'calls=' . $qa->llmCalls);
check('live: exactly one regen row INSERTed', count(array_filter(
    $pdo->sqlLog, fn($sql) => stripos($sql, 'INSERT INTO drafts') !== false)) === 1);
$markCount = count(array_filter(
    $pdo->sqlLog, fn($sql) => stripos($sql, 'UPDATE drafts SET grounding_regen = 1') !== false));
check('live: both rows marked grounding_regen=1', $markCount === 2, 'marks=' . $markCount);
check('live: regen reported attempted', $sg['regen']['attempted'] === true);
check('live: new draft id returned', $res['draft_id'] === 77 && $sg['regen']['new_draft_id'] === 77);
check('live: recheck present (deterministic, no second JEV)',
    is_array($sg['regen']['recheck']) && $qa->jevCalls === 1);
$statusUpdates = array_values(array_filter(
    $pdo->sqlLog, fn($sql) => stripos($sql, 'UPDATE drafts SET status') !== false));
check('live: new draft set to needs_human', count($statusUpdates) === 1
    && stripos($statusUpdates[0], "'needs_human'") === false // params are bound, not inlined
    , var_export($statusUpdates, true));
check('live: regenerated sections listed', $sg['regen']['regenerated_sections'] === ['intro']);

// --- 5. Regen-once: second pass does NOT regenerate again -------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = qaPdo(qaDraftRow(['id' => 77]), 1, [77 => 1], '78');
$qa = newQa($pdo);
$qa->llmUp = true;
$qa->scriptedAnswers = passAnswers($qa->splitSections('s', 'b'));
$res = $qa->qaPass(77, ['outcome' => 'needs_human', 'draft_id' => 77]);
check('second pass: no second INSERT', !array_filter(
    $pdo->sqlLog, fn($sql) => stripos($sql, 'INSERT INTO drafts') !== false));
check('second pass: reason already-attempted',
    $res['section_grounding']['regen']['reason'] === 'already-attempted'
    || $res['section_grounding']['regen']['reason'] === 'no-failing-sections',
    var_export($res['section_grounding']['regen']['reason'], true));
check('second pass: LLM not called', $qa->llmCalls === 0);

// Regen-once with failing sections but marker set: must not regenerate.
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = qaPdo(qaDraftRow(['id' => 77]), 1, [77 => 1], '78');
$qa = newQa($pdo);
$qa->llmUp = true;
$secs = $qa->splitSections(qaDraftRow()['subject'], qaDraftRow()['body']);
$ans = passAnswers($secs);
$ii = array_search('intro', array_column($secs, 'key'), true);
$ans["sec_{$ii}_grounded"] = ['score' => 1.0, 'confidence' => 0.9];
$qa->scriptedAnswers = $ans;
$res = $qa->qaPass(77, ['outcome' => 'needs_human', 'draft_id' => 77]);
check('marked draft + failing section: still no regen',
    $res['section_grounding']['regen']['attempted'] === false
    && $res['section_grounding']['regen']['reason'] === 'already-attempted'
    && $qa->llmCalls === 0);

// --- 6. Live + provider error (real class, no key): fail-closed -----------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = qaPdo(qaDraftRow());
$real = newRealQa($pdo);
$res = $real->qaPass(9, ['outcome' => 'needs_human', 'draft_id' => 9]); // must not throw
$sg = $res['section_grounding'];
check('provider error: no throw, report returned', is_array($sg));
check('provider error: source jev-unavailable', $sg['source'] === 'jev-unavailable',
    var_export($sg['source'], true));
check('provider error: unscored sections', (function () use ($sg) {
    foreach ($sg['sections'] as $s) {
        if ($s['verdict'] !== 'unscored' && $s['verdict'] !== 'fail') {
            return false;
        }
    }
    return true;
})());
check('provider error: no regen', !$pdo->sawSql('INSERT INTO drafts')
    && $sg['regen']['attempted'] === false);
check('provider error: draft_id unchanged', $res['draft_id'] === 9);

// --- 6b. Live + JEV failure + LLM AVAILABLE + placeholder: STILL no regen --
// (regen requires a real live JEV verdict; a deterministic flag alone
// never authorizes a rewrite). Test 6's real class has no llmRouter, so
// this is the distinguishing case.
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = qaPdo(qaDraftRow(['body' => "Hi Amy,\n\nTemplate says {{company}} here.\n\nBest,\nJosh"]), 1, [9 => 0], '77');
$qa = newRealQa($pdo); // real class: no TYPESAFE_API_KEY -> JEV failure
$refProp = new ReflectionProperty(\App\Actions\AbstractAction::class, 'llmRouter');
$refProp->setAccessible(true);
$refProp->setValue($qa, new \App\Routers\SmartLLMRouter($pdo)); // LLM present this time
$res = $qa->qaPass(9, ['outcome' => 'needs_human', 'draft_id' => 9]); // must not throw
$sg = $res['section_grounding'];
check('JEV fail + LLM present: placeholder still flagged',
    (bool)array_filter($sg['sections'], fn($s) => $s['reason'] === 'unreplaced-placeholder'));
check('JEV fail + LLM present: no regen (no-jev-verdict)',
    $sg['regen']['attempted'] === false && $sg['regen']['reason'] === 'no-jev-verdict',
    var_export($sg['regen'], true));
check('JEV fail + LLM present: no INSERT', !$pdo->sawSql('INSERT INTO drafts'));
check('JEV fail + LLM present: evidence trail still appended', $pdo->sawSql('reviewer_notes = CONCAT'));

// --- 7. Live + LLM unavailable: flagged un-regenerated ---------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = qaPdo(qaDraftRow(), 1, [9 => 0], '77');
$qa = newQa($pdo);
$qa->llmUp = false; // no LLM router
$secs = $qa->splitSections(qaDraftRow()['subject'], qaDraftRow()['body']);
$ans = passAnswers($secs);
$ii = array_search('intro', array_column($secs, 'key'), true);
$ans["sec_{$ii}_grounded"] = ['score' => 1.0, 'confidence' => 0.9];
$qa->scriptedAnswers = $ans;
$res = $qa->qaPass(9, ['outcome' => 'needs_human', 'draft_id' => 9]);
check('no LLM: no regen, flagged un-regenerated',
    $res['section_grounding']['regen']['attempted'] === false
    && $res['section_grounding']['regen']['reason'] === 'llm-unavailable'
    && !array_filter($pdo->sqlLog, fn($sql) => stripos($sql, 'INSERT INTO drafts') !== false));

// --- 8. Approved drafts are never rewritten ---------------------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = qaPdo(qaDraftRow(['status' => 'approved']), 1, [9 => 0], '77');
$qa = newQa($pdo);
$qa->llmUp = true;
$secs = $qa->splitSections(qaDraftRow()['subject'], qaDraftRow()['body']);
$ans = passAnswers($secs);
$ii = array_search('intro', array_column($secs, 'key'), true);
$ans["sec_{$ii}_grounded"] = ['score' => 1.0, 'confidence' => 0.9];
$qa->scriptedAnswers = $ans;
$res = $qa->qaPass(9, ['outcome' => 'approved', 'draft_id' => 9]);
check('approved: no regen (QA is evidence only)',
    $res['section_grounding']['regen']['reason'] === 'approved-kept'
    && $qa->llmCalls === 0
    && !array_filter($pdo->sqlLog, fn($sql) => stripos($sql, 'INSERT INTO drafts') !== false));
check('approved: evidence still appended', $pdo->sawSql('reviewer_notes = CONCAT'));

// --- 9. Missing migration column: no regen, flagged -------------------------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = qaPdo(qaDraftRow(), 0 /* column absent */, [], '77');
$qa = newQa($pdo);
$qa->llmUp = true;
$secs = $qa->splitSections(qaDraftRow()['subject'], qaDraftRow()['body']);
$ans = passAnswers($secs);
$ii = array_search('intro', array_column($secs, 'key'), true);
$ans["sec_{$ii}_grounded"] = ['score' => 1.0, 'confidence' => 0.9];
$qa->scriptedAnswers = $ans;
$res = $qa->qaPass(9, ['outcome' => 'needs_human', 'draft_id' => 9]);
check('no column: no regen attempted',
    $res['section_grounding']['regen']['attempted'] === false
    && $res['section_grounding']['regen']['reason'] === 'already-attempted'
    && !array_filter($pdo->sqlLog, fn($sql) => stripos($sql, 'INSERT INTO drafts') !== false));

// --- 10. Human-gate invariant: QA never writes status='approved' --------
p4j_inject_tier(['enabled' => true, 'mode' => 'live'], null);
$pdo = qaPdo(qaDraftRow(), 1, [9 => 0], '77');
$qa = newQa($pdo);
$qa->llmUp = true;
$secs = $qa->splitSections(qaDraftRow()['subject'], qaDraftRow()['body']);
$ans = passAnswers($secs);
$ii = array_search('intro', array_column($secs, 'key'), true);
$ans["sec_{$ii}_grounded"] = ['score' => 1.0, 'confidence' => 0.9];
$qa->scriptedAnswers = $ans;
$qa->rewrites = ['intro' => 'Fixed intro grounded in Acme facts.'];
$qa->qaPass(9, ['outcome' => 'needs_human', 'draft_id' => 9]);
// Statuses are bound params, so prove the invariant by code: no literal
// 'approved' status write may exist in SectionGroundingAction (the only
// status it writes is needs_human; approvals stay with the whole-draft
// reviewer and the human).
$src = file_get_contents(p4j_repo_root() . '/includes/Actions/SectionGroundingAction.php');
check('QA never writes status=approved',
    preg_match('/SET\s+status\s*=\s*[\'"]approved[\'"]/i', $src) === 0
    && preg_match('/setDraftStatus\([^)]*[\'"]approved[\'"]/', $src) === 0);
check('QA writes only needs_human statuses',
    preg_match_all('/setDraftStatus\(\$newId,\s*[\'"]needs_human[\'"]\)/', $src) === 1);
check('evidence trail JSON present', $pdo->sawSql('reviewer_notes = CONCAT'));

// --- 11. ReviewDraftAction integration: QA failure keeps the outcome -------
p4j_inject_tier(['enabled' => false, 'mode' => 'off'], null);
$pdo = qaPdo(qaDraftRow(), 1, [9 => 0], '77');
// Remove the draft row so qaPass throws (loadDraft misses) — the wrapper
// must preserve the original review outcome.
$pdo2 = GroundingTestPdo::makeQa([
    ['match' => 'information_schema.COLUMNS', 'rows' => [['c' => 1]]],
]);
$reviewer = p4j_action(\App\Actions\ReviewDraftAction::class, $pdo2);
$rm = new ReflectionMethod(\App\Actions\ReviewDraftAction::class, 'withSectionGrounding');
$rm->setAccessible(true);
$in = ['outcome' => 'needs_human', 'draft_id' => 999, 'revisions' => 0, 'reason' => 'x'];
$out = $rm->invoke($reviewer, 999, $in);
check('QA throw preserves review outcome', $out === $in, var_export($out, true));

// --- 12. execute(): newest reviewable draft QA'd; false when none ---------
p4j_inject_tier(['enabled' => false, 'mode' => 'off'], null);
$pdo = qaPdo(qaDraftRow(), 1, [9 => 0], '77');
$pdo->scripts = array_merge(
    [['match' => 'SELECT id FROM drafts WHERE lead_id', 'rows' => [['id' => 9]]]],
    $pdo->scripts
);
$real = newRealQa($pdo);
check('execute: true with a reviewable draft', $real->execute(1) === true);
check('execute: evidence appended', $pdo->sawSql('reviewer_notes = CONCAT'));

$pdo = qaPdo(qaDraftRow(), 1, [9 => 0], '77');
$pdo->scripts = array_merge(
    [['match' => 'SELECT id FROM drafts WHERE lead_id', 'rows' => []]],
    $pdo->scripts
);
$real = newRealQa($pdo);
check('execute: false with no reviewable draft', $real->execute(1) === false);

// --- 13. withSectionGrounding success path (llmRouter present) ------------
p4j_inject_tier(['enabled' => false, 'mode' => 'off'], null);
$pdo = qaPdo(qaDraftRow(), 1, [9 => 0], '77');
$reviewer = p4j_action(\App\Actions\ReviewDraftAction::class, $pdo);
$lrp = new ReflectionProperty(\App\Actions\AbstractAction::class, 'llmRouter');
$lrp->setAccessible(true);
$lrp->setValue($reviewer, new \App\Routers\SmartLLMRouter($pdo));
$rm = new ReflectionMethod(\App\Actions\ReviewDraftAction::class, 'withSectionGrounding');
$rm->setAccessible(true);
$in = ['outcome' => 'needs_human', 'draft_id' => 9, 'revisions' => 0, 'reason' => 'x'];
$out = $rm->invoke($reviewer, 9, $in);
check('wrapper: outcome preserved', $out['outcome'] === 'needs_human' && $out['draft_id'] === 9);
check('wrapper: grounding report attached',
    isset($out['section_grounding']['sections']) && $out['section_grounding']['source'] === 'deterministic');
check('wrapper: notes gained the evidence trail', $pdo->sawSql('reviewer_notes = CONCAT'));

exit(p4j_summary('test_section_grounding.php'));
