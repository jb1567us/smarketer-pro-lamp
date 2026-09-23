<?php
/**
 * Item 7: provider-specific List-Unsubscribe / List-Unsubscribe-Post injection.
 *
 * Usage: php tests/compliance/UnsubscribeHeadersTest.php
 *
 * Uses reflection to call the private EmailSender::withListUnsubscribe()
 * helper for each provider dispatch key and asserts both headers land in the
 * provider's own custom-headers location. No network, no database: expected
 * values are derived live from Compliance::listUnsubscribeHeaders() (which
 * falls back to the mailto: variant when no app_base_url is configured).
 *
 * Expected header locations per provider:
 *   brevo    - payload['headers'][<Name>]           (object map)
 *   resend   - payload['headers'][<Name>]           (object map, also injected inline in sendResend)
 *   sendgrid - payload['headers'][<Name>]           (object map, also injected inline in sendSendGrid)
 *   mailtrap - payload['headers'][<Name>]           (object map)
 *   netcore  - payload['headers'][<Name>]           (Pepipost v5.1 'headers' object)
 *   zoho     - payload['mime_headers'][<Name>]      (ZeptoMail object map)
 *   mailersend - payload['headers'][] = ['name'=>, 'value'=>] (array of pairs; Pro/Enterprise feature)
 *   postmark - payload['Headers'][] = ['Name'=>, 'Value'=>]   (array of pairs)
 *   mailjet  - payload['Messages'][0]['Headers'][<Name>]      (v3.1 object map)
 *   mailgun  - payload['h:<Name>']                 (form fields, 'h:' prefix)
 *   smtp     - payload returned untouched; raw MIME headers are written
 *              directly by sendSmtpSocket() instead.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
// EMAIL_SENDER_TEST_AUTOLOAD lets the suite run against a scratch copy of the
// tree (e.g. when sibling in-progress work in the shared tree does not parse).
$autoload = getenv('EMAIL_SENDER_TEST_AUTOLOAD') ?: $repo . '/includes/autoload.php';
require $autoload;

/**
 * Hermetic database stub: Database::getConnection() retries 5x with sleep(2)
 * when the configured MySQL is unreachable, which would make this unit test
 * take minutes. Seed a fast-failing PDO subclass instead so getSetting()
 * fails closed to its default immediately, with no sockets and no sleeping.
 */
final class UnsubscribeHeadersTestFastFailPDO extends \App\PDO
{
    public function __construct()
    {
        // Deliberately skip the mysqli connection.
    }

    public function prepare(string $query): \App\PDOStatement|false
    {
        throw new \App\PDOException('no database in unit test');
    }
}
$dbRef = new ReflectionClass(\App\Database::class);
$dbInstance = $dbRef->getProperty('instance');
$dbInstance->setAccessible(true);
$dbInstance->setValue(null, new UnsubscribeHeadersTestFastFailPDO());

$failures = 0;
$passed = 0;
function ok(bool $cond, string $name): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}

$ref = new ReflectionClass(\App\EmailSender::class);
$withUnsub = $ref->getMethod('withListUnsubscribe');
$withUnsub->setAccessible(true);

$to = 'lead@example.com';
$sender = 'sales@example.org';

// Expected name => value map, straight from the compliance source of truth.
$raw = \App\Compliance::listUnsubscribeHeaders($to, $sender);
$expected = [];
foreach ($raw as $h) {
    $parts = explode(':', $h, 2);
    if (count($parts) === 2) {
        $expected[trim($parts[0])] = trim($parts[1]);
    }
}
ok(count($expected) >= 1, 'listUnsubscribeHeaders() produced at least one header');

/** @param array<string,mixed> $payload */
$call = function (array $payload, string $provider) use ($withUnsub, $to, $sender): array {
    return $withUnsub->invoke(null, $payload, $provider, $to, $sender);
};

// --- Object-map providers: headers / mime_headers --------------------------
foreach (['brevo', 'resend', 'sendgrid', 'mailtrap', 'netcore'] as $provider) {
    $out = $call([], $provider);
    $headers = (isset($out['headers']) && is_array($out['headers'])) ? $out['headers'] : [];
    $found = 0;
    foreach ($expected as $name => $value) {
        if (($headers[$name] ?? null) === $value) { $found++; }
    }
    ok($found === count($expected), "{$provider}: headers object carries all " . count($expected) . " header(s)");
}

