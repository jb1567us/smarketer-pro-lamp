<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Compliance;
use PDO;

/**
 * ComplaintHandler — feedback-loop / complaint webhook processing.
 *
 * Accepts complaint events from email providers and turns them into
 * suppression-list entries, so a recipient who reports spam is never
 * mailed again:
 *
 *   - SendGrid event webhook: JSON array of events, complaint events use
 *     "event": "spamreport".
 *   - Generic format: a single JSON object (or array of them) shaped
 *     {email, event: "complaint"|"spamreport", provider}.
 *
 * Every received event is logged to the webhook_events table, which is the
 * source of truth for the complaint-rate monitor. Dedupe is inherited from
 * Compliance::suppress(): suppression_list has UNIQUE KEY uq_suppression_email,
 * so re-reporting the same address is a no-op update, never a duplicate row.
 *
 * New setting key: `webhook_secret` — per-install secret used by
 * api/webhook_complaint.php to HMAC-verify incoming webhooks.
 */
class ComplaintHandler
{
    /**
     * Event types treated as spam complaints. SendGrid reports them as
     * "spamreport"; the generic format allows the "complaint" alias.
     */
    public const COMPLAINT_EVENTS = ['spamreport', 'complaint'];

    /**
     * Exact DDL for the webhook_events table. Run at runtime via ensureTable()
     * (CREATE TABLE IF NOT EXISTS) and also listed here so the migration can
     * apply the identical statement idempotently.
     */
    public const TABLE_DDL = <<<'SQL'
        CREATE TABLE IF NOT EXISTS webhook_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(50) NOT NULL,
            event_type VARCHAR(50) NOT NULL,
            email VARCHAR(255) NOT NULL DEFAULT '',
            payload_json TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_we_email (email),
            INDEX idx_we_provider_event (provider, event_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL;

    /**
     * Timing-safe HMAC-SHA256 verification of a raw webhook body.
     *
     * The signature header may be bare hex or prefixed with "sha256=".
     * Anything malformed (wrong length, non-hex, empty secret) fails closed.
     */
    public static function verifyHmac(string $rawBody, string $providedSignature, string $secret): bool
    {
        $providedSignature = trim($providedSignature);
        if (str_starts_with(strtolower($providedSignature), 'sha256=')) {
            $providedSignature = substr($providedSignature, 7);
        }
        $providedSignature = strtolower($providedSignature);
        if ($secret === '' || !preg_match('/\A[0-9a-f]{64}\z/', $providedSignature)) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $providedSignature);
    }

    /** Create webhook_events if it does not exist (safe to call every time). */
    public static function ensureTable(?PDO $pdo = null): void
    {
        ($pdo ?? \App\Database::getConnection())->exec(self::TABLE_DDL);
    }

    /**
     * Normalize one raw event to [email|null, eventType|null].
     *
     * Email is lowercased/trimmed and must pass FILTER_VALIDATE_EMAIL;
     * anything else yields null (the event is still logged, just not acted on).
     */
    private static function normalizeEvent(array $event): array
    {
        $rawEmail = $event['email'] ?? null;
        $email = null;
        if (is_string($rawEmail)) {
            $candidate = strtolower(trim($rawEmail));
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
                $email = $candidate;
            }
        }
        $rawType = $event['event'] ?? null;
        $eventType = (is_string($rawType) && trim($rawType) !== '')
            ? strtolower(trim($rawType))
            : null;
        return [$email, $eventType];
    }

    /** Log one event to webhook_events. Every received event is logged. */
    private static function logEvent(
        PDO $pdo,
        string $provider,
        ?string $eventType,
        ?string $email,
        array $payload
    ): void {
        $stmt = $pdo->prepare(
            'INSERT INTO webhook_events (provider, event_type, email, payload_json) ' .
            'VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $provider,
            $eventType ?? 'unknown',
            $email ?? '',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Process a batch of webhook events.
     *
     * Complaint events (with a valid email) are suppressed with reason
     * 'complaint' and source '<provider>_webhook'; all other events are only
     * logged. Never throws for a single bad event — each event is independent.
     *
     * @param array<int,mixed> $events
     * @return array{received:int, processed:int, suppressed:int}
     */
    public static function process(
        array $events,
        string $provider,
        ?PDO $pdo = null,
        ?callable $suppressFn = null
    ): array {
        $pdo = $pdo ?? \App\Database::getConnection();
        self::ensureTable($pdo);
        $suppressFn = $suppressFn ?? static function (string $email, string $reason, ?string $source): void {
            Compliance::suppress($email, $reason, $source);
        };

        $received = count($events);
        $processed = 0;
        $suppressed = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            [$email, $eventType] = self::normalizeEvent($event);
            self::logEvent($pdo, $provider, $eventType, $email, $event);
            $processed++;
            if ($email === null
                || $eventType === null
                || !in_array($eventType, self::COMPLAINT_EVENTS, true)
            ) {
                continue;
            }
            $suppressFn($email, 'complaint', $provider . '_webhook');
            $suppressed++;
        }

        return ['received' => $received, 'processed' => $processed, 'suppressed' => $suppressed];
    }

    /**
     * Complaint count for a provider over the trailing window — the query a
     * complaint-rate monitor polls. The $days value is cast to int and
     * interpolated (never bound into the INTERVAL expression) so the query
     * matches the documented pattern exactly:
     *
     *   SELECT COUNT(*) FROM webhook_events
     *   WHERE provider = ?
     *     AND event_type IN ('spamreport','complaint')
     *     AND created_at >= NOW() - INTERVAL 7 DAY
     */
    public static function complaintCount(string $provider, int $days = 7, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?? \App\Database::getConnection();
        $days = max(1, (int)$days);
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM webhook_events WHERE provider = ? ' .
            "AND event_type IN ('spamreport','complaint') " .
            "AND created_at >= NOW() - INTERVAL {$days} DAY"
        );
        $stmt->execute([$provider]);
        return (int)$stmt->fetchColumn();
    }
}
