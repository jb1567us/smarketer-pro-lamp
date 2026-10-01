<?php

/**
 * Webhook bounce tests (runner style — no live providers, no network, no DB).
 *
 * Usage: php tests/compliance/WebhookBounceTest.php
 *
 * Everything under test is pure or takes injected fakes: WebhookAuth is
 * exercised through its pure helpers (secrets passed as arguments), and
 * BounceHandler::handle() gets a stub suppress callback. The DB write paths
 * (webhook_events / email_logs) fail fast here (no DB credentials in env,
 * cleared below) and are swallowed by design, so the assertions stay green.
 * The MariaDB-backed behavior (real inserts) is covered by the coordinator's
 * integration suite.
 */
declare(strict_types=1);

// Guarantee Database::getConnection() fails FAST (no TCP attempts, no sleeps).
putenv('DB_HOST');
putenv('DB_NAME');
putenv('DB_USER');
putenv('DB_PASS');

require dirname(__DIR__, 2) . '/includes/autoload.php';

use App\Webhooks\BounceHandler;
use App\Webhooks\WebhookAuth;

$failures = 0;
$passed = 0;
$skipped = 0;
function ok(bool $cond, string $name): void
{
    global $failures, $passed;
    if ($cond) { $passed++; echo "  PASS: {$name}\n"; }
    else { $failures++; echo "  FAIL: {$name}\n"; }
}
function skip(string $name, string $why): void
{
    global $skipped;
    $skipped++;
    echo "  SKIP: {$name} ({$why})\n";
}

echo "HMAC verification:\n";
$secret = bin2hex(random_bytes(32));
$body = '{"events":[{"email":"a@b.com","event":"bounce"}]}';
$goodSig = 'sha256=' . hash_hmac('sha256', $body, $secret);
ok(WebhookAuth::verifyHmac($body, $goodSig, $secret) === true, 'valid HMAC accepted');
ok(WebhookAuth::verifyHmac($body, substr($goodSig, 7), $secret) === true, 'HMAC without sha256= prefix accepted');
ok(WebhookAuth::verifyHmac($body, 'sha256=' . hash_hmac('sha256', $body, 'wrong-secret'), $secret) === false, 'HMAC with wrong secret rejected');
ok(WebhookAuth::verifyHmac($body . 'x', $goodSig, $secret) === false, 'HMAC over tampered body rejected');
ok(WebhookAuth::verifyHmac($body, 'not-hex!!', $secret) === false, 'malformed signature rejected');
ok(WebhookAuth::verifyHmac($body, '', $secret) === false, 'empty signature rejected');
ok(WebhookAuth::verifyHmac($body, $goodSig, '') === false, 'empty secret rejected');

// HMAC through the full authenticate() path: header, then ?sig= fallback.
$server = ['HTTP_X_WEBHOOK_SIGNATURE' => $goodSig];
ok(WebhookAuth::authenticate($body, $server, [], $secret, null) === true, 'authenticate accepts X-Webhook-Signature header');
ok(WebhookAuth::authenticate($body, [], ['sig' => substr($goodSig, 7)], $secret, null) === true, 'authenticate accepts ?sig= query param');
ok(WebhookAuth::authenticate($body, [], [], $secret, null) === false, 'authenticate rejects with no credentials at all');

echo "provider detection:\n";
$sgServer = [
    'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => 'abc',
    'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => '123',
];
ok(WebhookAuth::detectProvider($sgServer, []) === 'sendgrid', 'SendGrid headers -> sendgrid');
ok(WebhookAuth::detectProvider([], ['provider' => 'Mailgun']) === 'mailgun', '?provider=Mailgun -> mailgun');
ok(WebhookAuth::detectProvider([], ['provider' => '../../etc']) === 'generic', 'malicious provider value -> generic');
ok(WebhookAuth::detectProvider([], []) === 'generic', 'no hints -> generic');

echo "SendGrid ECDSA verification:\n";
if (!function_exists('openssl_pkey_new') || !function_exists('openssl_sign')) {
    skip('sendgrid ecdsa round-trip', 'openssl extension unavailable');
} else {
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $details = openssl_pkey_get_details($key);
    $x = $details['ec']['x'] ?? '';
    $y = $details['ec']['y'] ?? '';
    if (strlen($x) !== 32 || strlen($y) !== 32) {
        skip('sendgrid ecdsa round-trip', 'could not extract raw P-256 coordinates');
    } else {
        $keyB64 = base64_encode($x . $y); // exactly how SendGrid ships the verification key
        $keyHex = bin2hex($x . $y);
        $sgBody = json_encode([['email' => 'bounce@victim.com', 'event' => 'bounce']]);
        $ts = (string)time();
        openssl_sign($ts . $sgBody, $derSig, $key, OPENSSL_ALGO_SHA256);
        $sigB64 = base64_encode($derSig);
        $sgSrv = [
            'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => $sigB64,
            'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => $ts,
        ];

        ok(WebhookAuth::verifySendGridSignature($sgBody, $sigB64, $ts, $keyB64) === true, 'valid SendGrid signature accepted (base64 key)');
        ok(WebhookAuth::verifySendGridSignature($sgBody, $sigB64, $ts, $keyHex) === true, 'valid SendGrid signature accepted (hex key)');
        ok(WebhookAuth::verifySendGridSignature($sgBody . 'tampered', $sigB64, $ts, $keyB64) === false, 'tampered body rejected');
        ok(WebhookAuth::verifySendGridSignature($sgBody, $sigB64, (string)(time() - 90000), $keyB64) === false, 'stale timestamp rejected (replay)');
        ok(WebhookAuth::verifySendGridSignature($sgBody, $sigB64, $ts, base64_encode(random_bytes(64))) === false, 'wrong key rejected');
        ok(WebhookAuth::verifySendGridSignature($sgBody, $sigB64, $ts, 'not-a-key') === false, 'garbage key rejected');

        // Full authenticate(): SendGrid path must never fall back to HMAC.
        ok(WebhookAuth::authenticate($sgBody, $sgSrv, [], $secret, $keyB64) === true, 'authenticate accepts signed SendGrid request');
        ok(WebhookAuth::authenticate($sgBody, $sgSrv, [], $secret, null) === false, 'SendGrid request without configured key is rejected, not HMAC-fallback');
        ok(WebhookAuth::authenticate($sgBody, $sgSrv, ['sig' => substr($goodSig, 7)], $secret, 'wrong') === false, 'SendGrid headers + valid HMAC does not bypass ECDSA');
    }
}

