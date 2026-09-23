<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\OutreachException;

/**
 * Gdpr — data-subject rights: export, erasure, objection, lawful basis/provenance.
 *
 * Design notes:
 * - Erasure deletes PII across every table that stores the address (see
 *   PII_TABLES below), but retains ONE hash-only suppression row so "do not
 *   contact" survives erasure. The retained row stores email_hash (a keyed
 *   HMAC with the per-install app secret) and NEVER the plaintext address.
 *   A keyed hash cannot be reversed without the secret; it is pseudonymous
 *   data kept solely to honor the objection/erasure (GDPR Art. 17/21).
 * - Objection reuses the exact unsubscribe-token construction
 *   (Compliance::unsubscribeToken / ::verifyUnsubscribeToken): proving
 *   possession of the token proves control of the address, so no login is
 *   needed. Tokens are interchangeable between unsubscribe and objection by
 *   design — both attest to the same fact.
 * - Lawful basis is stored free-form in leads.lawful_basis (VARCHAR(50)).
 *   Recommended values: consent, contract, legitimate_interest,
 *   legal_obligation. Anything else is stored verbatim (truncated to 50).
 *
 * Requires the GDPR DDL (suppression_list.email_hash + key redesign,
 * leads.lawful_basis) before erase()/object() will run — they fail closed
 * with an actionable message when it is missing. export() works without it.
 */
class Gdpr
{
    /**
     * Recommended lawful_basis values. The column itself is free-form
     * VARCHAR(50); these are the documented, preferred labels.
     */
    public const LAWFUL_BASES = ['consent', 'contract', 'legitimate_interest', 'legal_obligation'];

    /**
     * Every (table, email-column) pair known to hold a data subject's address.
     * Surveyed from schema.sql + migrations/2026-09-23-compliance.sql:
     *   leads.email, email_logs.lead_email, suppression_list.email.
     * webhook_events (bounce/complaint webhooks, items 1-2) and
     * casl_decisions (item 8 CASL audit) are probed dynamically in
     * export()/eraseReport() so they are picked up with zero code
     * changes whenever the tables exist.
     */
    private const PII_TABLES = [
        ['leads', 'email'],
        ['email_logs', 'lead_email'],
        ['suppression_list', 'email'],
    ];

    /** @var array<string,bool> per-process cache of column-existence probes */
    private static array $columnCache = [];

    // ------------------------------------------------------------------
    // Connection + schema helpers
    // ------------------------------------------------------------------

    private static function db(?PDO $pdo): PDO
    {
        return $pdo ?? Database::getConnection();
    }

