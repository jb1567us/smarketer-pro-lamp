<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\OutreachException;

/**
 * Compliance — CAN-SPAM / CASL / GDPR sending guardrails.
 *
 * Single choke point: EmailSender::send() calls Compliance::requireCompliantSend()
 * before any provider path, so no email can leave the system without:
 *   1. Suppression-list check (opt-outs, bounces, complaints are never mailed).
 *   2. Mandatory sender identity (legal name + physical postal address) — the
 *      footer is appended by the caller via Compliance::footer().
 *   3. CASL country gate: leads in Canada (leads.country_code = 'CA') without
 *      express consent are refused, as are unknown-country leads without
 *      express consent (CASL-safe default, admin-overridable). The master
 *      toggle can disable the gate; every allow/block decision is audited
 *      to casl_decisions.
 *
 * The buyer remains the data controller and is liable for their own sending
 * practices; this class makes non-compliant sending difficult and deliberate,
 * not one click.
 */
class Compliance
{
    /**
     * Per-install secret for unsubscribe tokens. Written by install.php to
     * config/app_secret.php; falls back to a settings-table value created
     * once and reused (so tokens survive across requests).
     */
    public static function appSecret(): string
    {
        $file = dirname(__DIR__) . '/config/app_secret.php';
        if (is_file($file)) {
            $s = require $file;
            if (is_string($s) && strlen($s) >= 32) {
                return $s;
            }
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_secret'");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && is_string($row['setting_value']) && strlen($row['setting_value']) >= 32) {
            return $row['setting_value'];
        }
        $secret = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('app_secret', ?) " .
            "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$secret]);
        return $secret;
    }

    /** Signed, unguessable token for a recipient's one-click unsubscribe. */
    public static function unsubscribeToken(string $email): string
    {
        $email = strtolower(trim($email));
        $sig = hash_hmac('sha256', $email, self::appSecret());
        return rtrim(strtr(base64_encode($email), '+/', '-_'), '=') . '.' . $sig;
    }

    /** Verify a token and return the email it was minted for, or null. */
    public static function verifyUnsubscribeToken(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $email = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $email = strtolower(trim($email));
        $expected = hash_hmac('sha256', $email, self::appSecret());
        return hash_equals($expected, $parts[1]) ? $email : null;
    }

    /** Public unsubscribe URL for a recipient (null when app URL unknown). */
    public static function unsubscribeUrl(string $email): ?string
    {
        $base = trim((string)(Database::getSetting('app_base_url', '') ?? ''));
        if ($base === '') {
            return null;
        }
        return rtrim($base, '/') . '/unsubscribe.php?token=' . urlencode(self::unsubscribeToken($email));
    }

