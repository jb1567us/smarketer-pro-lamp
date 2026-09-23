<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Compliance;
use App\Database;

/**
 * BounceHandler — turns provider bounce webhooks into suppression-list rows.
 *
 * Flow: api/webhook_bounce.php authenticates the request via WebhookAuth,
 * then calls handle($provider, $events). Each raw event is normalized to
 * (email, event_type):
 *   - hard bounce equivalents (SendGrid: bounce/dropped/blocked;
 *     generic: bounce/hard_bounce/dropped/blocked/...) ->
 *     Compliance::suppress($email, 'hard_bounce', '<provider>_webhook')
 *   - complaint-ish events (SendGrid: spamreport/group_unsubscribe;
 *     generic: spam/complaint/abuse/unsubscribe) -> recorded, then handed to
 *     \App\Webhooks\ComplaintHandler IF that class exists (sibling agent's
 *     item); otherwise recorded and skipped. This handler never creates it.
 *   - everything else (delivered, open, click, deferred, processed...) is
 *     recorded in webhook_events and otherwise ignored.
 *
 * Every processed event is written to webhook_events (if the table exists)
 * and hard bounces are ALSO written to email_logs (status 'bounced' once the
 * ENUM is extended, 'failed' until then).
 *
 * handle() accepts an optional $suppressFn so tests can stub the
 * suppression side-effect without a database. Default behavior is unchanged.
 */
class BounceHandler
{
    /** Cap events per request so one huge payload can't DoS the loop. */
    public const MAX_EVENTS = 1000;

    /** Provider-specific hard-bounce event names (lowercase). */
    private const HARD_BOUNCE = [
        'sendgrid' => ['bounce' => true, 'dropped' => true, 'blocked' => true],
    ];

    /** Generic hard-bounce event names for providers without a mapping. */
    private const HARD_BOUNCE_GENERIC = [
        'bounce' => true, 'hard_bounce' => true, 'hardbounce' => true,
        'dropped' => true, 'blocked' => true, 'failed_permanent' => true,
        'permanent_failed' => true, 'permanent_failure' => true,
        'undeliverable' => true, 'hardfail' => true,
    ];

    /** Complaint-ish event names route to the complaint handler. */
    private const COMPLAINT = [
        'sendgrid' => ['spamreport' => true, 'group_unsubscribe' => true],
    ];

    private const COMPLAINT_GENERIC = [
        'spamreport' => true, 'spam_report' => true, 'spam' => true,
        'complaint' => true, 'abuse' => true, 'abuse_report' => true,
        'group_unsubscribe' => true, 'unsubscribe' => true,
    ];

    /** True when the DB is known-unreachable this request; skip DB writes. */
    private static bool $dbFailed = false;

    /**
     * Process a batch of raw provider events.
     *
     * @param string   $provider   'sendgrid' or a generic provider name
     * @param array    $events     list of raw event arrays
     * @param callable $suppressFn optional (string $email, string $reason, ?string $source): void
     * @return array{received:int,processed:int,suppressed:int}
     */
    public static function handle(string $provider, array $events, ?callable $suppressFn = null): array
    {
        $provider = strtolower(trim($provider)) !== '' ? strtolower(trim($provider)) : 'generic';
        $suppressFn ??= [Compliance::class, 'suppress'];

        $received = count($events);
        $processed = 0;
        $suppressed = 0;

        foreach (array_slice($events, 0, self::MAX_EVENTS) as $event) {
            if (!is_array($event)) {
                continue;
            }
            $normalized = self::normalize($provider, $event);
            if ($normalized === null) {
                continue;
            }
            [$email, $type] = $normalized;

            self::logWebhookEvent($provider, $type, $email, $event);
            $processed++;

            if (self::isHardBounce($provider, $type)) {
                $suppressFn($email, 'hard_bounce', $provider . '_webhook');
                self::logEmailLog($provider, $type, $email, $event);
                $suppressed++;
            } elseif (self::isComplaint($provider, $type)) {
                // Handoff to the complaint item (sibling agent). The event is
                // already recorded above; if the handler class is not present
                // in this tree yet, skip cleanly. Never let a complaint-event
                // failure break bounce processing.
                $handler = \App\Webhooks\ComplaintHandler::class;
                if (class_exists($handler)) {
                    try {
                        if (method_exists($handler, 'process')) {
                            $handler::process([$event], $provider);
                        } elseif (method_exists($handler, 'handle')) {
                            $handler::handle($provider, [$event]);
                        }
                    } catch (\Throwable $e) {
                        error_log('[BounceHandler] ComplaintHandler handoff failed: ' . $e->getMessage());
                    }
                }
            }
        }

        return ['received' => $received, 'processed' => $processed, 'suppressed' => $suppressed];
    }

