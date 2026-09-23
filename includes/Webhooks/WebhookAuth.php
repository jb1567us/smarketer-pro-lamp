<?php

declare(strict_types=1);

namespace App\Webhooks;

use App\Database;

/**
 * WebhookAuth — authenticates inbound provider webhooks WITHOUT login auth.
 *
 * Provider servers (SendGrid, etc.) cannot hold app credentials, so webhook
 * endpoints must NOT call \App\Auth::requireApiAuth(). Instead a request is
 * accepted when EITHER of these verifies:
 *
 *   (a) SendGrid signed event webhooks: ECDSA P-256 signature over
 *       (timestamp || raw body), presented in the headers
 *       X-Twilio-Email-Event-Webhook-Signature and
 *       X-Twilio-Email-Event-Webhook-Timestamp. The public key comes from the
 *       setting 'sendgrid_webhook_public_key'.
 *
 *       ADMIN ACTION REQUIRED: in SendGrid go to Settings > Mail Settings >
 *       Event Webhook, turn on the "Signed Event Webhook" toggle, and paste
 *       the "Verification Key" shown there into System Settings
 *       (sendgrid_webhook_public_key). Without that key, signed SendGrid
 *       requests are REJECTED (fail closed) — they never fall back to HMAC.
 *
 *   (b) Generic HMAC-SHA256: HMAC-SHA256 of the raw request body keyed by the
 *       per-install 'webhook_secret' setting (auto-generated with
 *       random_bytes() on first use and stored in settings). The client sends
 *       it as header "X-Webhook-Signature: sha256=<hex>" or as the query
 *       parameter ?sig=<hex> (the "sha256=" prefix is optional there).
 *
 * The pure verification helpers (verifyHmac / verifySendGridSignature /
 * authenticate) take all inputs as arguments so they are unit-testable
 * without a database. Production glue (webhookSecret / fromRequest) reads
 * settings and superglobals.
 */
class WebhookAuth
{
    public const SENDGRID_SIG_HEADER = 'X-Twilio-Email-Event-Webhook-Signature';
    public const SENDGRID_TS_HEADER = 'X-Twilio-Email-Event-Webhook-Timestamp';
    public const HMAC_HEADER = 'X-Webhook-Signature';

    /** Max age of a signed SendGrid timestamp (replay protection). */
    public const SENDGRID_MAX_SKEW_SECONDS = 86400; // 24h, allows delayed retries

    /**
     * Per-install HMAC secret. Generated once with random_bytes() and stored
     * in the settings table so it survives restarts; never in code.
     */
    public static function webhookSecret(): string
    {
        $secret = Database::getSetting('webhook_secret', '');
        if (is_string($secret) && strlen($secret) >= 32) {
            return $secret;
        }
        $secret = bin2hex(random_bytes(32));
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('webhook_secret', ?) " .
            "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$secret]);
        return $secret;
    }

    /** Read a request header from a $_SERVER-style array (case-insensitive). */
    public static function serverHeader(array $server, string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $val = $server[$key] ?? '';
        return is_string($val) ? trim($val) : '';
    }

