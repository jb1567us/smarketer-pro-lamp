<?php

declare(strict_types=1);

namespace App;

/**
 * ITEM A — per-campaign blocked-send counters.
 *
 * Every place a send is REFUSED (never retried silently, never sent) records
 * one increment under a reason, attributed to the campaign that owns the
 * send. The counts persist in the campaigns table, so they survive page
 * reloads and cron restarts.
 *
 * Cost model: at most one column-exists probe per process (cached), then
 * exactly one UPDATE per refused send. No SELECT per send. A lost increment
 * on crash is acceptable; lost counts on restart are not — hence a real
 * column, not a log scrape.
 *
 * Reasons (stable keys; the dashboard renders plain-language copy from
 * assets/js/blocked_counts.js):
 *   invalid_verification — the email-verification gate refused the address
 *   suppression          — the address is on the suppression list
 *   compliance_pause     — a compliance rule refused it (CASL gate, or the
 *                          sender identity is not configured)
 *   throttle             — a send throttle / daily cap refused it for now
 *   license_revoked      — the license key was revoked; sending is paused
 *   placeholder          — a fabricated harvester placeholder address
 *
 * Recording never throws and never blocks sending: a broken counter must
 * not break the send path it is observing.
 */
final class BlockedCount
{
    public const REASON_INVALID_VERIFICATION = 'invalid_verification';
    public const REASON_SUPPRESSION = 'suppression';
    public const REASON_COMPLIANCE_PAUSE = 'compliance_pause';
    public const REASON_THROTTLE = 'throttle';
    public const REASON_LICENSE_REVOKED = 'license_revoked';
    public const REASON_PLACEHOLDER = 'placeholder';

    /**
     * Reason key => campaigns column. Keep in sync with schema.sql and
     * migrations/2026-09-24-blocked-counts.sql.
     *
     * @return array<string,string>
     */
    public static function columns(): array
    {
        return [
            self::REASON_INVALID_VERIFICATION => 'blocked_invalid_verification',
            self::REASON_SUPPRESSION          => 'blocked_suppression',
            self::REASON_COMPLIANCE_PAUSE     => 'blocked_compliance_pause',
            self::REASON_THROTTLE             => 'blocked_throttle',
            self::REASON_LICENSE_REVOKED      => 'blocked_license_revoked',
            self::REASON_PLACEHOLDER          => 'blocked_placeholder',
        ];
    }

    /** @var callable|null Test seam: receives (int $campaignId, string $reason) instead of a DB write. */
    private static $recorder = null;

    /** @var array|null Per-process cache of which blocked_* columns exist. */
    private static $installedColumns = null;

    /** @internal test-only */
    public static function setRecorder(?callable $recorder): void
    {
        self::$recorder = $recorder;
    }

    /** @internal test-only */
    public static function resetColumnCache(): void
    {
        self::$installedColumns = null;
    }

    /**
     * Increment one blocked-send counter for a campaign.
     *
     * No-op (never throws) when there is no campaign context, the reason is
     * unknown, or the migration has not been applied yet.
     *
     * @param object|null $pdo \App\PDO in production, native \PDO in tests; null = Database::getConnection().
     */
    public static function record(?int $campaignId, string $reason, $pdo = null): void
    {
        try {
            if ($campaignId === null || $campaignId <= 0) {
                return;
            }
            $columns = self::columns();
            if (!isset($columns[$reason])) {
                return;
            }
            if (self::$recorder !== null) {
                call_user_func(self::$recorder, $campaignId, $reason);
                return;
            }
            $column = $columns[$reason];
            $pdo = $pdo ?? Database::getConnection();
            if (!self::columnInstalled($pdo, $column)) {
                return; // migration not applied yet — degrade silently
            }
            // Column name comes from the fixed allowlist above, never from input.
            $stmt = $pdo->prepare(
                "UPDATE campaigns SET {$column} = {$column} + 1 WHERE id = ?"
            );
            if ($stmt !== false) {
                $stmt->execute([$campaignId]);
            }
        } catch (\Throwable $e) {
            // Counting must never break the send path it observes.
            error_log('[BlockedCount] increment failed: ' . $e->getMessage());
        }
    }

    /**
     * Read-side helper: total + per-reason breakdown from a campaigns row
     * (e.g. a row returned by api/campaigns.php). Reasons with a zero count
     * are omitted.
     *
     * @return array{total:int, breakdown:array<int,array{reason:string,count:int}>}
     */
    public static function summarize(array $campaignRow): array
    {
        $breakdown = [];
        $total = 0;
        foreach (self::columns() as $reason => $column) {
            $count = (int)($campaignRow[$column] ?? 0);
            if ($count > 0) {
                $total += $count;
                $breakdown[] = ['reason' => $reason, 'count' => $count];
            }
        }
        return ['total' => $total, 'breakdown' => $breakdown];
    }

    private static function columnInstalled($pdo, string $column): bool
    {
        if (self::$installedColumns === null) {
            self::$installedColumns = self::probeInstalledColumns($pdo);
        }
        return in_array($column, self::$installedColumns, true);
    }

    /** @return array<int,string> */
    private static function probeInstalledColumns($pdo): array
    {
        try {
            $names = [];
            if ($pdo instanceof \PDO) {
                // Native PDO — the SQLite path used by tests.
                $stmt = $pdo->query('PRAGMA table_info(campaigns)');
                $rows = $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $names[] = (string)($r['name'] ?? '');
                }
            } else {
                // \App\PDO (mysqli shim) — production MySQL. query() may
                // return false; fetchAll on false would fatal, so guard.
                $stmt = $pdo->query('SHOW COLUMNS FROM campaigns');
                $rows = $stmt === false ? [] : $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $names[] = (string)($r['Field'] ?? '');
                }
            }
            return array_values(array_intersect($names, array_values(self::columns())));
        } catch (\Throwable $e) {
            return [];
        }
    }
}
