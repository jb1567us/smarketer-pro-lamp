<?php
// Test 2: JevProvider HTTP layer against the stub server (no real network).
require_once __DIR__ . '/../../includes/autoload.php';

$base = getenv('JEV_STUB_URL');
if (!$base) { fwrite(STDERR, "JEV_STUB_URL not set\n"); exit(2); }

$fail = 0;
function check(string $name, bool $cond): void {
    global $fail;
    echo ($cond ? "PASS" : "FAIL") . " $name\n";
    if (!$cond) $fail++;
}
function lastRequest(): array {
    $f = sys_get_temp_dir() . '/jev_stub/last_request.json';
    return json_decode(@file_get_contents($f) ?: '{}', true) ?: [];
}
function prov(string $scenario, int $maxRetries = 3): \App\Jev\JevProvider {
    global $base;
    @mkdir(sys_get_temp_dir() . '/jev_stub', 0777, true);
    file_put_contents(sys_get_temp_dir() . '/jev_stub/scenario', $scenario);
    return new \App\Jev\JevProvider('test-key', null, $base, 10, $maxRetries);
}
function q(): array { return ['qualified' => ['type' => 'noul', 'prompt' => 'Is this qualified?']]; }

// 1. Happy path returns answers; request shape is correct.
$p = prov('ok');
$answers = $p->systemOne('some state', q());
check('200 ok returns answers', ($answers['qualified']['noul'] ?? null) === 0.92);
$req = lastRequest();
$body = json_decode($req['body'] ?? '', true) ?: [];
check('sends Bearer auth', ($req['authorization'] ?? '') === 'Bearer test-key');
check('sends JSON content type', stripos($req['content_type'] ?? '', 'application/json') !== false);
check('payload has model/state/questions', ($body['model'] ?? '') === 'jev-latest' && ($body['state'] ?? '') === 'some state' && isset($body['questions']['qualified']));

// 2. 401 -> JevAuthException, no key material in message.
try { prov('unauthorized')->systemOne('s', q()); check('401 -> JevAuthException', false); }
catch (\App\Jev\JevAuthException $e) { check('401 -> JevAuthException', true); check('401 message clean', stripos($e->getMessage(), 'test-key') === false); }
catch (\Throwable $e) { check('401 -> JevAuthException (got ' . get_class($e) . ')', false); }

// 3. 422 -> JevValidationException.
try { prov('validation')->systemOne('s', q()); check('422 -> JevValidationException', false); }
catch (\App\Jev\JevValidationException $e) { check('422 -> JevValidationException', true); }
catch (\Throwable $e) { check('422 -> JevValidationException (got ' . get_class($e) . ')', false); }

// 4. 429 then 200 -> retried without caller involvement (maxRetries=1 => one retry).
$t0 = microtime(true);
$answers = prov('ratelimit', 1)->systemOne('s', q());
$elapsed = microtime(true) - $t0;
check('429 retried -> answers', ($answers['qualified']['noul'] ?? null) === 0.8);
check('retry backed off (~2s for attempt 1)', $elapsed >= 1.5 && $elapsed < 10);

// 5. 500 exhausted -> JevException (base), not a raw curl/HTTP leak.
try { prov('servererror', 0)->systemOne('s', q()); check('500 -> JevException', false); }
catch (\App\Jev\JevException $e) { check('500 -> JevException', get_class($e) === 'App\\Jev\\JevException'); }
catch (\Throwable $e) { check('500 -> JevException (got ' . get_class($e) . ')', false); }

// 6. 200 non-JSON -> JevException.
try { prov('badjson', 0)->systemOne('s', q()); check('non-JSON -> JevException', false); }
catch (\App\Jev\JevException $e) { check('non-JSON -> JevException', true); }
catch (\Throwable $e) { check('non-JSON -> JevException (got ' . get_class($e) . ')', false); }

// 7. 200 without answers key -> JevException.
try { prov('noanswers', 0)->systemOne('s', q()); check('missing answers -> JevException', false); }
catch (\App\Jev\JevException $e) { check('missing answers -> JevException', true); }
catch (\Throwable $e) { check('missing answers -> JevException (got ' . get_class($e) . ')', false); }

// 8. Convenience builders produce the documented question shapes.
check('noulQuestion shape', \App\Jev\JevProvider::noulQuestion('p') === ['type' => 'noul', 'instructions' => 'p']);
check('choiceQuestion shape', \App\Jev\JevProvider::choiceQuestion('p', ['a','b']) === ['type' => 'choice', 'instructions' => 'p', 'options' => ['a','b']]);
check('scoreQuestion shape', \App\Jev\JevProvider::scoreQuestion('p', ['low', 'mid', 'high']) === ['type' => 'score', 'instructions' => 'p', 'criteria' => ['low', 'mid', 'high']]);
try { \App\Jev\JevProvider::scoreQuestion('p', ['only-one']); check('scoreQuestion rejects <2 levels', false); }
catch (\App\Jev\JevException $e) { check('scoreQuestion rejects <2 levels', true); }
check('scoreToPercent endpoints', \App\Jev\JevProvider::scoreToPercent(0.0, 5) === 0.0 && \App\Jev\JevProvider::scoreToPercent(4.0, 5) === 100.0);
check('scoreToPercent midpoint', \App\Jev\JevProvider::scoreToPercent(2.5, 5) === 62.5);

// 9. askNoul helper runs end-to-end (stub answers are keyed 'qualified', so
// the 'q' answer is absent -> [false, 0.0]; the point is no error escapes).
$p = prov('ok');
$res = $p->askNoul('s', 'Is it qualified?');
check('askNoul returns [bool, prob]', is_array($res) && is_bool($res[0]) && is_float($res[1]));

exit($fail === 0 ? 0 : 1);