    /** Pure HMAC-SHA256 verification. Timing-safe via hash_equals(). */
    public static function verifyHmac(string $rawBody, string $provided, string $secret): bool
    {
        $provided = trim($provided);
        if (stripos($provided, 'sha256=') === 0) {
            $provided = substr($provided, 7);
        }
        if ($secret === '' || $provided === '' || !ctype_xdigit($provided)) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, strtolower($provided));
    }

    /**
     * Pure SendGrid ECDSA verification.
     *
     * SendGrid signs SHA-256(timestamp || rawBody) with ECDSA P-256 and sends
     * the DER signature base64-encoded. The verification key from the
     * SendGrid "Signed Event Webhook" setting is the raw 64-byte (x||y)
     * public key, base64-encoded (SendGrid docs' examples decode it exactly
     * this way). For operator convenience we also accept the key as hex or
     * as a PEM-encoded public key.
     */
    public static function verifySendGridSignature(
        string $rawBody,
        string $signatureB64,
        string $timestamp,
        string $publicKeySetting
    ): bool {
        if ($signatureB64 === '' || $timestamp === '' || $publicKeySetting === '') {
            return false;
        }
        if (!ctype_digit($timestamp)) {
            return false;
        }
        // Replay protection: reject stale or future-dated timestamps.
        if (abs(time() - (int)$timestamp) > self::SENDGRID_MAX_SKEW_SECONDS) {
            return false;
        }
        $sig = base64_decode($signatureB64, true);
        if ($sig === false || $sig === '') {
            return false;
        }
        $pem = self::sendGridKeyToPem($publicKeySetting);
        if ($pem === null) {
            return false;
        }
        // '@': a syntactically-valid-but-off-curve key makes openssl_verify
        // emit a warning; the false return already means "reject".
        return @openssl_verify($timestamp . $rawBody, $sig, $pem, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Convert a SendGrid verification-key setting value to a PEM SPKI public
     * key. Accepts: PEM ("-----BEGIN PUBLIC KEY-----..."), hex of the raw
     * 64-byte x||y key, or base64 of the raw 64-byte x||y key.
     */
    public static function sendGridKeyToPem(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (stripos($value, '-----BEGIN') === 0) {
            return $value; // already PEM; openssl_verify validates it
        }
        $raw = null;
        $noSpace = preg_replace('/\s+/', '', $value) ?? '';
        if (ctype_xdigit($noSpace) && strlen($noSpace) === 128) {
            $raw = hex2bin($noSpace);
        } elseif (($decoded = base64_decode($noSpace, true)) !== false && strlen($decoded) === 64) {
            $raw = $decoded;
        }
        if (!is_string($raw) || strlen($raw) !== 64) {
            return null;
        }
        // DER SubjectPublicKeyInfo for an uncompressed P-256 point.
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01" .
               "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00\x04" . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" .
            chunk_split(base64_encode($der), 64, "\n") .
            "-----END PUBLIC KEY-----\n";
    }

    /**
     * Authenticate one webhook request. Pure given its arguments.
     *
     * SendGrid signed requests are detected by the presence of EITHER SendGrid
     * header and are verified with ECDSA only — never downgraded to HMAC.
     * Otherwise the generic HMAC path is tried (header, then ?sig=).
     *
     * @param array $server $_SERVER-style array
     * @param array $query  $_GET-style array
     */
    public static function authenticate(
        string $rawBody,
        array $server,
        array $query,
        string $webhookSecret,
        ?string $sendGridPublicKey
    ): bool {
        $sgSig = self::serverHeader($server, self::SENDGRID_SIG_HEADER);
        $sgTs = self::serverHeader($server, self::SENDGRID_TS_HEADER);
        if ($sgSig !== '' || $sgTs !== '') {
            // SendGrid-signed request: fail closed if the admin has not
            // configured the verification key (never fall back to HMAC).
            if ($sendGridPublicKey === null || trim($sendGridPublicKey) === '') {
                error_log('[WebhookAuth] SendGrid-signed webhook received but sendgrid_webhook_public_key is not configured; rejecting.');
                return false;
            }
            return self::verifySendGridSignature($rawBody, $sgSig, $sgTs, $sendGridPublicKey);
        }

        $hmac = self::serverHeader($server, self::HMAC_HEADER);
        if ($hmac === '' && isset($query['sig']) && is_string($query['sig'])) {
            $hmac = $query['sig'];
        }
        return self::verifyHmac($rawBody, $hmac, $webhookSecret);
    }

    /** Detect the provider from request metadata ('sendgrid' or 'generic'). */
    public static function detectProvider(array $server, array $query): string
    {
        if (
            self::serverHeader($server, self::SENDGRID_SIG_HEADER) !== '' ||
            self::serverHeader($server, self::SENDGRID_TS_HEADER) !== ''
        ) {
            return 'sendgrid';
        }
        $p = $query['provider'] ?? '';
        if (is_string($p) && preg_match('/^[a-z0-9][a-z0-9_-]{0,29}$/i', $p)) {
            return strtolower($p);
        }
        return 'generic';
    }

    /**
     * Production glue: authenticate the current request from superglobals and
     * settings. Returns [ok, provider].
     */
    public static function fromRequest(string $rawBody): array
    {
        $provider = self::detectProvider($_SERVER, $_GET);
        $ok = self::authenticate(
            $rawBody,
            $_SERVER,
            $_GET,
            self::webhookSecret(),
            Database::getSetting('sendgrid_webhook_public_key', '')
        );
        return [$ok, $provider];
    }
}
