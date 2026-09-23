<?php
/**
 * DnsAuth pure-parsing tests — NO network, NO database.
 *
 * Exercises only the offline record-parsing and decision helpers in
 * \App\DnsAuth (parseSpf / parseDkim / parseDmarc / summarize /
 * missingDescriptions / isValidDomain / senderDomain), plus the
 * invalid-domain path of checkDomain(), which returns before any DNS call.
 *
 * Usage: php tests/compliance/DnsAuthTest.php
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

$failures = 0;
$passed = 0;
function ok(bool $cond, string $name): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}

use App\DnsAuth;

// --- SPF ----------------------------------------------------------------
echo "parseSpf:\n";
ok(DnsAuth::parseSpf(['v=spf1 include:_spf.google.com ~all']) === 'v=spf1 include:_spf.google.com ~all',
    'SPF present returns the record verbatim');
ok(DnsAuth::parseSpf(['google-site-verification=abc', 'V=SPF1 ip4:1.2.3.4 -all']) === 'V=SPF1 ip4:1.2.3.4 -all',
    'case-insensitive v=spf1, SPF not the first TXT record');
ok(DnsAuth::parseSpf(['google-site-verification=abc', 'some random text']) === null,
    'absent when no TXT starts with v=spf1');
ok(DnsAuth::parseSpf([]) === null, 'absent on empty input');
ok(DnsAuth::parseSpf(['v=spf1 -all', 'v=spf1 include:x.com ~all']) === 'v=spf1 -all',
    'multiple SPF records: first match wins (deterministic)');
ok(DnsAuth::parseSpf(['v=spf10 bogus']) === null,
    'v=spf10 is not v=spf1 (version boundary)');

// --- DKIM ---------------------------------------------------------------
echo "parseDkim:\n";
$dkimFixtures = [
    'default'   => ['google-site-verification=zzz'],
    'selector1' => ['v=DKIM1; k=rsa; p=MIIBIjANBgkq...'],
    'selector2' => null, // NXDOMAIN
    'mail'      => ['v=dkim1; k=rsa; p=AAAAB3...'], // lowercase tag
    'k1'        => ['not a dkim record'],
];
ok(DnsAuth::parseDkim($dkimFixtures) === ['selector1', 'mail'],
    'selectors found in probe order, case-insensitive tag, NXDOMAIN/null skipped');
ok(DnsAuth::parseDkim(['default' => ['x=y'], 'google' => []]) === [],
    'no selectors found -> empty list');
ok(DnsAuth::parseDkim([]) === [], 'empty input -> empty list');
ok(DnsAuth::parseDkim(['google' => ['v=DKIM10 bogus']]) === [],
    'v=DKIM10 is not v=DKIM1 (version boundary)');
ok(DnsAuth::parseDkim(['default' => ['p=abc; v=DKIM1; k=rsa']]) === ['default'],
    'v=DKIM1 recognised as a later tag, not just at string start');

// --- DMARC --------------------------------------------------------------
echo "parseDmarc:\n";
$d = DnsAuth::parseDmarc(['v=DMARC1; p=reject; rua=mailto:dmarc@example.com']);
ok($d['policy'] === 'reject' && $d['record'] !== null, 'p=reject extracted');
$d = DnsAuth::parseDmarc(['V=DMARC1; P=Quarantine; pct=100']);
ok($d['policy'] === 'quarantine', 'p= tag case-insensitive, value lowercased');
$d = DnsAuth::parseDmarc(['v=DMARC1 ; p = none ; sp=none']);
ok($d['policy'] === 'none', 'p=none with surrounding whitespace');
ok(DnsAuth::parseDmarc(['google-site-verification=abc']) === ['record' => null, 'policy' => null],
    'absent when no DMARC record');
ok(DnsAuth::parseDmarc([]) === ['record' => null, 'policy' => null], 'absent on empty input');
$d = DnsAuth::parseDmarc(['v=DMARC1; rua=mailto:x@y.z']);
ok($d['record'] !== null && $d['policy'] === null, 'record without p= tag: record kept, policy null');
ok(DnsAuth::parseDmarc(['v=DMARC10; p=reject'])['record'] === null,
    'v=DMARC10 is not v=DMARC1 (version boundary)');

// --- summary matrix -----------------------------------------------------
echo "summarize:\n";
ok(DnsAuth::summarize('pass', 'pass', 'pass') === 'pass', 'all present -> pass');
ok(DnsAuth::summarize('pass', 'warn', 'pass') === 'warn', 'DKIM warn only -> warn (not fail)');
ok(DnsAuth::summarize('fail', 'pass', 'pass') === 'warn', 'SPF missing only -> warn');
ok(DnsAuth::summarize('pass', 'pass', 'fail') === 'warn', 'DMARC missing only -> warn');
ok(DnsAuth::summarize('fail', 'warn', 'fail') === 'fail', 'all three absent (dkim warn) -> fail');
ok(DnsAuth::summarize('fail', 'fail', 'fail') === 'fail', 'all three absent -> fail');
ok(DnsAuth::summarize('error', 'fail', 'error') === 'fail', 'invalid-domain statuses -> fail');
ok(DnsAuth::summarize('fail', 'warn', 'pass') === 'warn', 'two absent -> warn');

// --- domain validation --------------------------------------------------
echo "isValidDomain:\n";
ok(DnsAuth::isValidDomain('example.com') === true, 'normal domain valid');
ok(DnsAuth::isValidDomain('Example.COM.') === true, 'case + trailing dot tolerated');
ok(DnsAuth::isValidDomain('sub.example.co.uk') === true, 'multi-level valid');
ok(DnsAuth::isValidDomain('xn--nxasmq6b.example') === true, 'punycode label valid');
ok(DnsAuth::isValidDomain('') === false, 'empty invalid');
ok(DnsAuth::isValidDomain('   ') === false, 'whitespace invalid');
ok(DnsAuth::isValidDomain('not a domain!!') === false, 'spaces/punctuation invalid');
ok(DnsAuth::isValidDomain('-bad.com') === false, 'leading hyphen invalid');
ok(DnsAuth::isValidDomain('bad-.com') === false, 'trailing hyphen invalid');
ok(DnsAuth::isValidDomain('a..com') === false, 'empty label invalid');
ok(DnsAuth::isValidDomain(str_repeat('a', 64) . '.com') === false, 'overlong label invalid');
ok(DnsAuth::isValidDomain(str_repeat('a', 250) . '.com') === false, 'overlong domain invalid');

// --- sender domain extraction -------------------------------------------
echo "senderDomain:\n";
ok(DnsAuth::senderDomain('News@Example.COM ') === 'example.com', 'email -> normalized domain');
ok(DnsAuth::senderDomain('x@mail.sub.example.co.uk') === 'mail.sub.example.co.uk', 'full domain after @ preserved');
ok(DnsAuth::senderDomain('no-at-sign') === null, 'no @ -> null');
ok(DnsAuth::senderDomain('') === null, 'empty -> null');
ok(DnsAuth::senderDomain('a@') === null, 'empty domain -> null');
ok(DnsAuth::senderDomain('a@bad domain!!') === null, 'invalid domain -> null');

// --- missing descriptions -----------------------------------------------
echo "missingDescriptions:\n";
$rep = [
    'domain' => 'example.com',
    'spf' => ['status' => 'fail', 'record' => null],
    'dkim' => ['status' => 'warn', 'selectors_found' => []],
    'dmarc' => ['status' => 'pass', 'record' => 'v=DMARC1; p=none', 'policy' => 'none'],
];
$m = DnsAuth::missingDescriptions($rep);
ok(count($m) === 2 && strpos($m[0], 'v=spf1') !== false && strpos($m[0], 'example.com') !== false,
    'SPF+DKIM missing named with domain');
ok(strpos($m[1], '_domainkey.example.com') !== false, 'DKIM line names the _domainkey location');
$allOk = [
    'domain' => 'example.com',
    'spf' => ['status' => 'pass', 'record' => 'v=spf1 -all'],
    'dkim' => ['status' => 'pass', 'selectors_found' => ['selector1']],
    'dmarc' => ['status' => 'pass', 'record' => 'v=DMARC1; p=reject', 'policy' => 'reject'],
];
ok(DnsAuth::missingDescriptions($allOk) === [], 'nothing missing on a clean report');

// --- checkDomain invalid-input path (returns BEFORE any DNS call) -------
echo "checkDomain (invalid input, no network):\n";
$r = DnsAuth::checkDomain('');
ok($r['summary'] === 'fail' && $r['spf']['status'] === 'error'
    && $r['dkim']['status'] === 'fail' && $r['dmarc']['status'] === 'error',
    'empty domain: error/fail statuses, fail summary');
ok(isset($r['error']) && is_string($r['error']) && count($r['missing']) === 1,
    'invalid domain carries an error string and a single missing line');
ok(array_key_exists('record', $r['spf']) && array_key_exists('selectors_found', $r['dkim'])
    && array_key_exists('policy', $r['dmarc']) && isset($r['checked_at']) && isset($r['domain']),
    'invalid-domain report keeps the documented shape');
$r2 = DnsAuth::checkDomain('not a domain!!');
ok($r2['summary'] === 'fail' && $r2['spf']['status'] === 'error', 'garbage domain -> fail summary');

echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
