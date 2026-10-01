<?php
/**
 * Weighted ICP scoring model test suite (goal_67693fcbba4c, subject 4/4) —
 * shared harness.
 *
 * Conventions (same as tests/phase4_jev and tests/phase3): each test file
 * requires this file, then the repo autoloader, then runs its checks in its
 * own PHP process (see run_icp_scoring_tests.php). Zero network; a scratch
 * MariaDB is used only where explicitly noted (config/db.php swapped and
 * restored, same as tests/icp/test_adjust_weights_integration.php).
 *
 * Shadow-first invariant: no test in this suite persists a 'live' default
 * anywhere. DecisionTier modes are only ever injected per-process via
 * reflection (the p4j_inject_tier pattern), and every test file ends with
 * DecisionTier::resetForTests() plus a check that the effective default is
 * back to 'off'.
 */
declare(strict_types=1);

$ICS_PASS = 0;
$ICS_FAIL = 0;
$ICS_SKIP = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $ICS_PASS, $ICS_FAIL;
    if ($cond) {
        $ICS_PASS++;
        echo "  PASS: {$name}\n";
    } else {
        $ICS_FAIL++;
        echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function ics_skip(string $name, string $reason): void
{
    global $ICS_SKIP;
    $ICS_SKIP++;
    echo "  SKIP: {$name} ({$reason})\n";
}

/** Print the summary and return the process exit code for this file. */
function ics_summary(string $file): int
{
    global $ICS_PASS, $ICS_FAIL, $ICS_SKIP;
    echo "  -- {$file}: {$ICS_PASS} pass, {$ICS_FAIL} fail, {$ICS_SKIP} skip\n";
    return $ICS_FAIL === 0 ? 0 : 1;
}

/** Repo root derived from this file's location. */
function ics_repo_root(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Inject a DecisionTier config + provider for off/shadow/live tests without
 * network or DB (same pattern as tests/phase4_jev/common.php). The injected
 * config is per-process only — it never touches the persisted settings.
 */
function ics_inject_tier(array $cfg, $provider): void
{
    $ref = new ReflectionClass('App\\Jev\\DecisionTier');
    $cfgProp = $ref->getProperty('configCache');
    $cfgProp->setAccessible(true);
    $provProp = $ref->getProperty('provider');
    $provProp->setAccessible(true);
    $attProp = $ref->getProperty('providerAttempted');
    $attProp->setAccessible(true);

    \App\Jev\DecisionTier::resetForTests();
    $cfgProp->setValue(null, $cfg + [
        'enabled' => true, 'mode' => 'shadow', 'min_confidence' => 0.65,
        'model' => null, 'base_url' => null, 'timeout' => 30, 'shadow_log' => null,
    ]);
    $attProp->setValue(null, true);
    $provProp->setValue(null, $provider);
}

/**
 * Shadow-first guard: after resetForTests() the effective default mode must
 * be 'off' (fail-closed). Call at the start and end of every test file.
 */
function ics_assert_default_mode_off(string $where): void
{
    \App\Jev\DecisionTier::resetForTests();
    check("shadow-first: default DecisionTier mode is 'off' {$where}",
        \App\Jev\DecisionTier::mode() === 'off');
}

/** Scripted provider: answers queues or Throwables; records calls.
 * Defined AFTER the autoloader (needs App\Jev\JevProvider). */
require_once ics_repo_root() . '/includes/autoload.php';

class IcsFakeJev extends \App\Jev\JevProvider
{
    public static array $script = [];
    public static int $calls = 0;
    public function __construct() {} // skip key requirement
    public function systemOne($state, array $questions): array
    {
        self::$calls++;
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
    }
}

/**
 * Resolved-profile snapshot (same shape as
 * ScoreLeadFitAction::resolveProfile() returns); used with the
 * $profileOverride seam so no test touches the static DB gateway.
 *
 * Models the production default: tech_stack is present but DISABLED
 * (enabled=false, weight 0), so the default-off state scores exactly like
 * the five-dimension model.
 */
function ics_profile(array $overrides = []): array
{
    $dims = [];
    foreach (\App\Icp\IcpProfile::DIMENSIONS as $d) {
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

function ics_lead(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'company_name' => 'Acme Logistics',
        'contact_name' => 'Jane Ops',
        'email' => 'jane@acmelogistics.com',
        'website' => 'https://acmelogistics.com',
        'target_persona' => 'VP Operations',
        'country_code' => 'US',
        'notes' => 'Mid-market logistics firm, 250 employees.',
        'status' => 'Enriched',
        'lead_score' => 0,
    ], $overrides);
}

/** @param array<string,float> $positions dimension key => Jev position 0..9 */
function ics_dim_answers(array $positions, float $conf = 0.9): array
{
    $a = [];
    foreach (\App\Icp\IcpProfile::DIMENSIONS as $d) {
        $a['dim_' . $d] = [
            'score' => $positions[$d] ?? 0.0,
            'confidence' => $conf,
            'probabilities' => [],
        ];
    }
    return $a;
}

function ics_scorer(): \App\Actions\ScoreLeadFitAction
{
    $ref = new ReflectionClass(\App\Actions\ScoreLeadFitAction::class);
    return $ref->newInstanceWithoutConstructor();
}

// Param-aware scripted fake PDO (ScriptedPdo) from the phase4_jev suite:
// matchers on SQL substrings -> canned rows. Zero DB.
require_once ics_repo_root() . '/tests/phase4_jev/fake_pdo.php';
