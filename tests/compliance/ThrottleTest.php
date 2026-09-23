<?php

declare(strict_types=1);

/**
 * ThrottleTest — pure-logic tests for item 4 (no DB, no network).
 *
 * Usage: php tests/compliance/ThrottleTest.php
 *
 * Covers Throttles (cap enforcement decisions, per-minute window math) and
 * SendMonitor (complaint/bounce rate computation, auto-pause trigger and
 * no-trigger boundaries, manual-resume state) using in-memory fakes of the
 * ThrottleStore / MonitorStore interfaces.
 */

$repo = dirname(__DIR__, 2);
// The item-4 classes share one file per concern (not one class per file),
// so require them directly instead of relying on the PSR-4 autoloader.
require $repo . '/includes/Throttles.php';
require $repo . '/includes/SendMonitor.php';

use App\MonitorConfig;
use App\MonitorStore;
use App\SendMonitor;
use App\ThrottleDecision;
use App\ThrottleStore;
use App\Throttles;
use App\WindowStats;

$passed = 0;
$failed = 0;

function ok(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: {$name}\n";
    } else {
        $failed++;
        echo "  FAIL: {$name}\n";
    }
}

/* ------------------------------------------------------------------ */
/* Fake ThrottleStore                                                   */
/* ------------------------------------------------------------------ */
final class FakeThrottleStore implements ThrottleStore
{
    /** @var array<string,string> */
    public array $settings = [];
    /** @var array<int,int> campaignId => cap (null entry = unlimited) */
    public array $campaignCaps = [];
    public int $campaignCount = 0;
    public int $providerCount = 0;
    public int $recentCount = 0;
    /** @var array<int,bool> timestamps "sent" for window-math tests */
    public array $sendTimestamps = [];
    public int $now;

    public function __construct(int $now)
    {
        $this->now = $now;
    }

    public function getSetting(string $key, string $default): string
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    public function getCampaignDailyCap(int $campaignId): ?int
    {
        return $this->campaignCaps[$campaignId] ?? null;
    }

    public function countCampaignSendsSince(int $campaignId, int $sinceTs): int
    {
        return $this->campaignCount;
    }

    public function countProviderSendsSince(string $provider, int $sinceTs): int
    {
        return $this->providerCount;
    }

    public function countAllSendsSince(int $sinceTs): int
    {
        if ($this->sendTimestamps !== []) {
            $n = 0;
            foreach ($this->sendTimestamps as $ts) {
                if ($ts > $sinceTs) {
                    $n++;
                }
            }
            return $n;
        }
        return $this->recentCount;
    }
}

/* ------------------------------------------------------------------ */
/* Throttles: cap enforcement                                           */
/* ------------------------------------------------------------------ */
$NOW = 1_750_000_000; // fixed "now" for deterministic window math

// 1. Campaign daily cap: hit at exactly cap -> deferred; below -> allowed.
$store = new FakeThrottleStore($NOW);
$store->campaignCaps[7] = 100;
$store->campaignCount = 100;
$d = (new Throttles($store, $NOW))->checkSend(7, 'sendgrid');
ok(!$d->allowed && $d->code === 'campaign_daily_cap', 'campaign cap hit at exactly cap -> deferred');

$store->campaignCount = 99;
$d = (new Throttles($store, $NOW))->checkSend(7, 'sendgrid');
ok($d->allowed, 'campaign count below cap -> allowed');

// 2. Campaign cap NULL (unlimited) -> allowed regardless of count.
$store = new FakeThrottleStore($NOW);
$store->campaignCount = 99999;
$d = (new Throttles($store, $NOW))->checkSend(7, 'sendgrid');
ok($d->allowed, 'campaign cap null (unlimited) -> allowed');

// 3. No campaign context -> campaign cap skipped.
$store = new FakeThrottleStore($NOW);
$store->campaignCaps[7] = 1;
$store->campaignCount = 500;
$d = (new Throttles($store, $NOW))->checkSend(null, 'sendgrid');
ok($d->allowed, 'null campaignId skips campaign cap');

// 4. Per-provider global cap.
$store = new FakeThrottleStore($NOW);
$store->settings['throttle_provider_daily_cap'] = '500';
$store->providerCount = 500;
$d = (new Throttles($store, $NOW))->checkSend(null, 'brevo');
ok(!$d->allowed && $d->code === 'provider_daily_cap', 'provider global cap hit -> deferred');

