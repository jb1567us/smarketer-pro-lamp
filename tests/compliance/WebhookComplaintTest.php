<?php

/**
 * Webhook complaint handler tests — fakes/stubs only, no database.
 *
 * Usage: php tests/compliance/WebhookComplaintTest.php
 *
 * Covers:
 *   1. HMAC verification: accept (prefixed, bare, uppercase hex) / reject
 *      (wrong signature, tampered body, empty secret, malformed signature).
 *   2. SendGrid "spamreport" event -> suppressed with reason 'complaint'.
 *   3. Generic {email, event: 'complaint', provider} event -> suppressed.
 *   4. Non-complaint event -> logged but NOT suppressed.
 *   5. Every received event is logged to webhook_events.
 *   6. complaintCount() uses the documented query pattern.
 */

declare(strict_types=1);

require __DIR__ . '/../../includes/autoload.php';

use App\Webhooks\ComplaintHandler;

$passed = 0;
$failures = 0;
function ok(bool $cond, string $name): void
{
    global $passed, $failures;
    if ($cond) {
        $passed++;
        echo "  PASS: {$name}\n";
    } else {
        $failures++;
        echo "  FAIL: {$name}\n";
    }
}

// --- Fakes ----------------------------------------------------------------

/** Fake PDO: captures webhook_events inserts; never touches a real database. */
class WebhookComplaintFakePdo extends PDO
{
    /** @var array<int, array{provider:?string, event_type:?string, email:?string, payload_json:?string}> */
    public array $inserts = [];
    public int $execCalls = 0;
    public string $lastPrepare = '';

    public function __construct()
    {
        // No-op: deliberately never connects anywhere.
    }

    public function exec(string $statement): int|false
    {
        $this->execCalls++;
        return 0;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastPrepare = $query;
        return new WebhookComplaintFakeStmt($this, $query);
    }
}

class WebhookComplaintFakeStmt extends PDOStatement
{
    /** @var array<int, mixed> */
    private array $params = [];

    public function __construct(
        private WebhookComplaintFakePdo $pdo,
        private string $query
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];
        if (stripos($this->query, 'INSERT INTO webhook_events') === 0) {
            $this->pdo->inserts[] = [
                'provider' => $this->params[0] ?? null,
                'event_type' => $this->params[1] ?? null,
                'email' => $this->params[2] ?? null,
                'payload_json' => $this->params[3] ?? null,
            ];
        }
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return '2';
    }
}

/** Capturing suppress stub: records (email, reason, source) instead of DB. */
$spy = new class {
    /** @var array<int, array{email:string, reason:string, source:?string}> */
    public array $calls = [];
};
$suppress = static function (string $email, string $reason, ?string $source) use ($spy): void {
    $spy->calls[] = ['email' => $email, 'reason' => $reason, 'source' => $source];
};
$resetSpy = static function () use ($spy): void {
    $spy->calls = [];
};
$calls = static function () use ($spy): array {
    return $spy->calls;
};

// --- 1. HMAC verification ----------------------------------------------------
echo "hmac:\n";
$secret = 'test-webhook-secret-123';
$body = '{"email":"a@b.co","event":"spamreport"}';
$good = hash_hmac('sha256', $body, $secret);

ok(ComplaintHandler::verifyHmac($body, 'sha256=' . $good, $secret), 'accepts sha256=<hex> signature');
ok(ComplaintHandler::verifyHmac($body, $good, $secret), 'accepts bare hex signature');
ok(ComplaintHandler::verifyHmac($body, strtoupper($good), $secret), 'accepts uppercase hex');
ok(!ComplaintHandler::verifyHmac($body, 'sha256=' . $good, 'wrong-secret'), 'rejects wrong secret');
ok(!ComplaintHandler::verifyHmac($body . 'tampered', 'sha256=' . $good, $secret), 'rejects tampered body');
ok(!ComplaintHandler::verifyHmac($body, 'sha256=' . $good, ''), 'rejects empty secret (fail closed)');
ok(!ComplaintHandler::verifyHmac($body, 'not-hex-at-all', $secret), 'rejects malformed signature');
ok(!ComplaintHandler::verifyHmac($body, '', $secret), 'rejects missing signature');