// ZeptoMail uses mime_headers.
$out = $call([], 'zoho');
$headers = (isset($out['mime_headers']) && is_array($out['mime_headers'])) ? $out['mime_headers'] : [];
$found = 0;
foreach ($expected as $name => $value) {
    if (($headers[$name] ?? null) === $value) { $found++; }
}
ok($found === count($expected), 'zoho: mime_headers object carries all header(s)');
ok(!isset($out['headers']), 'zoho: no generic headers key is added');

// --- List-of-pairs providers ------------------------------------------------
/** @param list<array<string,string>> $list */
$extractPairs = function (array $list, string $nameKey, string $valueKey): array {
    $map = [];
    foreach ($list as $entry) {
        if (is_array($entry) && isset($entry[$nameKey], $entry[$valueKey])) {
            $map[$entry[$nameKey]] = $entry[$valueKey];
        }
    }
    return $map;
};

// MailerSend: headers = [{name, value}]
$out = $call([], 'mailersend');
$got = $extractPairs($out['headers'] ?? [], 'name', 'value');
$found = 0;
foreach ($expected as $name => $value) {
    if (($got[$name] ?? null) === $value) { $found++; }
}
ok($found === count($expected), 'mailersend: headers [{name,value}] carries all header(s)');

// Postmark: Headers = [{Name, Value}]
$out = $call([], 'postmark');
$got = $extractPairs($out['Headers'] ?? [], 'Name', 'Value');
$found = 0;
foreach ($expected as $name => $value) {
    if (($got[$name] ?? null) === $value) { $found++; }
}
ok($found === count($expected), 'postmark: Headers [{Name,Value}] carries all header(s)');
ok(!isset($out['headers']), 'postmark: no lowercase headers key is added');

// --- Mailjet ----------------------------------------------------------------
$payload = ['Messages' => [[
    'From' => ['Email' => $sender],
    'To' => [['Email' => $to]],
    'Subject' => 'x',
    'HTMLPart' => '<p>x</p>',
]]];
$out = $call($payload, 'mailjet');
$headers = $out['Messages'][0]['Headers'] ?? [];
$found = 0;
foreach ($expected as $name => $value) {
    if (($headers[$name] ?? null) === $value) { $found++; }
}
ok($found === count($expected), 'mailjet: Messages[0].Headers carries all header(s)');
ok($out['Messages'][0]['Subject'] === 'x', 'mailjet: rest of the message payload untouched');

// Mailjet with no Messages array: payload must pass through unchanged.
$out = $call(['foo' => 'bar'], 'mailjet');
ok($out === ['foo' => 'bar'], 'mailjet: payload without Messages is returned unchanged');

// --- Mailgun ----------------------------------------------------------------
$out = $call(['from' => $sender, 'to' => $to, 'subject' => 'x', 'html' => '<p>x</p>'], 'mailgun');
$found = 0;
foreach ($expected as $name => $value) {
    if (($out['h:' . $name] ?? null) === $value) { $found++; }
}
ok($found === count($expected), 'mailgun: h:<Name> form fields carry all header(s)');
ok($out['from'] === $sender && $out['to'] === $to, 'mailgun: base payload fields untouched');

// --- SMTP / unknown providers: payload untouched ---------------------------
$in = ['anything' => 'goes'];
ok($call($in, 'smtp') === $in, 'smtp: payload untouched (raw MIME headers live in sendSmtpSocket)');
ok($call($in, 'custom_smtp') === $in, 'custom_smtp: payload untouched');
ok($call($in, 'weird') === $in, 'unknown provider: payload untouched');

// --- Provider key is case-insensitive (dispatch lowercases it) --------------
$out = $call([], 'BREVO');
ok(isset($out['headers']) && is_array($out['headers']), 'provider key case-insensitive (BREVO)');

// --- Idempotency / strictly additive ---------------------------------------
$first = $call([], 'postmark');
$second = $call($first, 'postmark');
ok($second === $first, 'postmark: second call is a no-op (no duplicate headers)');
$first = $call([], 'mailersend');
$second = $call($first, 'mailersend');
ok($second === $first, 'mailersend: second call is a no-op');

$pre = ['headers' => ['X-Campaign' => 'abc123']];
$out = $call($pre, 'brevo');
ok($out['headers']['X-Campaign'] === 'abc123', 'brevo: pre-existing headers are preserved');
$out = $call(['Headers' => [['Name' => 'X-Campaign', 'Value' => 'abc123']]], 'postmark');
$got = $extractPairs($out['Headers'], 'Name', 'Value');
ok(($got['X-Campaign'] ?? null) === 'abc123', 'postmark: pre-existing headers are preserved');

echo "\n{$passed} passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
