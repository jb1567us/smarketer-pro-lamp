<?php

declare(strict_types=1);

namespace App;

/**
 * SendMonitor — complaint-rate / bounce-rate monitoring with auto-pause.
 *
 * Rolling 7-day window, evaluated per campaign AND globally:
 *   complaint_rate = complaints / delivered
 *   bounce_rate    = hard_bounces / delivered
 *   delivered      = sent - bounces
 *
 * A campaign is auto-paused (campaigns.status='paused', plus paused_reason /
 * paused_at) when, with at least `monitor_min_delivered` delivered emails in
 * the window, either rate meets or exceeds its threshold:
 *   - monitor_complaint_rate_threshold  default '0.001' (0.1% — the Gmail/Yahoo
 *     bulk-sender danger line)
 *   - monitor_bounce_rate_threshold     default '0.05'  (5% — standard list-hygiene ceiling)
 * Thresholds are inclusive (>=): exactly 0.1% / 5.0% DOES trigger.
 *
 * Auto-pause is ON by default (monitor_auto_pause='1'); the min-delivered
 * floor keeps it from firing on tiny samples, so it only triggers on
 * genuinely abusive patterns. Resume is ALWAYS manual (admin action).
 *
 * Data sources (degrade gracefully when missing):
 *   - complaints: webhook_events event_type IN ('spamreport','complaint');
 *     0 when the sibling agent's webhook_events table does not exist yet.
 *   - bounces:    webhook_events event_type IN ('bounce','blocked','dropped');
 *     falls back to email_logs status='failed' when webhook_events is absent.
 *     (Fallback caveat: email_logs 'failed' rows are failed send attempts,
 *     not true bounces — the webhook source is strictly better.)
 *   - per-campaign attribution joins webhook_events.email to
 *     email_logs.lead_email via the email_logs.campaign_id column; when that
 *     column is missing, only the global evaluation runs.
 *
 * Like Throttles, the decision logic is pure (WindowStats + MonitorConfig +
 * evaluateWindow) and the MonitorStore interface keeps it unit-testable.
 */
final class WindowStats
{
    public function __construct(
        public int $sent = 0,
        public int $bounces = 0,
        public int $complaints = 0,
    ) {
    }

    public function delivered(): int
    {
        return max(0, $this->sent - $this->bounces);
    }

    public function complaintRate(): ?float
    {
        $d = $this->delivered();
        return $d > 0 ? $this->complaints / $d : null;
    }

    public function bounceRate(): ?float
    {
        $d = $this->delivered();
        return $d > 0 ? $this->bounces / $d : null;
    }
}

final class MonitorConfig
{
    public function __construct(
        public readonly float $complaintThreshold = 0.001,
        public readonly float $bounceThreshold = 0.05,
        public readonly bool $autoPause = true,
        public readonly int $minDelivered = 100,
    ) {
    }

    public static function fromStore(MonitorStore $store): self
    {
        $f = function (string $key, string $default) use ($store): float {
            $v = (float)$store->getSetting($key, $default);
            return $v >= 0 ? $v : (float)$default;
        };
        return new self(
            $f('monitor_complaint_rate_threshold', '0.001'),
            $f('monitor_bounce_rate_threshold', '0.05'),
            $store->getSetting('monitor_auto_pause', '1') === '1',
            max(1, (int)$store->getSetting('monitor_min_delivered', '100')),
        );
    }
}

interface MonitorStore
{
    public function getSetting(string $key, string $default): string;

    public function hasWebhookEventsTable(): bool;

    /** email_logs.campaign_id present (needed for per-campaign attribution). */
    public function hasCampaignAttribution(): bool;

    /** campaigns.status/paused_reason/paused_at present (needed to pause). */
    public function canPauseCampaigns(): bool;

    /** Campaigns eligible for evaluation: active and not already paused. */
    public function getActiveCampaignIds(): array;

    public function getWindowStats(int $sinceTs, ?int $campaignId): WindowStats;

    /** Pause the campaign; false when already paused or pause infra missing. */
    public function pauseCampaign(int $campaignId, string $reason): bool;

    public function isCampaignPaused(int $campaignId): bool;
}

final class SendMonitor
{
    public const WINDOW_SECONDS = 7 * 86400;

    public function __construct(private ?int $now = null)
    {
    }

