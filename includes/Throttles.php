<?php

declare(strict_types=1);

namespace App;

/**
 * Throttles — outbound send rate limiting.
 *
 * Three independent caps, checked before each send:
 *   1. Per-campaign daily send cap:  campaigns.daily_send_cap (INT NULL = unlimited).
 *   2. Per-provider daily cap:       setting `throttle_provider_daily_cap` (global,
 *                                    '0' = unlimited) with optional per-provider
 *                                    override `throttle_provider_daily_cap_<provider>`.
 *   3. Global per-minute throttle:   setting `throttle_sends_per_minute`
 *                                    ('0' = disabled -> zero behavior change).
 *
 * Design: Throttles is pure logic. All data access goes through the
 * ThrottleStore interface so the decisions are unit-testable without a
 * database (see tests/compliance/ThrottleTest.php). DbThrottleStore is the
 * PDO-backed implementation used in production; it degrades gracefully when
 * the compliance DDL has not been applied yet (missing columns are treated
 * as "unlimited", never as a send blocker).
 *
 * Counting source: email_logs (status sent/failed attempts, INT unix
 * timestamp). Attempt counts (not just successes) are used because failed
 * attempts still consume provider quota / rate-limit budget.
 */
interface ThrottleStore
{
    public function getSetting(string $key, string $default): string;

    /** Per-campaign daily cap; null = unlimited (or column not installed). */
    public function getCampaignDailyCap(int $campaignId): ?int;

    public function countCampaignSendsSince(int $campaignId, int $sinceTs): int;

    public function countProviderSendsSince(string $provider, int $sinceTs): int;

    /** All send attempts (any provider) since $sinceTs. */
    public function countAllSendsSince(int $sinceTs): int;
}

/** Outcome of a single pre-send throttle check. */
final class ThrottleDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $code,        // e.g. 'ok', 'campaign_daily_cap'
        public readonly string $detail,      // human-readable explanation
        public readonly int $deferMinutes,   // suggested requeue delay when denied
    ) {
    }

    public static function allowed(): self
    {
        return new self(true, 'ok', 'within all throttle limits', 0);
    }

    public static function deferred(string $code, string $detail, int $deferMinutes): self
    {
        return new self(false, $code, $detail, max(1, $deferMinutes));
    }
}

final class Throttles
{
    public function __construct(
        private ThrottleStore $store,
        private ?int $now = null,
    ) {
    }

    public function getStore(): ThrottleStore
    {
        return $this->store;
    }

    /**
     * Check whether one more send is allowed right now.
     *
     * @param int|null    $campaignId null when the send has no campaign context
     *                                (campaign cap is then skipped).
     * @param string|null $provider   null/'' when the provider is not known up
     *                                front (e.g. smart_rotation) — provider cap
     *                                is then skipped.
     */
    public function checkSend(?int $campaignId, ?string $provider): ThrottleDecision
    {
        $now = $this->now ?? time();
        // Calendar-day window in server-local time (admin-friendly "daily").
        $dayStart = (int)mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
        $deferMin = $this->deferMinutes();

        // 1. Per-campaign daily cap.
        if ($campaignId !== null && $campaignId > 0) {
            $cap = $this->store->getCampaignDailyCap($campaignId);
            if ($cap !== null && $cap > 0) {
                $count = $this->store->countCampaignSendsSince($campaignId, $dayStart);
                if ($count >= $cap) {
                    return ThrottleDecision::deferred(
                        'campaign_daily_cap',
                        "campaign #{$campaignId} daily send cap reached ({$count}/{$cap}); resumes after midnight",
                        $deferMin
                    );
                }
            }
        }

        // 2. Per-provider daily cap (per-provider override wins over global).
        $provider = trim((string)$provider);
        if ($provider !== '') {
            $cap = $this->providerDailyCap($provider);
            if ($cap > 0) {
                $count = $this->store->countProviderSendsSince($provider, $dayStart);
                if ($count >= $cap) {
                    return ThrottleDecision::deferred(
                        'provider_daily_cap',
                        "provider '{$provider}' daily send cap reached ({$count}/{$cap}); resumes after midnight",
                        $deferMin
                    );
                }
            }
        }

        // 3. Global per-minute throttle (rolling 60-second window).
        $perMinute = (int)$this->store->getSetting('throttle_sends_per_minute', '0');
        if ($perMinute > 0) {
            $count = $this->store->countAllSendsSince($now - 60);
            if ($count >= $perMinute) {
                return ThrottleDecision::deferred(
                    'sends_per_minute',
                    "global per-minute throttle reached ({$count}/{$perMinute} in last 60s)",
                    $deferMin
                );
            }
        }

        return ThrottleDecision::allowed();
    }