echo "event normalization:\n";
ok(BounceHandler::normalize('sendgrid', ['email' => 'A@Example.COM', 'event' => 'Bounce']) === ['a@example.com', 'bounce'], 'sendgrid normalize lowercases');
ok(BounceHandler::normalize('generic', ['recipient' => 'B@Ex.COM', 'type' => 'hard_bounce']) === ['b@ex.com', 'hard_bounce'], 'generic normalize reads recipient/type');
ok(BounceHandler::normalize('sendgrid', ['email' => 'not-an-email', 'event' => 'bounce']) === null, 'invalid email -> null');
ok(BounceHandler::normalize('sendgrid', ['email' => 'a@b.com']) === null, 'missing event -> null');
ok(BounceHandler::normalize('sendgrid', ['event' => 'bounce']) === null, 'missing email -> null');

echo "classifiers:\n";
ok(BounceHandler::isHardBounce('sendgrid', 'bounce') === true, 'sendgrid bounce is hard bounce');
ok(BounceHandler::isHardBounce('sendgrid', 'dropped') === true, 'sendgrid dropped is hard bounce');
ok(BounceHandler::isHardBounce('sendgrid', 'blocked') === true, 'sendgrid blocked is hard bounce');
ok(BounceHandler::isHardBounce('sendgrid', 'delivered') === false, 'sendgrid delivered is not hard bounce');
ok(BounceHandler::isHardBounce('sendgrid', 'open') === false, 'sendgrid open is not hard bounce');
ok(BounceHandler::isHardBounce('generic', 'hard_bounce') === true, 'generic hard_bounce is hard bounce');
ok(BounceHandler::isHardBounce('generic', 'deferred') === false, 'generic deferred is not hard bounce');
ok(BounceHandler::isComplaint('sendgrid', 'spamreport') === true, 'sendgrid spamreport is complaint');
ok(BounceHandler::isComplaint('sendgrid', 'group_unsubscribe') === true, 'sendgrid group_unsubscribe is complaint');
ok(BounceHandler::isComplaint('sendgrid', 'bounce') === false, 'sendgrid bounce is not complaint');
ok(BounceHandler::isComplaint('generic', 'spam') === true, 'generic spam is complaint');

echo "handle() end-to-end (stubbed suppression):\n";
$suppressedCalls = [];
$stub = function (string $email, string $reason, ?string $source) use (&$suppressedCalls): void {
    $suppressedCalls[] = [$email, $reason, $source];
};
$events = [
    ['email' => 'bounce1@example.com', 'event' => 'bounce', 'sg_message_id' => 'msg-1'],
    ['email' => 'ok@example.com', 'event' => 'delivered'],
    ['email' => 'spam1@example.com', 'event' => 'spamreport'],
    ['email' => 'drop1@example.com', 'event' => 'dropped'],
    ['email' => 'not-an-email', 'event' => 'bounce'],
    'not-an-array',
];
$result = BounceHandler::handle('sendgrid', $events, $stub);
ok($result === ['received' => 6, 'processed' => 4, 'suppressed' => 2], 'counts are right (bad email + non-array skipped)');
ok(count($suppressedCalls) === 2, 'two suppression calls made');
$emails = array_column($suppressedCalls, 0);
sort($emails);
ok($emails === ['bounce1@example.com', 'drop1@example.com'], 'bounce + dropped suppressed, delivered/spamreport not');
foreach ($suppressedCalls as [$e, $reason, $source]) {
    ok($reason === 'hard_bounce' && $source === 'sendgrid_webhook', "suppression call for {$e} has reason hard_bounce / source sendgrid_webhook");
}

// Single (non-list) event object handling is the endpoint's job; handle() takes lists.
$result2 = BounceHandler::handle('generic', [['recipient' => 'g@example.com', 'type' => 'hard_bounce']], $stub);
ok($result2['suppressed'] === 1 && end($suppressedCalls)[2] === 'generic_webhook', 'generic provider suppresses with generic_webhook source');

echo "\n{$passed} passed, {$failures} failed, {$skipped} skipped\n";
exit($failures > 0 ? 1 : 0);