$store->providerCount = 499;
$d = (new Throttles($store, $NOW))->checkSend(null, 'brevo');
ok($d->allowed, 'provider count below global cap -> allowed');

// 5. Per-provider override wins over global.
$store = new FakeThrottleStore($NOW);
$store->settings['throttle_provider_daily_cap'] = '500';
$store->settings['throttle_provider_daily_cap_sendgrid'] = '50';
$store->providerCount = 50;
$d = (new Throttles($store, $NOW))->checkSend(null, 'sendgrid');
ok(!$d->allowed && $d->code === 'provider_daily_cap', 'per-provider override cap hit -> deferred');

$store->providerCount = 49;
$d = (new Throttles($store, $NOW))->checkSend(null, 'sendgrid');
ok($d->allowed, 'below per-provider override -> allowed');

// 6. Override of '0' falls back to the global cap.
$store = new FakeThrottleStore($NOW);
$store->settings['throttle_provider_daily_cap'] = '500';
$store->settings['throttle_provider_daily_cap_sendgrid'] = '0';
$store->providerCount = 500;
$d = (new Throttles($store, $NOW))->checkSend(null, 'sendgrid');
ok(!$d->allowed, "override '0' falls back to global cap");

// 7. Unknown provider up front (smart_rotation) -> provider cap skipped.
$store = new FakeThrottleStore($NOW);
$store->settings['throttle_provider_daily_cap'] = '1';
$store->providerCount = 999;
$d = (new Throttles($store, $NOW))->checkSend(null, null);
ok($d->allowed, 'null provider skips provider cap');

// 8. Global per-minute throttle: rolling 60s window math.
$store = new FakeThrottleStore($NOW);
$store->settings['throttle_sends_per_minute'] = '10';
// 10 sends inside the window (oldest exactly at boundary-1s) -> deferred.
$store->sendTimestamps = [];
for ($i = 1; $i <= 10; $i++) {
    $store->sendTimestamps[] = $NOW - $i;
}
$d = (new Throttles($store, $NOW))->checkSend(null, null);
ok(!$d->allowed && $d->code === 'sends_per_minute', '10 sends in last 60s with cap 10 -> deferred');

// 9. A send 61s ago is outside the window -> allowed.
$store->sendTimestamps = [];
for ($i = 2; $i <= 10; $i++) {
    $store->sendTimestamps[] = $NOW - $i; // 9 recent
}
$store->sendTimestamps[] = $NOW - 61; // outside window
$d = (new Throttles($store, $NOW))->checkSend(null, null);
ok($d->allowed, 'send 61s ago excluded from rolling 60s window');

// 10. Per-minute disabled ('0') -> no behavior change, no counting.
$store = new FakeThrottleStore($NOW);
$store->settings['throttle_sends_per_minute'] = '0';
$store->recentCount = 100000;
$d = (new Throttles($store, $NOW))->checkSend(null, null);
ok($d->allowed, "per-minute '0' (disabled) -> allowed");

// 11. Defer minutes come from settings and are clamped.
$store = new FakeThrottleStore($NOW);
$store->settings['throttle_sends_per_minute'] = '1';
$store->settings['throttle_defer_minutes'] = '12';
$store->recentCount = 5;
$d = (new Throttles($store, $NOW))->checkSend(null, null);
ok(!$d->allowed && $d->deferMinutes === 12, 'deferMinutes honored from settings');

$store->settings['throttle_defer_minutes'] = '0';
$d = (new Throttles($store, $NOW))->checkSend(null, null);
ok($d->deferMinutes === 1, 'deferMinutes clamped to >= 1');

/* ------------------------------------------------------------------ */
/* Fake MonitorStore                                                    */
/* ------------------------------------------------------------------ */
final class FakeMonitorStore implements MonitorStore
{
    /** @var array<string,string> */
    public array $settings = [];
    /** @var array<int,WindowStats> campaignId => stats; -1 = global */
    public array $windows = [];
    /** @var array<int,array{status:string,reason:?string}> */
    public array $campaigns = [];
    public bool $webhookTable = true;
    public bool $attribution = true;
    public bool $canPause = true;

    public function getSetting(string $key, string $default): string
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    public function hasWebhookEventsTable(): bool
    {
        return $this->webhookTable;
    }

    public function hasCampaignAttribution(): bool
    {
        return $this->attribution;
    }

    public function canPauseCampaigns(): bool
    {
        return $this->canPause;
    }