    /**
     * Normalize one raw provider event to [email, event_type] (both
     * lowercase/trimmed), or null when the event is unusable. Pure — no DB.
     */
    public static function normalize(string $provider, array $event): ?array
    {
        if (strtolower($provider) === 'sendgrid') {
            $email = $event['email'] ?? null;
            $type = $event['event'] ?? null;
        } else {
            $email = $event['email'] ?? $event['recipient'] ?? $event['to'] ?? $event['address'] ?? null;
            $type = $event['event'] ?? $event['type'] ?? $event['event_type'] ?? $event['status'] ?? null;
        }
        if (!is_string($email) || !is_string($type)) {
            return null;
        }
        $email = strtolower(trim($email));
        $type = strtolower(trim($type));
        if ($type === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return [$email, $type];
    }

    /** Pure classifier: is this event type a hard bounce? */
    public static function isHardBounce(string $provider, string $type): bool
    {
        $provider = strtolower($provider);
        $type = strtolower(trim($type));
        $map = self::HARD_BOUNCE[$provider] ?? null;
        if ($map !== null) {
            return isset($map[$type]);
        }
        return isset(self::HARD_BOUNCE_GENERIC[$type]);
    }

    /** Pure classifier: is this event type complaint-ish? */
    public static function isComplaint(string $provider, string $type): bool
    {
        $provider = strtolower($provider);
        $type = strtolower(trim($type));
        $map = self::COMPLAINT[$provider] ?? null;
        if ($map !== null) {
            return isset($map[$type]);
        }
        return isset(self::COMPLAINT_GENERIC[$type]);
    }

    /**
     * Record the event in webhook_events. Never throws: if the table does not
     * exist yet (DDL pending) or the DB is unreachable, the event is still
     * processed — the suppression write is what matters.
     */
    private static function logWebhookEvent(string $provider, string $type, string $email, array $event): void
    {
        if (self::$dbFailed) {
            return;
        }
        try {
            $pdo = Database::getConnection();
            $payload = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($payload)) {
                $payload = '{}';
            }
            $stmt = $pdo->prepare(
                "INSERT INTO webhook_events (provider, event_type, email, payload_json) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$provider, $type, $email, substr($payload, 0, 60000)]);
        } catch (\Throwable $e) {
            // Table missing or DB down: remember for this request so we don't
            // pay for a failed connect on every event in the batch.
            self::$dbFailed = true;
            error_log('[BounceHandler] webhook_events write skipped: ' . $e->getMessage());
        }
    }

    /**
     * Record a hard bounce in email_logs. Uses status 'bounced' when the ENUM
     * has been extended (see DDL in the item report); 'failed' until then so
     * the insert works on the current schema. Never throws.
     */
    private static function logEmailLog(string $provider, string $type, string $email, array $event): void
    {
        if (self::$dbFailed) {
            return;
        }
        try {
            $pdo = Database::getConnection();
            $msgId = $event['sg_message_id'] ?? $event['message_id'] ?? $event['sg_event_id'] ?? null;
            $status = self::emailLogsBounceStatus($pdo);
            $stmt = $pdo->prepare(
                "INSERT INTO email_logs (lead_email, provider_id, provider_msg_id, status, metadata_json, timestamp) " .
                "VALUES (?, ?, ?, ?, ?, ?)"
            );
            $payload = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $stmt->execute([
                $email,
                substr($provider . '_webhook', 0, 50),
                is_string($msgId) ? substr($msgId, 0, 255) : null,
                $status,
                is_string($payload) ? substr($payload, 0, 60000) : null,
                time(),
            ]);
        } catch (\Throwable $e) {
            error_log('[BounceHandler] email_logs write skipped: ' . $e->getMessage());
        }
    }

    /** Prefer 'bounced' status when the ENUM supports it, else 'failed'. */
    private static function emailLogsBounceStatus(object $pdo): string
    {
        static $status = null;
        if ($status !== null) {
            return $status;
        }
        $status = 'failed';
        try {
            $row = $pdo->query("SHOW COLUMNS FROM email_logs LIKE 'status'")->fetch();
            if (is_array($row) && isset($row['Type']) && stripos((string)$row['Type'], "'bounced'") !== false) {
                $status = 'bounced';
            }
        } catch (\Throwable $e) {
            // stay on 'failed'
        }
        return $status;
    }
}