    private function providerDailyCap(string $provider): int
    {
        $override = trim($this->store->getSetting('throttle_provider_daily_cap_' . $provider, ''));
        if ($override !== '' && (int)$override > 0) {
            return (int)$override;
        }
        return max(0, (int)$this->store->getSetting('throttle_provider_daily_cap', '0'));
    }

    private function deferMinutes(): int
    {
        $min = (int)$this->store->getSetting('throttle_defer_minutes', '5');
        return min(1440, max(1, $min));
    }
}

/**
 * PDO-backed ThrottleStore.
 *
 * Graceful degradation: when the compliance DDL (campaigns.daily_send_cap,
 * email_logs.campaign_id) has not been applied, the related caps are treated
 * as unlimited instead of throwing. Settings are preloaded once per instance
 * to avoid per-tick query + log noise.
 */
final class DbThrottleStore implements ThrottleStore
{
    /** @var array<string,string> */
    private array $settings = [];
    /** @var array<string,bool> column-existence cache */
    private array $colCache = [];

    public function __construct(private \App\PDO $pdo)
    {
        try {
            $rows = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
            if ($rows !== false) {
                foreach ($rows->fetchAll(\App\PDO::FETCH_KEY_PAIR) as $k => $v) {
                    $this->settings[(string)$k] = (string)$v;
                }
            }
        } catch (\Throwable $e) {
            error_log('[DbThrottleStore] settings preload failed: ' . $e->getMessage());
        }
    }

    public function getSetting(string $key, string $default): string
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    public function getCampaignDailyCap(int $campaignId): ?int
    {
        if (!$this->columnExists('campaigns', 'daily_send_cap')) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT daily_send_cap FROM campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
            if (!$row || $row['daily_send_cap'] === null || (int)$row['daily_send_cap'] <= 0) {
                return null;
            }
            return (int)$row['daily_send_cap'];
        } catch (\Throwable $e) {
            error_log('[DbThrottleStore] getCampaignDailyCap failed: ' . $e->getMessage());
            return null;
        }
    }

    public function countCampaignSendsSince(int $campaignId, int $sinceTs): int
    {
        if (!$this->columnExists('email_logs', 'campaign_id')) {
            return 0;
        }
        return $this->countWhere(
            "SELECT COUNT(*) FROM email_logs WHERE campaign_id = ? AND timestamp >= ? AND status IN ('sent','failed')",
            [$campaignId, $sinceTs]
        );
    }

    public function countProviderSendsSince(string $provider, int $sinceTs): int
    {
        return $this->countWhere(
            "SELECT COUNT(*) FROM email_logs WHERE provider_id = ? AND timestamp >= ? AND status IN ('sent','failed')",
            [$provider, $sinceTs]
        );
    }

    public function countAllSendsSince(int $sinceTs): int
    {
        return $this->countWhere(
            "SELECT COUNT(*) FROM email_logs WHERE timestamp > ? AND status IN ('sent','failed')",
            [$sinceTs]
        );
    }

    private function countWhere(string $sql, array $params): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[DbThrottleStore] count query failed: ' . $e->getMessage());
            return 0;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $this->colCache)) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS " .
                    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
                );
                $stmt->execute([$table, $column]);
                $this->colCache[$key] = ((int)$stmt->fetchColumn() > 0);
            } catch (\Throwable $e) {
                $this->colCache[$key] = false;
            }
        }
        return $this->colCache[$key];
    }
}