    public static function isSuppressed(string $email): bool
    {
        $email = strtolower(trim($email));
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT 1 FROM suppression_list WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                return true;
            }
            // GDPR erasure/objection rows retain only a keyed hash (no
            // plaintext). Check that leg too — but only when the GDPR DDL
            // has been applied, so databases without it keep working.
            if (Gdpr::hashColumnExists($pdo)) {
                try {
                    $hash = Gdpr::emailHash($email);
                } catch (\Throwable $e) {
                    return false;
                }
                $stmt = $pdo->prepare("SELECT 1 FROM suppression_list WHERE email_hash = ? LIMIT 1");
                $stmt->execute([$hash]);
                return (bool)$stmt->fetch();
            }
            return false;
        } catch (\Throwable $e) {
            // Fail closed: if we cannot check suppression, do not send.
            error_log('[Compliance] suppression check failed: ' . $e->getMessage());
            return true;
        }
    }

    public static function suppress(string $email, string $reason = 'unsubscribe', ?string $source = null): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO suppression_list (email, reason, source) VALUES (?, ?, ?) " .
            "ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source)"
        );
        $stmt->execute([$email, $reason, $source]);
    }

    // ═══════════════════════════════════════════════════════════════════
    // ── CASL country gate (item 8): auditable recipient-country handling ──
    // Everything item 8 owns lives between these markers. The verification
    // gate (sibling work) is a separate block — do not merge the two.
    //
    // Design: the gate keys off leads.country_code (ISO-3166-1 alpha-2,
    // NULL = unknown) instead of the old .ca TLD regex. Every allow/block
    // decision is written to casl_decisions (single INSERT, never throws).
    //
    // Settings:
    //   compliance_casl_ca_block         '1'|'0', default '1' — MASTER toggle.
    //       Kept under its historic name so existing installs keep working;
    //       it no longer means "block .ca", it means "CASL country gate on".
    //       '0' disables the whole gate; the disable itself is logged with
    //       rule 'gate_disabled'.
    //   compliance_casl_unknown_country  'block'|'allow', default 'block' —
    //       how to treat leads whose country is unknown AND whose consent is
    //       not express. 'block' is the CASL-safe default.
    // ───────────────────────────────────────────────────────────────────

    /**
     * @deprecated The .ca TLD heuristic is superseded by leads.country_code
     * (item 8). Kept for backward compatibility only — the send path no
     * longer consults it.
     */
    public static function isCanadianAddress(string $email): bool
    {
        return (bool)preg_match('/\.ca$/i', trim($email));
    }

    /**
     * Normalize a country value to uppercase ISO-3166-1 alpha-2.
     * Returns null for anything that is not exactly two ASCII letters —
     * callers store NULL (unknown country).
     */
    public static function normalizeCountryCode($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $v = strtoupper(trim($value));
        return preg_match('/^[A-Z]{2}$/', $v) === 1 ? $v : null;
    }

    /**
     * Evaluate the CASL country gate for a send. Reads the master toggle
     * and the unknown-country mode from settings, then delegates to the
     * pure evaluateCaslGateWithSettings() so the decision matrix is unit
     * testable without a database.
     *
     * Returns ['decision' => 'allow'|'block', 'rule' => string, 'message' => string].
     * 'message' is the buyer-actionable refusal text ('' on allow).
     */
    public static function evaluateCaslGate(array $lead, string $email): array
    {
        $gateEnabled = Database::getSetting('compliance_casl_ca_block', '1') === '1';
        $unknownMode = strtolower(trim((string)(Database::getSetting('compliance_casl_unknown_country', 'block') ?? 'block')));
        return self::evaluateCaslGateWithSettings($lead, $email, $gateEnabled, $unknownMode);
    }

    /**
     * Pure CASL decision logic (no settings/DB reads — pass them in).
     *
     * Rules:
     *  - gate disabled                            → allow ('gate_disabled')
     *  - country_code = 'CA', consent = express   → allow ('express_consent_allow')
     *  - country_code = 'CA', consent otherwise   → block ('ca_no_express_consent')
     *  - country known, not 'CA'                  → allow ('non_ca_country_allow')
     *  - country unknown, consent = express       → allow ('express_consent_allow')
     *  - country unknown, consent otherwise,
     *      unknownMode = 'allow'                  → allow ('unknown_country_override_allow')
     *  - country unknown, consent otherwise       → block ('unknown_country_no_consent')
     *
     * The unknown-country default is 'block' on purpose: harvested leads
     * arrive with NULL country and 'unknown' consent, and the CASL-safe
     * posture refuses them until the buyer records a country/consent or
     * explicitly opts unknown-country leads into 'allow'. Leads that
     * already carry express consent are never blocked by an unknown
     * country, so existing opted-in lists keep working.
     */
    public static function evaluateCaslGateWithSettings(array $lead, string $email, bool $gateEnabled, string $unknownMode): array
    {
        $unknownMode = strtolower(trim($unknownMode));
        if (!$gateEnabled) {
            return ['decision' => 'allow', 'rule' => 'gate_disabled', 'message' => ''];
        }

        $country = self::normalizeCountryCode($lead['country_code'] ?? null);
        $consent = strtolower(trim((string)($lead['consent_status'] ?? 'unknown')));
        if ($consent === '') {
            $consent = 'unknown';
        }

        if ($country === 'CA') {
            if ($consent === 'express') {
                return ['decision' => 'allow', 'rule' => 'express_consent_allow', 'message' => ''];
            }
            return [
                'decision' => 'block',
                'rule' => 'ca_no_express_consent',
                'message' => "Refusing to send to {$email}: Canada's CASL prohibits commercial email to harvested " .
                    "addresses without express consent. Record express consent for this lead, or disable " .
                    "'Block unconsented Canadian sends' in System Settings (you assume the legal risk).",
            ];
        }

        if ($country !== null) {
            // Known non-Canadian recipient country: the CASL gate does not apply.
            return ['decision' => 'allow', 'rule' => 'non_ca_country_allow', 'message' => ''];
        }

        if ($consent === 'express') {
            return ['decision' => 'allow', 'rule' => 'express_consent_allow', 'message' => ''];
        }

        if ($unknownMode === 'allow') {
            return ['decision' => 'allow', 'rule' => 'unknown_country_override_allow', 'message' => ''];
        }

        return [
            'decision' => 'block',
            'rule' => 'unknown_country_no_consent',
            'message' => "Refusing to send to {$email}: the recipient's country is unknown and this lead does not " .
                "have express consent. Record the lead's country and consent, or set 'Unknown-country CASL " .
                "handling' to Allow in System Settings (you assume the legal risk).",
        ];
    }

    /**
     * CASL check for a send: fetch the lead row (consent_status, country_code)
     * when the caller did not pass one, evaluate the gate, and audit the
     * decision. Returns the same ['decision','rule','message'] array as
     * evaluateCaslGate().
     */
    public static function caslCheckForSend(string $to, ?array $lead = null): array
    {
        $email = trim($to);
        if ($lead === null) {
            $lead = self::fetchLeadConsentRow($email);
        }
        $result = self::evaluateCaslGate($lead, $email);
        self::logCaslDecision(
            $email,
            self::normalizeCountryCode($lead['country_code'] ?? null),
            $result['decision'],
            $result['rule']
        );
        return $result;
    }

    /**
     * Fetch the lead row the CASL gate needs. Falls back to a
     * consent_status-only SELECT on pre-item-8 schemas (no country_code
     * column yet) so the gate keeps working during the migration window;
     * a missing row or an unreadable table both fail safe to unknown.
     */
    private static function fetchLeadConsentRow(string $email): array
    {
        try {
            $pdo = Database::getConnection();
            try {
                $stmt = $pdo->prepare("SELECT consent_status, country_code FROM leads WHERE email = ? LIMIT 1");
                $stmt->execute([strtolower($email)]);
            } catch (\Throwable $e) {
                $stmt = $pdo->prepare("SELECT consent_status FROM leads WHERE email = ? LIMIT 1");
                $stmt->execute([strtolower($email)]);
            }
            $row = $stmt->fetch();
            return $row === false ? [] : $row;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Audit every CASL decision. One INSERT, fail-open on logging errors:
     * a broken audit table must never block — or unblock — a send.
     */
    private static function logCaslDecision(string $email, ?string $countryCode, string $decision, string $rule): void
    {
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare(
                "INSERT INTO casl_decisions (email, country_code, decision, rule) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([strtolower(trim($email)), $countryCode, $decision, $rule]);
        } catch (\Throwable $e) {
            error_log('[Compliance] casl_decisions audit insert failed: ' . $e->getMessage());
        }
    }
    // ── /CASL country gate (item 8) ──

    /**
     * Enforce every pre-send compliance rule. Throws on any violation —
     * callers must not catch-and-continue past this.
     */
    public static function requireCompliantSend(string $to, ?array $lead = null): void
    {
        $to = trim($to);

        if (self::isSuppressed($to)) {
            throw new OutreachException(
                "Refusing to send: {$to} is on the suppression list (opt-out, bounce, or complaint)."
            );
        }

        $legalName = trim((string)(Database::getSetting('company_legal_name', '') ?? ''));
        $postal = trim((string)(Database::getSetting('physical_address', '') ?? ''));
        if ($legalName === '' || $postal === '') {
            throw new OutreachException(
                'Refusing to send: sender identity is not configured. Set "Company legal name" and ' .
                '"Physical postal address" in System Settings (required by CAN-SPAM for every commercial email).'
            );
        }

        // ── CASL country gate (item 8): single audited decision point ──
        // The decision (allow or block) is evaluated AND logged inside
        // caslCheckForSend(), so every send attempt leaves one audit row.
        $casl = self::caslCheckForSend($to, $lead);
        if ($casl['decision'] === 'block') {
            throw new OutreachException($casl['message']);
        }
        // ── /CASL country gate (item 8) ──

        // ── Email verification gate (item 3) ──
        self::runEmailVerificationGate($to, $lead);
    }

    // ── Email verification gate (item 3) ─────────────────────────────────────────
    // Real verification-provider adapter + send gating (compliance gap item 3).
    //
    // Settings keys (read from the settings table; API keys live in settings/ENV
    // only, never in code):
    //   verification_required      '1' enables, anything else disables (default '0' = OFF)
    //   verification_provider      provider id, currently 'millionverifier' (default)
    //   verification_api_key       provider API key; empty => gate no-ops, never throws
    //   verification_risky_action  'block'|'flag' (default 'block')
    //   verification_strict        '1' blocks sends on an 'unknown' verdict (default '0' = fail open)
    //   verification_cache_days    reuse a cached verdict for this many days (default '30'; '0' = always re-verify)
    //
    // Every verdict is persisted to leads.verification_status / leads.verified_at
    // (columns added by migrations/2026-09-23-compliance.sql).
    // ─────────────────────────────────────────────────────────────────────────────

    /** @var callable|null Test seam: overrides settings reads in this gate only. */
    private static $verificationSettingsReader = null;

    /** @var callable|null Test seam: overrides provider construction in this gate only. */
    private static $verificationProviderFactory = null;

    /** @var callable|null Test seam: overrides verdict persistence in this gate only. */
    private static $verificationStatusPersister = null;

    /** @internal test-only */
    public static function setVerificationSettingsReader(?callable $reader): void
    {
        self::$verificationSettingsReader = $reader;
    }

    /** @internal test-only */
    public static function setVerificationProviderFactory(?callable $factory): void
    {
        self::$verificationProviderFactory = $factory;
    }

    /**
     * @internal test-only
     * @param callable(string):void|null $persist Receives the recipient email via closure; signature is (string $status).
     */
    public static function setVerificationStatusPersister(?callable $persist): void
    {
        self::$verificationStatusPersister = $persist;
    }

    private static function verificationSetting(string $key, string $default): string
    {
        if (self::$verificationSettingsReader !== null) {
            return (string)call_user_func(self::$verificationSettingsReader, $key, $default);
        }
        return (string)(Database::getSetting($key, $default) ?? $default);
    }

    /**
     * Build the configured provider. An unknown provider id falls back to a
     * NullVerificationProvider (all verdicts 'unknown'), never throws.
     */
    public static function makeVerificationProvider(string $apiKey): \App\Verification\EmailVerificationProvider
    {
        $id = strtolower(trim(self::verificationSetting('verification_provider', 'millionverifier')));
        if ($id === 'millionverifier') {
            return new \App\Verification\MillionVerifierProvider($apiKey);
        }
        error_log("[Compliance] unknown verification_provider '{$id}'; using null provider (all verdicts 'unknown').");
        return new \App\Verification\NullVerificationProvider();
    }

    /**
     * Settings-level wrapper. No-op (never throws) unless the gate is enabled
     * AND an API key is configured. Looks the lead up for cache purposes when
     * the caller didn't pass one.
     */
    public static function runEmailVerificationGate(string $to, ?array $lead = null): void
    {
        if (self::verificationSetting('verification_required', '0') !== '1') {
            return; // Gate disabled — zero behavior change.
        }
        $apiKey = trim(self::verificationSetting('verification_api_key', ''));
        if ($apiKey === '') {
            error_log('[Compliance] email verification is enabled but no API key is configured; skipping verification.');
            return; // Never throw for a missing key — the admin may not be done.
        }

        $factory = self::$verificationProviderFactory
            ?? fn(string $key): \App\Verification\EmailVerificationProvider => self::makeVerificationProvider($key);
        $provider = $factory($apiKey);

        if ($lead === null) {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("SELECT verification_status, verified_at FROM leads WHERE email = ? LIMIT 1");
                $stmt->execute([strtolower(trim($to))]);
                $row = $stmt->fetch();
                $lead = $row ?: null;
            } catch (\Throwable $e) {
                // Fail open on lookup failure: the provider still gets consulted.
                $lead = null;
            }
        }

        $recordStatus = function (string $status) use ($to): void {
            if (self::$verificationStatusPersister !== null) {
                call_user_func(self::$verificationStatusPersister, $status);
                return;
            }
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare("UPDATE leads SET verification_status = ?, verified_at = NOW() WHERE email = ?");
                $stmt->execute([$status, strtolower(trim($to))]);
            } catch (\Throwable $e) {
                error_log('[Compliance] failed to persist verification status: ' . $e->getMessage());
            }
        };
        $warn = function (string $msg): void { error_log($msg); };

        self::applyVerificationGate(
            $to,
            $provider,
            $lead,
            self::verificationSetting('verification_risky_action', 'block'),
            self::verificationSetting('verification_strict', '0') === '1',
            max(0, (int)self::verificationSetting('verification_cache_days', '30')),
            $recordStatus,
            $warn
        );
    }

    /**
     * Core gate logic — pure apart from the two injected callables, so tests
     * can exercise it with fakes and no database.
     *
     * @param \App\Verification\EmailVerificationProvider $provider
     * @param array|null $lead Lead row (verification_status, verified_at) or null.
     * @param string $riskyAction 'block' or 'flag'.
     * @param bool $strict When true, an 'unknown' verdict also blocks the send.
     * @param int $cacheDays Reuse cached verdicts younger than this; 0 disables caching.
     * @param callable(string):void $recordStatus Persists a verdict.
     * @param callable(string):void $warn Logs a warning.
     * @throws \App\Exceptions\OutreachException when the send must be blocked.
     */
    public static function applyVerificationGate(
        string $to,
        \App\Verification\EmailVerificationProvider $provider,
        ?array $lead,
        string $riskyAction,
        bool $strict,
        int $cacheDays,
        callable $recordStatus,
        callable $warn
    ): void {
        $to = trim($to);

        // Cache hit: a non-'unknown' verdict younger than cacheDays needs no new lookup.
        if ($cacheDays > 0 && is_array($lead)) {
            $cached = $lead['verification_status'] ?? 'unknown';
            $checkedAt = $lead['verified_at'] ?? null;
            if ($cached !== 'unknown' && is_string($checkedAt) && $checkedAt !== '') {
                $age = time() - (int)strtotime($checkedAt);
                if ($age >= 0 && $age < $cacheDays * 86400) {
                    return; // fresh cached verdict — provider not called
                }
            }
        }

        $result = null;
        try {
            $result = $provider->verify($to);
        } catch (\Throwable $e) {
            $warn("[Compliance] verification provider threw for {$to}: " . $e->getMessage());
        }
        if ($result === null) {
            $result = new \App\Verification\EmailVerificationResult(
                \App\Verification\EmailVerificationResult::UNKNOWN,
                'none',
                new \DateTimeImmutable(),
                ['note' => 'Provider threw; treated as unknown.']
            );
        }

        $recordStatus($result->status);

        switch ($result->status) {
            case \App\Verification\EmailVerificationResult::VALID:
                return;
            case \App\Verification\EmailVerificationResult::INVALID:
                throw new OutreachException(
                    "Refusing to send to {$to}: email verification failed (status: invalid)."
                );
            case \App\Verification\EmailVerificationResult::RISKY:
                if ($riskyAction === 'block') {
                    throw new OutreachException(
                        "Refusing to send to {$to}: email verification returned 'risky' and " .
                        "'verification_risky_action' is set to block."
                    );
                }
                // 'flag': verdict already recorded above; the send proceeds.
                return;
            default: // 'unknown'
                if ($strict) {
                    throw new OutreachException(
                        "Refusing to send to {$to}: email could not be verified (provider unavailable " .
                        "or unparseable response) and strict verification mode is on."
                    );
                }
                $warn("[Compliance] email verification returned 'unknown' for {$to}; send allowed (strict mode off).");
                return;
        }
    }
    // ── end: Email verification gate (item 3) ────────────────────────────────────

    /**
     * Mandatory identity footer, appended to every outgoing message.
     * Returns [textFooter, htmlFooter]; the caller picks by body type.
     */
    public static function footer(string $to): array
    {
        $legalName = trim((string)(Database::getSetting('company_legal_name', '') ?? ''));
        $postal = trim((string)(Database::getSetting('physical_address', '') ?? ''));
        $unsub = self::unsubscribeUrl($to);

        $optOutText = $unsub
            ? "To stop receiving these emails, unsubscribe here: {$unsub}"
            : "To stop receiving these emails, reply with \"unsubscribe\".";
        $text = "\n\n--\n{$legalName}\n{$postal}\n\n{$optOutText}\n";

        $optOutHtml = $unsub
            ? 'To stop receiving these emails, <a href="' . htmlspecialchars($unsub) . '">unsubscribe here</a>.'
            : 'To stop receiving these emails, reply with "unsubscribe".';
        $html = '<br><br>--<br>' . htmlspecialchars($legalName) . '<br>' .
            nl2br(htmlspecialchars($postal)) . '<br><br>' . $optOutHtml;

        return [$text, $html];
    }

    /** Append the compliance footer to a body, detecting HTML vs text. */
    public static function appendFooter(string $body, string $to): string
    {
        [$text, $html] = self::footer($to);
        $isHtml = stripos($body, '<html') !== false || stripos($body, '<p') !== false
            || stripos($body, '<br') !== false || stripos($body, '<div') !== false;
        return $body . ($isHtml ? $html : $text);
    }

    /** List-Unsubscribe headers for raw-SMTP and provider APIs that accept them. */
    public static function listUnsubscribeHeaders(string $to, string $senderEmail): array
    {
        $headers = [];
        $url = self::unsubscribeUrl($to);
        if ($url !== null) {
            $headers[] = 'List-Unsubscribe: <' . $url . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        } else {
            $headers[] = 'List-Unsubscribe: <mailto:' . $senderEmail . '?subject=unsubscribe>';
        }
        return $headers;
    }
}
