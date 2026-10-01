<?php
/**
 * Phase 4 JEV decision-port tests (goal_67693fcbba4c) — shared harness.
 *
 * NOTE: this suite lives in tests/phase4_jev/ because tests/phase4/ is a
 * different workstream's suite (n8n ingestion hardening) with its own
 * runner and helper names.
 *
 * Mirrors tests/phase3/common.php: each test file requires this file, then
 * the repo autoloader, then runs its checks in its own PHP process. Run the
 * whole suite with tests/phase4_jev/run_phase4_jev_tests.php.
 */
declare(strict_types=1);

$P4J_PASS = 0;
$P4J_FAIL = 0;
$P4J_SKIP = 0;

function check(string $name, bool $cond, string $detail = ''): void
{
    global $P4J_PASS, $P4J_FAIL;
    if ($cond) {
        $P4J_PASS++;
        echo "  PASS: {$name}\n";
    } else {
        $P4J_FAIL++;
        echo "  FAIL: {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

function p4j_skip(string $name, string $reason): void
{
    global $P4J_SKIP;
    $P4J_SKIP++;
    echo "  SKIP: {$name} ({$reason})\n";
}

/** Print the summary and return the process exit code for this file. */
function p4j_summary(string $file): int
{
    global $P4J_PASS, $P4J_FAIL, $P4J_SKIP;
    echo "  -- {$file}: {$P4J_PASS} pass, {$P4J_FAIL} fail, {$P4J_SKIP} skip\n";
    return $P4J_FAIL === 0 ? 0 : 1;
}

/** Repo root derived from this file's location. */
function p4j_repo_root(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Build an action instance without invoking its constructor and inject a
 * scripted pdo. The new decision actions only use pdo on their JEV/rule
 * paths; the LLM router is never consulted.
 */
function p4j_action(string $class, object $pdo): object
{
    $ref = new ReflectionClass($class);
    /** @var object $action */
    $action = $ref->newInstanceWithoutConstructor();
    $prop = $ref->getParentClass()->getProperty('pdo');
    $prop->setAccessible(true);
    $prop->setValue($action, $pdo);
    return $action;
}

/**
 * Inject a DecisionTier config + provider for off/shadow/live tests without
 * network or DB (same approach as tests/jev/test_decision_tier.php).
 *
 * @return array{cfg:ReflectionProperty,prov:ReflectionProperty,att:ReflectionProperty}
 */
function p4j_inject_tier(array $cfg, $provider): array
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
    // providerAttempted=true keeps the shared-provider path from touching
    // the network; timeout-override paths build their own provider and fail
    // closed to null without a key (JevAuthException is caught there).
    $attProp->setValue(null, true);
    $provProp->setValue(null, $provider);
    return ['cfg' => $cfgProp, 'prov' => $provProp, 'att' => $attProp];
}
