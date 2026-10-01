#!/usr/bin/env php
<?php
/**
 * Unit tests for AdjustIcpWeightsAction's pure logic: engagement signal
 * mapping, classified-intent parsing, Pearson correlation, and weight
 * proposal (nudge/clamp/noise-floor/locks/renormalization).
 *
 * No database required — everything under test is a public static.
 *
 * Usage: php tests/icp/test_adjust_weights_unit.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

use App\Actions\AdjustIcpWeightsAction as A;

$pass = 0;
$fail = 0;
function check(string $name, bool $cond, string $detail = ''): void
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

$W = ['company_size' => 20, 'industry_fit' => 20, 'target_title' => 20,
      'geography' => 20, 'trigger_signals' => 20];

// --- A. engagement signal mapping --------------------------------------
echo "A. signalForIntent:\n";
check('positive -> +2', A::signalForIntent('positive') === 2.0);
check('objection -> +1', A::signalForIntent('objection') === 1.0);
check('referral -> +1', A::signalForIntent('referral') === 1.0);
check('unsubscribe -> -1', A::signalForIntent('unsubscribe') === -1.0);
check('hostile -> -1', A::signalForIntent('hostile') === -1.0);
check('bounce -> 0', A::signalForIntent('bounce') === 0.0);
check('out_of_office -> 0', A::signalForIntent('out_of_office') === 0.0);
check('not_now -> 0', A::signalForIntent('not_now') === 0.0);
check('other -> 0', A::signalForIntent('other') === 0.0);
check('unknown intent -> 0', A::signalForIntent('bogus') === 0.0);
check('case-insensitive', A::signalForIntent('Positive') === 2.0);

// --- B. classified intent parsing --------------------------------------
echo "B. parseClassifiedIntent:\n";
check(
    'Phase-3 verdict prefix + JSON',
    A::parseClassifiedIntent('Phase-3 verdict: {"intent":"positive","confidence":0.9}') === 'positive'
);
check(
    'bare JSON falls back to regex',
    A::parseClassifiedIntent('{"intent": "hostile", "urgency": 7}') === 'hostile'
);
check('garbage -> null', A::parseClassifiedIntent('no verdict here') === null);
check('null -> null', A::parseClassifiedIntent(null) === null);
check('empty -> null', A::parseClassifiedIntent('') === null);

// --- C. Pearson correlation --------------------------------------------
echo "C. pearson:\n";
check('perfect positive -> 1.0', A::pearson([1, 2, 3, 4], [2, 4, 6, 8]) === 1.0);
check('perfect negative -> -1.0', A::pearson([1, 2, 3, 4], [8, 6, 4, 2]) === -1.0);
check('zero variance in x -> null', A::pearson([5, 5, 5], [1, 2, 3]) === null);
check('zero variance in y -> null', A::pearson([1, 2, 3], [5, 5, 5]) === null);
check('fewer than 2 pairs -> null', A::pearson([1], [2]) === null);
check('length mismatch -> null', A::pearson([1, 2], [1]) === null);
$r = A::pearson([1, 2, 3], [1, 2, 2]);
check('partial correlation in (-1,1)', $r !== null && $r > -1.0 && $r < 1.0, 'r=' . var_export($r, true));

// --- D. weight proposal --------------------------------------------------
echo "D. proposeWeights:\n";

$new = A::proposeWeights($W, [], ['company_size' => 0.8]);
check('predictive dim nudged up', $new['company_size'] > 20, json_encode($new));
check('vector sums to exactly 100', array_sum($new) === 100, json_encode($new));
check('all ints', array_reduce($new, fn($c, $w) => $c && is_int($w), true));
check('zero-variance dims not increased', $new['geography'] <= 20);

$new = A::proposeWeights($W, [], ['company_size' => 1.0]);
check(
    'nudge clamped to max +5 net',
    $new['company_size'] - 20 <= 5 && array_sum($new) === 100,
    json_encode($new)
);

$new = A::proposeWeights($W, [], ['company_size' => 0.04]);
check('below noise floor -> unchanged', $new === $W);

$new = A::proposeWeights($W, [], ['company_size' => null]);
check('null correlation -> unchanged', $new === $W);

$new = A::proposeWeights($W, [], ['company_size' => -1.0]);
check(
    'negative correlation nudges down, sum still 100',
    $new['company_size'] < 20 && array_sum($new) === 100,
    json_encode($new)
);

$new = A::proposeWeights($W, ['target_title'], ['target_title' => 1.0, 'company_size' => 0.6]);
check('buyer_locked dim NEVER touched', $new['target_title'] === 20, json_encode($new));
check('locked sum keeps vector at 100', array_sum($new) === 100);

$new = A::proposeWeights($W, array_keys($W), ['company_size' => 1.0]);
check('all locked -> vector unchanged', $new === $W);

$new = A::proposeWeights(
    ['company_size' => 0, 'industry_fit' => 0, 'target_title' => 0,
     'geography' => 0, 'trigger_signals' => 0],
    [],
    ['company_size' => -1.0]
);
check('degenerate zero vector -> fail-safe unchanged', array_sum($new) === 0);

// execute() is a deliberate no-op for this per-buyer loop.
echo "E. execute():\n";
$ref = new ReflectionClass(A::class);
$exec = $ref->getMethod('execute');
check(
    'execute() returns false without DB',
    (function () {
        $obj = (new ReflectionClass(\App\Actions\AdjustIcpWeightsAction::class))
            ->newInstanceWithoutConstructor();
        return $obj->execute(123) === false;
    })()
);

echo "  -- test_adjust_weights_unit: {$pass} pass, {$fail} fail\n";
exit($fail === 0 ? 0 : 1);