    public function getActiveCampaignIds(): array
    {
        $ids = [];
        foreach ($this->campaigns as $id => $c) {
            if ($c['status'] === 'active') {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    public function getWindowStats(int $sinceTs, ?int $campaignId): WindowStats
    {
        return $this->windows[$campaignId ?? -1] ?? new WindowStats();
    }

    public function pauseCampaign(int $campaignId, string $reason): bool
    {
        if (!$this->canPause || ($this->campaigns[$campaignId]['status'] ?? '') === 'paused') {
            return false;
        }
        $this->campaigns[$campaignId] = ['status' => 'paused', 'reason' => $reason];
        return true;
    }

    public function resumeCampaign(int $campaignId): bool
    {
        if (($this->campaigns[$campaignId]['status'] ?? '') !== 'paused') {
            return false;
        }
        $this->campaigns[$campaignId] = ['status' => 'active', 'reason' => null];
        return true;
    }

    public function isCampaignPaused(int $campaignId): bool
    {
        return ($this->campaigns[$campaignId]['status'] ?? '') === 'paused';
    }
}

function monitorWith(array $settings = []): array
{
    $store = new FakeMonitorStore();
    foreach ($settings as $k => $v) {
        $store->settings[$k] = $v;
    }
    return [$store, new SendMonitor(1_750_000_000)];
}

/* ------------------------------------------------------------------ */
/* SendMonitor: rate computation + auto-pause                           */
/* ------------------------------------------------------------------ */

// 12. Complaint rate exactly at 0.1% boundary -> PAUSE (>= per compliance spec).
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 1000, bounces: 0, complaints: 1); // 0.1% exactly
$store->windows[1] = new WindowStats(sent: 1000, bounces: 0, complaints: 1);
$paused = $monitor->run($store);
ok(count($paused) === 1 && $store->isCampaignPaused(1), 'complaint rate exactly 0.1% -> auto-pause (>=)');

// 13. Complaint rate above 0.1% -> pause with reason recorded.
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 1000, bounces: 0, complaints: 2); // 0.2%
$store->windows[1] = new WindowStats(sent: 1000, bounces: 0, complaints: 2);
$paused = $monitor->run($store);
ok(count($paused) === 1 && $store->isCampaignPaused(1), 'complaint rate 0.2% -> auto-pause');
ok(str_contains((string)$store->campaigns[1]['reason'], 'complaint rate'), 'pause reason mentions complaint rate');

// 14. Bounce rate exactly at 5% boundary -> NO pause.
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 1000, bounces: 50, complaints: 0); // delivered 950, 50/950 > 5%!
$store->windows[1] = new WindowStats(sent: 1000, bounces: 50, complaints: 0);
$paused = $monitor->run($store);
// Note: delivered = 1000-50 = 950, bounce rate = 50/950 = 5.26% > 5% -> pauses.
ok(count($paused) === 1, 'bounce 50/950 delivered = 5.26% > 5% -> pause (delivered excludes bounces)');

// 15. Bounce rate exactly 5.0% of delivered -> PAUSE (>= per compliance spec).
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
// delivered = 1000 -> need sent=1053, bounces=53: 53/1000 = 5.3% no...
// exact 5%: delivered 1000, bounces 50 -> sent 1050.
$store->windows[-1] = new WindowStats(sent: 1050, bounces: 50, complaints: 0); // 50/1000 = 5.0%
$store->windows[1] = new WindowStats(sent: 1050, bounces: 50, complaints: 0);
$paused = $monitor->run($store);
ok(count($paused) === 1 && $store->isCampaignPaused(1), 'bounce rate exactly 5.0% of delivered -> auto-pause (>=)');

// 16. Min-delivered floor: 1 complaint on 1 delivered -> NO pause.
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 1, bounces: 0, complaints: 1); // 100% rate, tiny sample
$store->windows[1] = new WindowStats(sent: 1, bounces: 0, complaints: 1);
$paused = $monitor->run($store);
ok($paused === [] && !$store->isCampaignPaused(1), 'tiny sample (1 delivered) never auto-pauses');

// 17. Global breach pauses every active campaign, not just the offender.
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->campaigns[2] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 2000, bounces: 0, complaints: 5); // 0.25% global
$store->windows[1] = new WindowStats(sent: 1000, bounces: 0, complaints: 0);
$store->windows[2] = new WindowStats(sent: 1000, bounces: 0, complaints: 0);
$paused = $monitor->run($store);
ok(count($paused) === 2 && $store->isCampaignPaused(1) && $store->isCampaignPaused(2),
    'global breach pauses all active campaigns');