    /**
     * Evaluate one window's stats against the config.
     * Returns a human-readable breach reason, or null when clean.
     */
    public function evaluateWindow(WindowStats $stats, MonitorConfig $config): ?string
    {
        $delivered = $stats->delivered();
        if ($delivered < $config->minDelivered) {
            return null; // Sample too small — never auto-pause on noise.
        }
        $cr = $stats->complaintRate();
        if ($cr !== null && $cr >= $config->complaintThreshold) {
            return sprintf(
                'complaint rate %.3f%% reached/exceeded %.3f%% threshold (%d complaints / %d delivered)',
                $cr * 100, $config->complaintThreshold * 100, $stats->complaints, $delivered
            );
        }
        $br = $stats->bounceRate();
        if ($br !== null && $br >= $config->bounceThreshold) {
            return sprintf(
                'bounce rate %.2f%% reached/exceeded %.2f%% threshold (%d bounces / %d delivered)',
                $br * 100, $config->bounceThreshold * 100, $stats->bounces, $delivered
            );
        }
        return null;
    }

    /**
     * Run one monitoring pass: evaluate global + per-campaign windows and
     * auto-pause offenders. Returns a list of human-readable pause summaries
     * (empty when nothing was paused). Never throws — callers (cron) must not
     * let the monitor break the queue.
     *
     * @return string[]
     */
    public function run(MonitorStore $store): array
    {
        $paused = [];
        try {
            $config = MonitorConfig::fromStore($store);
            $since = ($this->now ?? time()) - self::WINDOW_SECONDS;

            if (!$config->autoPause) {
                // Still evaluate and warn so the admin sees the signal in logs.
                $breach = $this->evaluateWindow($store->getWindowStats($since, null), $config);
                if ($breach !== null) {
                    error_log('[SendMonitor] auto-pause is OFF; global 7-day window breached: ' . $breach);
                }
                return $paused;
            }

            if (!$store->canPauseCampaigns()) {
                return $paused; // Pause DDL not applied yet — nothing to do.
            }

            // Global breach pauses every active campaign.
            $globalBreach = $this->evaluateWindow($store->getWindowStats($since, null), $config);
            if ($globalBreach !== null) {
                foreach ($store->getActiveCampaignIds() as $cid) {
                    if ($store->pauseCampaign((int)$cid, 'Global 7-day window: ' . $globalBreach)) {
                        $paused[] = "campaign #{$cid} paused (global 7-day window: {$globalBreach})";
                    }
                }
                return $paused;
            }

            // Otherwise evaluate each campaign individually.
            if (!$store->hasCampaignAttribution()) {
                return $paused; // email_logs.campaign_id missing — global only.
            }
            foreach ($store->getActiveCampaignIds() as $cid) {
                $cid = (int)$cid;
                if ($store->isCampaignPaused($cid)) {
                    continue;
                }
                $breach = $this->evaluateWindow($store->getWindowStats($since, $cid), $config);
                if ($breach !== null && $store->pauseCampaign($cid, '7-day window: ' . $breach)) {
                    $paused[] = "campaign #{$cid} paused (7-day window: {$breach})";
                }
            }
        } catch (\Throwable $e) {
            error_log('[SendMonitor] run failed: ' . $e->getMessage());
        }
        return $paused;
    }
}

/**
 * PDO-backed MonitorStore. Degrades gracefully: missing webhook_events table
 * -> complaint/bounce-webhook counts are 0 (bounce falls back to email_logs
 * failed attempts); missing email_logs.campaign_id -> per-campaign
 * evaluation is skipped; missing campaigns.status columns -> pausing is a
 * no-op that returns false.
 */