// --- 2. SendGrid spamreport -> suppressed --------------------------------------
echo "spamreport:\n";
$pdo = new WebhookComplaintFakePdo();
$resetSpy();
$result = ComplaintHandler::process(
    [['email' => 'User@Example.com', 'event' => 'spamreport', 'sg_event_id' => 'abc']],
    'sendgrid',
    $pdo,
    $suppress
);
ok($result === ['received' => 1, 'processed' => 1, 'suppressed' => 1], 'counts [1,1,1] for one spamreport');
ok(count($calls()) === 1, 'suppress called exactly once');
ok($calls()[0]['email'] === 'user@example.com', 'email normalized to lowercase');
ok($calls()[0]['reason'] === 'complaint', "reason is 'complaint'");
ok($calls()[0]['source'] === 'sendgrid_webhook', "source is '<provider>_webhook'");
ok(count($pdo->inserts) === 1, 'event logged to webhook_events');
ok($pdo->inserts[0]['provider'] === 'sendgrid', 'log row has provider');
ok($pdo->inserts[0]['event_type'] === 'spamreport', 'log row has event_type');
ok($pdo->inserts[0]['email'] === 'user@example.com', 'log row has normalized email');

// --- 3. Generic complaint event -> suppressed ------------------------------------
echo "generic:\n";
$pdo = new WebhookComplaintFakePdo();
$resetSpy();
$result = ComplaintHandler::process(
    [['email' => 'a@b.co', 'event' => 'complaint', 'provider' => 'postmark']],
    'generic',
    $pdo,
    $suppress
);
ok($result['suppressed'] === 1, 'generic complaint suppressed');
ok($calls()[0]['source'] === 'generic_webhook', "source uses the handler's provider argument");
ok($pdo->inserts[0]['event_type'] === 'complaint', "event_type 'complaint' logged");

// --- 4. Non-complaint event ignored --------------------------------------------
echo "non-complaint:\n";
$pdo = new WebhookComplaintFakePdo();
$resetSpy();
$result = ComplaintHandler::process(
    [['email' => 'a@b.co', 'event' => 'delivered']],
    'sendgrid',
    $pdo,
    $suppress
);
ok($result === ['received' => 1, 'processed' => 1, 'suppressed' => 0], 'delivered logged but not suppressed');
ok(count($calls()) === 0, 'suppress never called for non-complaint');
ok(count($pdo->inserts) === 1, 'non-complaint event still logged');

// --- 5. Mixed batch --------------------------------------------------------------
echo "mixed batch:\n";
$pdo = new WebhookComplaintFakePdo();
$resetSpy();
$result = ComplaintHandler::process(
    [
        ['email' => 'spam@x.io', 'event' => 'spamreport'],
        ['email' => 'ok@y.io', 'event' => 'delivered'],
        ['email' => 'not-an-email', 'event' => 'spamreport'],   // invalid email
        ['event' => 'spamreport'],                               // missing email
        'junk-string',                                          // not an array
    ],
    'sendgrid',
    $pdo,
    $suppress
);
ok($result['received'] === 5, 'received counts every payload entry');
ok($result['processed'] === 4, 'processed counts every array event logged');
ok($result['suppressed'] === 1, 'only the valid complaint email suppressed');
ok(count($calls()) === 1 && $calls()[0]['email'] === 'spam@x.io', 'correct address suppressed');
ok(count($pdo->inserts) === 4, 'every array event logged (junk string skipped)');
ok($pdo->execCalls === 1, 'ensureTable ran (CREATE TABLE IF NOT EXISTS)');

// --- 6. complaintCount query pattern ----------------------------------------------
echo "complaintCount:\n";
$pdo = new WebhookComplaintFakePdo();
$count = ComplaintHandler::complaintCount('sendgrid', 7, $pdo);
ok($count === 2, 'complaintCount returns the count');
$q = $pdo->lastPrepare;
ok(stripos($q, 'FROM webhook_events') !== false, 'query targets webhook_events');
ok(stripos($q, "event_type IN ('spamreport','complaint')") !== false, 'query filters complaint event types');
ok(strpos($q, 'INTERVAL 7 DAY') !== false, 'query uses the documented INTERVAL 7 DAY window');

// --- Summary ----------------------------------------------------------------------
echo "\n{$passed} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