// 18. Per-campaign breach pauses only the offending campaign.
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->campaigns[2] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 2000, bounces: 0, complaints: 0); // global clean
$store->windows[1] = new WindowStats(sent: 1000, bounces: 0, complaints: 5);  // 0.5% breach
$store->windows[2] = new WindowStats(sent: 1000, bounces: 0, complaints: 0);
$paused = $monitor->run($store);
ok(count($paused) === 1 && $store->isCampaignPaused(1) && !$store->isCampaignPaused(2),
    'per-campaign breach pauses only the offender');

// 19. Already-paused campaign is not re-paused.
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'paused', 'reason' => 'earlier'];
$store->windows[-1] = new WindowStats(sent: 2000, bounces: 0, complaints: 0);
$store->windows[1] = new WindowStats(sent: 1000, bounces: 0, complaints: 9);
$paused = $monitor->run($store);
ok($paused === [] && $store->campaigns[1]['reason'] === 'earlier', 'already-paused campaign untouched');

// 20. monitor_auto_pause='0' -> evaluate + warn, but never pause.
[$store, $monitor] = monitorWith(['monitor_auto_pause' => '0']);
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 1000, bounces: 0, complaints: 9); // 0.9%
$paused = $monitor->run($store);
ok($paused === [] && !$store->isCampaignPaused(1), "auto_pause='0' never pauses");

// 21. Manual resume restores active state and clears the reason.
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 1000, bounces: 0, complaints: 9);
$store->windows[1] = new WindowStats(sent: 1000, bounces: 0, complaints: 9);
$monitor->run($store);
ok($store->isCampaignPaused(1), 'setup: campaign paused by monitor');
$resumed = $store->resumeCampaign(1); // mirrors api/campaigns.php action=resume
ok($resumed && !$store->isCampaignPaused(1) && $store->campaigns[1]['reason'] === null,
    'manual resume clears paused state and reason');
ok(in_array(1, $store->getActiveCampaignIds(), true), 'resumed campaign is active again');

// 22. Custom thresholds from settings are honored.
[$store, $monitor] = monitorWith(['monitor_complaint_rate_threshold' => '0.01']);
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 1000, bounces: 0, complaints: 5); // 0.5% < 1%
$store->windows[1] = new WindowStats(sent: 1000, bounces: 0, complaints: 5);
$paused = $monitor->run($store);
ok($paused === [], 'custom 1% complaint threshold: 0.5% does not pause');

// 23. Zero delivered -> rates undefined -> no pause, no division error.
[$store, $monitor] = monitorWith();
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 0, bounces: 0, complaints: 0);
$store->windows[1] = new WindowStats(sent: 0, bounces: 0, complaints: 0);
$paused = $monitor->run($store);
ok($paused === [], 'zero delivered -> no pause, no error');

// 24. Missing pause DDL (canPause=false) -> run() is a safe no-op.
[$store, $monitor] = monitorWith();
$store->canPause = false;
$store->campaigns[1] = ['status' => 'active', 'reason' => null];
$store->windows[-1] = new WindowStats(sent: 1000, bounces: 0, complaints: 9);
$paused = $monitor->run($store);
ok($paused === [] && !$store->isCampaignPaused(1), 'pause DDL missing -> no-op, no crash');

// 25. evaluateWindow unit checks: boundary strings.
$monitor = new SendMonitor();
$cfg = new MonitorConfig();
$r = $monitor->evaluateWindow(new WindowStats(1000, 0, 1), $cfg);
ok(is_string($r) && str_contains($r, 'complaint rate'), 'evaluateWindow: 0.1% exactly -> reason string (>=)');
$r = $monitor->evaluateWindow(new WindowStats(1000, 0, 2), $cfg);
ok(is_string($r) && str_contains($r, 'complaint rate'), 'evaluateWindow: 0.2% -> reason string');
$r = $monitor->evaluateWindow(new WindowStats(1050, 50, 0), $cfg);
ok(is_string($r) && str_contains($r, 'bounce rate'), 'evaluateWindow: bounce exactly 5% -> reason string (>=)');
$r = $monitor->evaluateWindow(new WindowStats(1050, 51, 0), $cfg);
ok(is_string($r) && str_contains($r, 'bounce rate'), 'evaluateWindow: bounce 5.1% -> reason string');

/* ------------------------------------------------------------------ */
echo "\n{$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