final class DbMonitorStore implements MonitorStore
{
    /** @var array<string,string> */
    private array $settings = [];
    /** @var array<string,bool> */
    private array $cache = [];

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
            error_log('[DbMonitorStore] settings preload failed: ' . $e->getMessage());
        }
    }

    public function getSetting(string $key, string $default): string
    {
        return array_key_exists($key, $this->settings) ? $this->settings[$key] : $default;
    }

    public function hasWebhookEventsTable(): bool
    {
        return $this->tableExists('webhook_events');
    }

    public function hasCampaignAttribution(): bool
    {
        return $this->columnExists('email_logs', 'campaign_id');
    }

    public function canPauseCampaigns(): bool
    {
        return $this->columnExists('campaigns', 'status')
            && $this->columnExists('campaigns', 'paused_reason')
            && $this->columnExists('campaigns', 'paused_at');
    }

    public function getActiveCampaignIds(): array
    {
        try {
            if ($this->canPauseCampaigns()) {
                $stmt = $this->pdo->query(
                    "SELECT id FROM campaigns WHERE is_active = 1 AND status = 'active' ORDER BY id ASC"
                );
            } else {
                // Pre-DDL fallback: everything active is eligible (but pauseCampaign
                // will no-op, so run() is effectively read-only).
                $stmt = $this->pdo->query("SELECT id FROM campaigns WHERE is_active = 1 ORDER BY id ASC");
            }
            if ($stmt === false) {
                return [];
            }
            return array_map('intval', $stmt->fetchAll(\App\PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            error_log('[DbMonitorStore] getActiveCampaignIds failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getWindowStats(int $sinceTs, ?int $campaignId): WindowStats
    {
        $stats = new WindowStats();
        $campFilter = '';
        $params = [$sinceTs];
        if ($campaignId !== null) {
            $campFilter = ' AND campaign_id = ?';
            $params[] = $campaignId;
        }

        // Sent: email_logs rows the provider accepted.
        $stats->sent = $this->countWhere(
            "SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND timestamp >= ?" . $campFilter,
            $params
        );

        if ($this->hasWebhookEventsTable()) {
            // Bounces from provider webhooks (preferred source).
            $stats->bounces = $this->countWhere(
                "SELECT COUNT(*) FROM webhook_events w WHERE w.event_type IN ('bounce','blocked','dropped') " .
                "AND w.created_at >= FROM_UNIXTIME(?)" . $this->campaignJoin($campaignId),
                $params
            );
            $stats->complaints = $this->countWhere(
                "SELECT COUNT(*) FROM webhook_events w WHERE w.event_type IN ('spamreport','complaint') " .
                "AND w.created_at >= FROM_UNIXTIME(?)" . $this->campaignJoin($campaignId),
                $params
            );
        } else {
            // Degraded: approximate bounces with failed send attempts; complaints unknown.
            $stats->bounces = $this->countWhere(
                "SELECT COUNT(*) FROM email_logs WHERE status = 'failed' AND timestamp >= ?" . $campFilter,
                $params
            );
            $stats->complaints = 0;
        }
        return $stats;
    }

    /**
     * Attribute a webhook event to a campaign by joining the recipient email
     * back to email_logs (which carries campaign_id). Empty when no campaign
     * scoping is requested.
     */
    private function campaignJoin(?int $campaignId): string
    {
        if ($campaignId === null) {
            return '';
        }
        return " AND EXISTS (SELECT 1 FROM email_logs e WHERE e.lead_email = w.email AND e.campaign_id = " .
            (int)$campaignId . ")";
    }

    public function pauseCampaign(int $campaignId, string $reason): bool
    {
        if (!$this->canPauseCampaigns() || $this->isCampaignPaused($campaignId)) {
            return false;
        }
        try {
            // is_active=0 as well so the existing dashboard (which keys off
            // is_active) immediately treats the campaign as stopped. Resume
            // restores it; see api/campaigns.php action=resume.
            $stmt = $this->pdo->prepare(
                "UPDATE campaigns SET status = 'paused', paused_reason = ?, paused_at = NOW(), is_active = 0 " .
                "WHERE id = ? AND status <> 'paused'"
            );
            $stmt->execute([$reason, $campaignId]);
            $did = $stmt->rowCount() > 0;
            if ($did) {
                error_log("[SendMonitor] auto-paused campaign #{$campaignId}: {$reason}");
            }
            return $did;
        } catch (\Throwable $e) {
            error_log('[DbMonitorStore] pauseCampaign failed: ' . $e->getMessage());
            return false;
        }
    }

    public function isCampaignPaused(int $campaignId): bool
    {
        if (!$this->canPauseCampaigns()) {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare("SELECT status FROM campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            return $stmt->fetchColumn() === 'paused';
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function countWhere(string $sql, array $params): int
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[DbMonitorStore] count query failed: ' . $e->getMessage());
            return 0;
        }
    }

    private function tableExists(string $table): bool
    {
        $key = 'table:' . $table;
        if (!array_key_exists($key, $this->cache)) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.TABLES " .
                    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
                );
                $stmt->execute([$table]);
                $this->cache[$key] = ((int)$stmt->fetchColumn() > 0);
            } catch (\Throwable $e) {
                $this->cache[$key] = false;
            }
        }
        return $this->cache[$key];
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = 'col:' . $table . '.' . $column;
        if (!array_key_exists($key, $this->cache)) {
            try {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS " .
                    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
                );
                $stmt->execute([$table, $column]);
                $this->cache[$key] = ((int)$stmt->fetchColumn() > 0);
            } catch (\Throwable $e) {
                $this->cache[$key] = false;
            }
        }
        return $this->cache[$key];
    }
}
