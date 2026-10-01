#!/usr/bin/env php
<?php
/**
 * Hard exclusion veto across DecisionTier modes (subject 2/4,
 * goal_67693fcbba4c).
 *
 * The veto is a deterministic ICP rule: it fires BEFORE any Jev call, in
 * EVERY mode — off, shadow, and live. Zero network, zero DB (profile
 * injected via the $profileOverride seam; Jev provider scripted).
 *
 *   1. Each of the five exclusion types fires in off, shadow, and live
 *      mode: fit forced to 0, verdict unqualified, source 'veto', and the
 *      scripted Jev provider records ZERO calls (even in live mode, where
 *      the provider is queued with perfect all-9 answers).
 *   2. Matching semantics: case-insensitive substring; domain matches the
 *      email domain and website host with www stripped and subdomain
 *      suffix; a near-miss domain does NOT veto.
 *   3. Unknown exclusion_type rows are ignored (the veto list still
 *      applies); empty-value rows are skipped.
 *   4. No usable ICP profile -> no veto possible; the legacy path runs.
 *   5. The veto result carries the profile key + thresholds and cites the
 *      matched exclusion in the reason.
 *
 * Usage: php tests/icp_scoring/test_exclusion_veto.php
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require_once ics_repo_root() . '/includes/autoload.php';

use App\Actions\ScoreLeadFitAction;
use App\Icp\IcpProfile;

ics_assert_default_mode_live('(start)');

$legacyFn = fn() => ['qualified' => true, 'score' => 80, 'reason' => 'legacy verdict', 'source' => 'llm'];
$all9 = array_fill_keys(IcpProfile::DIMENSIONS, 9.0);

// --- 1. every exclusion type x every mode ----------------------------------------
echo "1. veto fires before any Jev call in off/shadow/live:\n";
$cases = [
    'industry' => [
        ['exclusion_type' => 'industry', 'value' => 'gambling', 'note' => 'compliance'],
        ics_lead(['notes' => 'They run a gambling affiliate portal.']),
    ],
    'company' => [
        ['exclusion_type' => 'company', 'value' => 'acme', 'note' => null],
        ics_lead(['company_name' => 'Acme Logistics']),
    ],
    'domain (email)' => [
        ['exclusion_type' => 'domain', 'value' => 'spamco.com', 'note' => null],
        ics_lead(['email' => 'x@spamco.com', 'website' => 'https://example.com']),
    ],
    'domain (website subdomain)' => [
        ['exclusion_type' => 'domain', 'value' => 'spamco.com', 'note' => null],
        ics_lead(['email' => 'x@other.com', 'website' => 'https://mail.spamco.com/path']),
    ],
    'title' => [
        ['exclusion_type' => 'title', 'value' => 'intern', 'note' => null],
        ics_lead(['contact_name' => 'Jane Intern', 'target_persona' => '']),
    ],
    'keyword' => [
        ['exclusion_type' => 'keyword', 'value' => 'crypto casino', 'note' => null],
        ics_lead(['notes' => 'Side project: a crypto casino review site.']),
    ],
];
$modes = [
    'off'    => ['enabled' => false, 'mode' => 'off'],
    'shadow' => ['enabled' => true, 'mode' => 'shadow'],
    'live'   => ['enabled' => true, 'mode' => 'live'],
];
foreach ($cases as $name => [$exclusion, $lead]) {
    foreach ($modes as $modeName => $tierCfg) {
        ics_inject_tier($tierCfg, new IcsFakeJev());
        IcsFakeJev::reset();
        IcsFakeJev::$script = [ics_dim_answers($all9)]; // perfect answers queued
        ScoreLeadFitAction::$profileOverride = ics_profile(['exclusions' => [$exclusion]]);
        $r = ics_scorer()->score($lead, $legacyFn);
        check("veto [{$name}] in {$modeName}: fit 0, unqualified, source 'veto'",
            $r['fit_score'] === 0 && $r['score'] === 0
            && $r['qualified'] === false && $r['verdict'] === 'unqualified'
            && $r['source'] === 'veto' && $r['veto'] !== null);
        check("veto [{$name}] in {$modeName}: ZERO Jev calls even in live",
            IcsFakeJev::$calls === 0);
        check("veto [{$name}] in {$modeName}: reason cites the exclusion",
            stripos($r['reason'], 'EXCLUDED by ICP exclusion') !== false
            && stripos($r['reason'], (string)$exclusion['value']) !== false);
    }
}
ScoreLeadFitAction::$profileOverride = null;

// --- 2. matching semantics ----------------------------------------------------------
echo "2. matching semantics:\n";
ics_inject_tier(['enabled' => false, 'mode' => 'off'], new IcsFakeJev());

// Case-insensitive value AND lead text.
ScoreLeadFitAction::$profileOverride = ics_profile([
    'exclusions' => [['exclusion_type' => 'industry', 'value' => 'GAMBLING', 'note' => null]],
]);
$r = ics_scorer()->score(ics_lead(['notes' => 'A Gambling affiliate portal.']), $legacyFn);
check('exclusion matching is case-insensitive', $r['source'] === 'veto');

// Uppercase domain + www prefix stripped on both sides.
ScoreLeadFitAction::$profileOverride = ics_profile([
    'exclusions' => [['exclusion_type' => 'domain', 'value' => 'WWW.SpamCo.COM', 'note' => null]],
]);
$r = ics_scorer()->score(
    ics_lead(['email' => 'x@SPAMCO.com', 'website' => 'https://www.spamco.com']),
    $legacyFn);
check('domain match strips www and folds case', $r['source'] === 'veto');

// Near-miss domain does NOT match.
ScoreLeadFitAction::$profileOverride = ics_profile([
    'exclusions' => [['exclusion_type' => 'domain', 'value' => 'spamco.com', 'note' => null]],
]);
$r = ics_scorer()->score(ics_lead(['email' => 'x@notspamco.com', 'website' => 'https://legit.example']), $legacyFn);
check('near-miss domain (notspamco.com) does NOT veto',
    $r['source'] === 'legacy' && $r['fit_score'] === 80);

// Title type scans contact name, persona, and notes (not the company name).
ScoreLeadFitAction::$profileOverride = ics_profile([
    'exclusions' => [['exclusion_type' => 'title', 'value' => 'student', 'note' => null]],
]);
$r = ics_scorer()->score(
    ics_lead(['company_name' => 'Student Beans Ltd', 'contact_name' => 'Jane Ops', 'target_persona' => 'VP Ops', 'notes' => 'B2B']),
    $legacyFn);
check('title exclusion does not fire on the company name alone', $r['source'] === 'legacy');

// --- 3. unknown type / empty value rows ----------------------------------------------
echo "3. malformed exclusion rows:\n";
ScoreLeadFitAction::$profileOverride = ics_profile([
    'exclusions' => [
        ['exclusion_type' => 'planet', 'value' => 'mars', 'note' => null],
        ['exclusion_type' => 'keyword', 'value' => '   ', 'note' => null],
        ['exclusion_type' => 'industry', 'value' => 'gambling', 'note' => null],
    ],
]);
$r = ics_scorer()->score(ics_lead(['notes' => 'A gambling portal.']), $legacyFn);
check('unknown type + empty value rows ignored; real exclusion still fires',
    $r['source'] === 'veto' && stripos($r['reason'], 'gambling') !== false);

ScoreLeadFitAction::$profileOverride = ics_profile([
    'exclusions' => [['exclusion_type' => 'planet', 'value' => 'mars', 'note' => null]],
]);
$r = ics_scorer()->score(ics_lead(), $legacyFn);
check('unknown exclusion_type alone never vetoes (fail-open on the row)',
    $r['source'] === 'legacy');

// --- 4. no usable profile -> no veto possible -----------------------------------------
echo "4. no profile, no veto:\n";
ScoreLeadFitAction::$profileOverride = false; // force "no usable profile"
IcsFakeJev::reset();
$r = ics_scorer()->score(
    ics_lead(['notes' => 'A gambling portal.']), // would veto with a profile
    $legacyFn);
check('no profile: veto cannot fire; legacy path runs, Jev never called',
    $r['source'] === 'legacy' && IcsFakeJev::$calls === 0
    && stripos($r['reason'], 'no active ICP profile') !== false);
ScoreLeadFitAction::$profileOverride = null;

// --- 5. veto result shape ---------------------------------------------------------------
echo "5. veto result shape:\n";
ScoreLeadFitAction::$profileOverride = ics_profile([
    'exclusions' => [['exclusion_type' => 'company', 'value' => 'acme', 'note' => 'dup test']],
]);
$r = ics_scorer()->score(ics_lead(), $legacyFn);
check('veto result carries profile key + thresholds',
    $r['profile'] === 'Test ICP' && $r['thresholds'] === ['qualify' => 75, 'review' => 50]);
check('veto result has empty dimension breakdowns',
    $r['dimensions'] === [] && $r['dimension_pcts'] === []);
check('veto reason names the type, value, and note',
    stripos($r['reason'], '[company: "acme"]') !== false
    && stripos($r['reason'], 'dup test') !== false
    && stripos($r['reason'], 'no Jev call made') !== false);

ScoreLeadFitAction::$profileOverride = null;
ics_assert_default_mode_live('(end)');
exit(ics_summary('test_exclusion_veto.php'));
