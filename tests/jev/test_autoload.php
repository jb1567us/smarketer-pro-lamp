<?php
// Test 1 (fresh process): exception classes autoload correctly on a cold
// request, and JevProvider surfaces typed exceptions — not "Class not found".
require_once __DIR__ . '/../../includes/autoload.php';

$fail = 0;
function check(string $name, bool $cond): void {
    global $fail;
    echo ($cond ? "PASS" : "FAIL") . " $name\n";
    if (!$cond) $fail++;
}

// 1. Both subclasses load via the PSR-4 autoloader (cold, no prior includes).
check('JevAuthException autoloads', class_exists('App\\Jev\\JevAuthException'));
check('JevValidationException autoloads', class_exists('App\\Jev\\JevValidationException'));

// 2. Both extend JevException.
$a = new \App\Jev\JevAuthException('auth msg');
$v = new \App\Jev\JevValidationException('validation msg');
check('JevAuthException instanceof JevException', $a instanceof \App\Jev\JevException);
check('JevValidationException instanceof JevException', $v instanceof \App\Jev\JevException);
check('messages preserved', $a->getMessage() === 'auth msg' && $v->getMessage() === 'validation msg');

// 3. Typed catch works.
try { throw $v; } catch (\App\Jev\JevValidationException $e) { check('typed catch Validation', true); }
try { throw $a; } catch (\App\Jev\JevException $e) { check('catch as base JevException', true); }

// 4. Missing API key -> JevAuthException (previously: fatal "Class not found").
putenv('TYPESAFE_API_KEY');
try {
    new \App\Jev\JevProvider(null);
    check('missing key throws JevAuthException', false);
} catch (\App\Jev\JevAuthException $e) {
    check('missing key throws JevAuthException', true);
    check('message does not leak anything sensitive', stripos($e->getMessage(), 'key=') === false);
} catch (\Throwable $e) {
    check('missing key throws JevAuthException (got ' . get_class($e) . ')', false);
}

// 5. Empty questions -> JevValidationException via systemOne (no network).
try {
    $p = new \App\Jev\JevProvider('dummy-key');
    $p->systemOne('state', []);
    check('empty questions throws JevValidationException', false);
} catch (\App\Jev\JevValidationException $e) {
    check('empty questions throws JevValidationException', true);
} catch (\Throwable $e) {
    check('empty questions throws JevValidationException (got ' . get_class($e) . ')', false);
}

exit($fail === 0 ? 0 : 1);