    private static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    /** Cached INFORMATION_SCHEMA probe; false on any failure (fail open here — callers decide). */
    public static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnCache)) {
            return self::$columnCache[$key];
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS " .
                "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
            );
            if ($stmt === false) {
                return self::$columnCache[$key] = false;
            }
            $stmt->execute([$table, $column]);
            return self::$columnCache[$key] = (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            return self::$columnCache[$key] = false;
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.TABLES " .
                "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
            );
            if ($stmt === false) {
                return false;
            }
            $stmt->execute([$table]);
            return (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** True once the GDPR DDL has added suppression_list.email_hash. */
    public static function hashColumnExists(PDO $pdo): bool
    {
        return self::columnExists($pdo, 'suppression_list', 'email_hash');
    }

    /**
     * Find the address column of an optional table (e.g. webhook_events).
     * Prefers an exact `email` column, else the first column whose name
     * contains "email". Null when the table has no address column.
     */
    private static function emailColumn(PDO $pdo, string $table): ?string
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS " .
                "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
            );
            if ($stmt === false) {
                return null;
            }
            $stmt->execute([$table]);
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($cols as $c) {
                if (strtolower((string)$c) === 'email') {
                    return (string)$c;
                }
            }
            foreach ($cols as $c) {
                if (stripos((string)$c, 'email') !== false) {
                    return (string)$c;
                }
            }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Keyed, non-reversible pseudonym for the suppression-retention row. */
    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', self::normalize($email), Compliance::appSecret());
    }

    /** Fail closed when erase()/object() run before the GDPR DDL. */
    private static function requireGdprSchema(PDO $pdo): void
    {
        if (!self::hashColumnExists($pdo)) {
            throw new OutreachException(
                'GDPR erasure unavailable: suppression_list.email_hash is missing. ' .
                'Apply the GDPR DDL (see item-5 report) before running erasure or objection.'
            );
        }
    }

    /** @return array<int,array<string,mixed>> */
    private static function rows(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new OutreachException('Database prepare failed.');
        }
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Execute a DELETE and return the affected-row count. */
    private static function deleteCount(PDO $pdo, string $sql, array $params): int
    {
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            throw new OutreachException('Database prepare failed.');
        }
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // ------------------------------------------------------------------
    // Tokens (reuse the exact unsubscribe-token construction)
    // ------------------------------------------------------------------

    /** Mint an objection token — identical construction to unsubscribe tokens. */
    public static function objectionToken(string $email): string
    {
        return Compliance::unsubscribeToken($email);
    }

    /** Verify an objection token; returns the address it was minted for, or null. */
    public static function verifyObjectionToken(string $token): ?string
    {
        return Compliance::verifyUnsubscribeToken($token);
    }

    /** Public one-click objection URL for a recipient (null when app URL unknown). */
    public static function objectionUrl(string $email): ?string
    {
        $base = trim((string)(Database::getSetting('app_base_url', '') ?? ''));
        if ($base === '') {
            return null;
        }
        return rtrim($base, '/') . '/api/gdpr.php?action=object&token=' . urlencode(self::objectionToken($email));
    }

    // ------------------------------------------------------------------
    // Export
    // ------------------------------------------------------------------

    /**
     * Collect EVERYTHING stored about an address, as a structured array that
     * serializes directly to a downloadable JSON export.
     */
    public static function export(string $email, ?PDO $pdo = null): array
    {
        $email = self::normalize($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new OutreachException('Invalid email address.');
        }
        $pdo = self::db($pdo);
        $hash = self::hashColumnExists($pdo) ? self::emailHash($email) : null;

        $data = [
            'email' => $email,
            'exported_at' => date('c'),
            'leads' => self::rows($pdo, "SELECT * FROM leads WHERE email = ?", [$email]),
            'email_logs' => self::rows($pdo, "SELECT * FROM email_logs WHERE lead_email = ?", [$email]),
            // Suppression rows: plaintext ones, plus the hash-only erasure/
            // objection retention row when the GDPR DDL is in place.
            'suppression_list' => $hash === null
                ? self::rows($pdo, "SELECT * FROM suppression_list WHERE email = ?", [$email])
                : self::rows($pdo, "SELECT * FROM suppression_list WHERE email = ? OR email_hash = ?", [$email, $hash]),
        ];

        // webhook_events is optional (bounce/complaint webhooks land later);
        // include it automatically the day the table appears.
        if (self::tableExists($pdo, 'webhook_events')) {
            $col = self::emailColumn($pdo, 'webhook_events');
            $data['webhook_events'] = $col === null
                ? []
                : self::rows($pdo, "SELECT * FROM webhook_events WHERE `{$col}` = ?", [$email]);
        }

        // casl_decisions is optional (item-8 CASL audit table); include it
        // automatically the day the table appears.
        if (self::tableExists($pdo, 'casl_decisions')) {
            $col = self::emailColumn($pdo, 'casl_decisions');
            $data['casl_decisions'] = $col === null
                ? []
                : self::rows($pdo, "SELECT * FROM casl_decisions WHERE `{$col}` = ?", [$email]);
        }

        return $data;
    }

    // ------------------------------------------------------------------
    // Erasure
    // ------------------------------------------------------------------

    /**
     * GDPR Art. 17 erasure: delete the subject's PII from every table that
     * holds it, then retain a single hash-only suppression row (no plaintext)
     * so "do not contact" survives the erasure. Idempotent — safe to call
     * twice; the second call only refreshes the retention row.
     */
    public static function erase(string $email, ?PDO $pdo = null, string $suppressionReason = 'erasure'): void
    {
        self::eraseReport($email, $pdo, $suppressionReason);
    }

    /**
     * Same as erase(), but returns a summary of what was deleted vs retained
     * (used by the admin erase API action).
     *
     * @return array{deleted: array<string,int>, retained: array<string,mixed>, notes: string[]}
     */
    public static function eraseReport(string $email, ?PDO $pdo = null, string $suppressionReason = 'erasure'): array
    {
        $email = self::normalize($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new OutreachException('Invalid email address.');
        }
        $pdo = self::db($pdo);
        self::requireGdprSchema($pdo);

        $hash = self::emailHash($email);
        $deleted = ['email_logs' => 0, 'webhook_events' => 0, 'casl_decisions' => 0, 'suppression_list' => 0, 'leads' => 0];

        $pdo->exec('START TRANSACTION');
        try {
            $deleted['email_logs'] = self::deleteCount($pdo, "DELETE FROM email_logs WHERE lead_email = ?", [$email]);

            if (self::tableExists($pdo, 'webhook_events')) {
                $col = self::emailColumn($pdo, 'webhook_events');
                if ($col !== null) {
                    $deleted['webhook_events'] = self::deleteCount(
                        $pdo,
                        "DELETE FROM webhook_events WHERE `{$col}` = ?",
                        [$email]
                    );
                }
            }

            // CASL audit rows hold the subject's plaintext address: erase them.
            if (self::tableExists($pdo, 'casl_decisions')) {
                $col = self::emailColumn($pdo, 'casl_decisions');
                if ($col !== null) {
                    $deleted['casl_decisions'] = self::deleteCount(
                        $pdo,
                        "DELETE FROM casl_decisions WHERE `{$col}` = ?",
                        [$email]
                    );
                }
            }

            // Plaintext suppression rows ARE PII: remove them, then retain
            // only the keyed hash below.
            $deleted['suppression_list'] = self::deleteCount($pdo, "DELETE FROM suppression_list WHERE email = ?", [$email]);

            // Leads last: agent_traces.lead_id is ON DELETE CASCADE, so the
            // subject's agent traces go with the lead rows automatically.
            $deleted['leads'] = self::deleteCount($pdo, "DELETE FROM leads WHERE email = ?", [$email]);

            // Retention row: email stays NULL (no plaintext PII). The
            // uq_suppression_hash unique key makes this upsert idempotent.
            $stmt = $pdo->prepare(
                "INSERT INTO suppression_list (email, email_hash, reason, source) VALUES (NULL, ?, ?, ?) " .
                "ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source), created_at = CURRENT_TIMESTAMP"
            );
            if ($stmt === false) {
                throw new OutreachException('Database prepare failed.');
            }
            $stmt->execute([$hash, $suppressionReason, 'gdpr-erasure']);

            $pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }

        return [
            'deleted' => $deleted,
            'retained' => [
                'suppression_list' => [
                    'email_hash' => $hash,
                    'reason' => $suppressionReason,
                    'note' => 'Hash-only row: no plaintext PII retained. ' .
                        'HMAC-SHA256 keyed with the per-install app secret; not reversible without it.',
                ],
            ],
            'notes' => [
                'agent_traces rows linked to deleted leads were removed via the leads(id) ON DELETE CASCADE foreign key.',
                'The retained hash row keeps Compliance::isSuppressed() refusing future sends to this address.',
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Objection (Art. 21) — one-click, no login, token in URL
    // ------------------------------------------------------------------

    /**
     * Handle a one-click objection: verify the signed token, suppress, then
     * erase marketing data while keeping the suppression row (reason
     * 'objection'). Returns false for forged/invalid tokens — callers must
     * respond generically either way so unauthenticated callers can never
     * learn whether an address exists in the system.
     */
    public static function object(string $email, string $token, ?PDO $pdo = null): bool
    {
        $email = self::normalize($email);
        $verified = self::verifyObjectionToken($token);
        if ($verified === null || !hash_equals($verified, $email)) {
            return false;
        }
        // Plaintext suppression first (single choke point for the plaintext
        // schema), then erase() deletes that plaintext row and retains only
        // the keyed hash with reason 'objection'.
        Compliance::suppress($email, 'objection', 'gdpr-objection');
        self::erase($email, $pdo, 'objection');
        return true;
    }
}
