<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\OutreachException;

/**
 * Licensing — soft phone-home license lock (client side).
 *
 * Design principles (non-negotiable):
 *   1. SOFT: the app NEVER bricks. Unlicensed, unreachable, mistyped-key,
 *      and even grace-expired installs keep every feature working.
 *   2. The ONLY hard consequence is an explicitly REVOKED key (seller-side
 *      refund/chargeback), and even that pauses SENDING ONLY — dashboard,
 *      leads, campaigns, and settings keep working. Un-revoke and sending
 *      resumes automatically on the next check.
 *   3. Every network call has a short timeout and a catch-all fallback to
 *      cached/grace behavior. A dead license server is indistinguishable
 *      from "no license server" as far as the buyer is concerned.
 *
 * Settings keys (settings table):
 *   license_server_url   base URL of the license server API, e.g.
 *                        https://license.example.com/api — empty = licensing
 *                        disabled entirely ("Unlicensed", zero behavior change)
 *   license_key          buyer's key (treated as a secret by api/settings.php)
 *   license_verdict      HMAC-signed JSON verdict blob (see signVerdict())
 *
 * Verdict blob (signed):
 *   { status, reason, detail, checked_at, last_ok_at, grace_until,
 *     domain, domains, max_domains, key_fp }
 * status ∈ valid | invalid | revoked | unreachable | unlicensed | unknown
 *
 * Distributors: set DEFAULT_LICENSE_SERVER_URL before packaging a release
 * zip so buyers don't have to type it. It is always overridable via the
 * license_server_url setting — moving the license server later is just a
 * settings change, never a code change.
 */
class Licensing
{
    /** Distributors: bake the license-server API base URL in here before zipping. */
    public const DEFAULT_LICENSE_SERVER_URL = '';

    public const GRACE_SECONDS = 14 * 86400;   // 14-day grace on unreachable
    public const RECHECK_SECONDS = 86400;       // daily re-validation from cron
    public const HTTP_TIMEOUT = 10;             // seconds, every license HTTP call

    /** @var callable|null Injectable HTTP layer (tests stub this; null = curl). */
    private static $httpHandler = null;

    /** @var array|null Per-request cache of the verified verdict. */
    private static $verdictCache = null;

    // ── configuration ────────────────────────────────────────────────────

    public static function setHttpHandler(?callable $fn): void
    {
        self::$httpHandler = $fn;
        self::$verdictCache = null;
    }

    /** License-server API base URL, or null when licensing is disabled. */
    public static function serverUrl(): ?string
    {
        $url = trim((string)(Database::getSetting('license_server_url', '') ?? ''));
        if ($url === '' && static::DEFAULT_LICENSE_SERVER_URL !== '') {
            $url = static::DEFAULT_LICENSE_SERVER_URL;
        }
        $url = rtrim(trim($url), '/');
        return $url === '' ? null : $url;
    }

    public static function hasKey(): bool
    {
        return trim((string)(Database::getSetting('license_key', '') ?? '')) !== '';
    }

    /** Licensing is in play only when a server URL is configured. */
    public static function isEnabled(): bool
    {
        return self::serverUrl() !== null;
    }

    // ── normalization (must match license-server/lib/common.php exactly) ─

    public static function normalizeDomain(string $input): string
    {
        $d = strtolower(trim($input));
        $d = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $d);
        if (strpos($d, '@') !== false) {
            $d = substr($d, (int)strrpos($d, '@') + 1);
        }
        $d = preg_split('#[/?#]#', $d, 2)[0];
        $d = preg_replace('#:\d+$#', '', $d);
        $d = rtrim(trim($d), '.');
        if (str_starts_with($d, 'www.')) {
            $d = substr($d, 4);
        }
        return $d;
    }

    public static function normalizeKey(string $key): string
    {
        return strtoupper(trim($key));
    }

    /** Best-effort domain for this install (web request → Host header; CLI → hostname). */
    public static function currentDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (is_string($host) && trim($host) !== '') {
            return self::normalizeDomain($host);
        }
        $hn = gethostname();
        return self::normalizeDomain(is_string($hn) ? $hn : '');
    }

    // ── signed verdict blob ──────────────────────────────────────────────

    /**
     * Sign a verdict array. $secretOverride exists for unit tests (no DB);
     * production always uses Compliance::appSecret().
     */
    public static function signVerdict(array $verdict, ?string $secretOverride = null): string
    {
        $secret = $secretOverride ?? Compliance::appSecret();
        $json = json_encode($verdict, JSON_UNESCAPED_SLASHES);
        $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $b64, $secret);
        return $b64 . '.' . $sig;
    }

    /**
     * Verify + decode a verdict blob. Returns null on any tamper/format
     * problem — callers treat that as "unknown, revalidate".
     */
    public static function verifyVerdict(string $blob, ?string $secretOverride = null): ?array
    {
        $parts = explode('.', $blob, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $secret = $secretOverride ?? Compliance::appSecret();
        $expected = hash_hmac('sha256', $parts[0], $secret);
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }
        $json = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if (!is_string($json)) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    public static function defaultVerdict(string $status = 'unknown', string $reason = ''): array
    {
        return [
            'status' => $status,
            'reason' => $reason,
            'detail' => '',
            'checked_at' => 0,
            'last_ok_at' => null,
            'grace_until' => null,
            'domain' => '',
            'domains' => [],
            'max_domains' => 0,
            'key_fp' => '',
        ];
    }

    public static function storeVerdict(array $verdict): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (\'license_verdict\', ?) ' .
            'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([self::signVerdict($verdict)]);
        self::$verdictCache = $verdict;
    }

    /** Read + verify the cached verdict; never throws, never returns garbage. */
    public static function readVerdict(): array
    {
        if (self::$verdictCache !== null) {
            return self::$verdictCache;
        }
        try {
            $blob = Database::getSetting('license_verdict', '');
            if (is_string($blob) && $blob !== '') {
                $v = self::verifyVerdict($blob);
                if (is_array($v)) {
                    self::$verdictCache = $v;
                    return $v;
                }
            }
        } catch (\Throwable $e) {
            // fall through to default
        }
        self::$verdictCache = self::defaultVerdict();
        return self::$verdictCache;
    }

    // ── HTTP ─────────────────────────────────────────────────────────────

    /**
     * POST JSON to the license server. Returns the decoded array on
     * HTTP 200/409 with valid JSON, null on ANY failure (network, timeout,
     * bad JSON, unexpected status). Never throws.
     */
    public static function httpPost(string $path, array $payload): ?array
    {
        $base = self::serverUrl();
        if ($base === null) {
            return null;
        }
        $url = $base . '/' . ltrim($path, '/');
        try {
            if (self::$httpHandler !== null) {
                $res = (self::$httpHandler)($url, $payload);
                return is_array($res) ? $res : null;
            }
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => self::HTTP_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($raw) || ($code !== 200 && $code !== 409)) {
                return null;
            }
            $data = json_decode($raw, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ── validate / register / release ────────────────────────────────────

    /**
     * Validate the (stored or supplied) key against the server.
     * Updates + stores the verdict. Never throws; unreachable → grace verdict.
     */
    public static function validateNow(?string $key = null, ?string $domain = null): array
    {
        $now = time();
        $prev = self::readVerdict();

        if (!self::isEnabled()) {
            $v = self::defaultVerdict('unlicensed', 'no_server_configured');
            $v['detail'] = 'No license server configured — the app is fully functional without a license.';
            self::storeVerdict($v);
            return $v;
        }

        $key = $key !== null ? self::normalizeKey($key) : self::normalizeKey((string)(Database::getSetting('license_key', '') ?? ''));
        $domain = $domain !== null ? self::normalizeDomain($domain) : ($prev['domain'] !== '' ? $prev['domain'] : self::currentDomain());

        if ($key === '') {
            $v = self::defaultVerdict('unlicensed', 'no_key');
            $v['detail'] = 'No license key entered — the app is fully functional without one.';
            $v['domain'] = $domain;
            self::storeVerdict($v);
            return $v;
        }

        $res = self::httpPost('validate.php', ['key' => $key, 'domain' => $domain]);

        if ($res === null) {
            // Unreachable: keep working. Grace runs from the last SUCCESSFUL
            // check (or now, if there never was one).
            $lastOk = is_int($prev['last_ok_at']) ? $prev['last_ok_at'] : $now;
            $v = self::defaultVerdict('unreachable', 'server_unreachable');
            $v['detail'] = 'Could not reach the license server — everything keeps working. Will retry automatically.';
            $v['checked_at'] = $now;
            $v['last_ok_at'] = is_int($prev['last_ok_at']) ? $prev['last_ok_at'] : null;
            $v['grace_until'] = $lastOk + self::GRACE_SECONDS;
            $v['domain'] = $domain;
            $v['domains'] = $prev['domains'];
            $v['max_domains'] = $prev['max_domains'];
            $v['key_fp'] = hash('sha256', $key);
            self::storeVerdict($v);
            return $v;
        }

        $reason = (string)($res['reason'] ?? 'unknown');
        if (!empty($res['valid'])) {
            $v = self::defaultVerdict('valid', 'ok');
            $v['detail'] = 'License valid.';
            $v['checked_at'] = $now;
            $v['last_ok_at'] = $now;
            $v['grace_until'] = $now + self::GRACE_SECONDS;
        } elseif ($reason === 'revoked') {
            $v = self::defaultVerdict('revoked', 'revoked');
            $v['detail'] = 'This license key has been revoked. Sending is paused; everything else keeps working. Contact support if you believe this is a mistake.';
            $v['checked_at'] = $now;
            $v['last_ok_at'] = is_int($prev['last_ok_at']) ? $prev['last_ok_at'] : null;
        } else {
            // unknown_key / domain_not_registered / anything else: soft.
            // A mistyped key must never punish the buyer.
            $v = self::defaultVerdict('invalid', $reason);
            $v['detail'] = self::invalidDetail($reason, $res);
            $v['checked_at'] = $now;
            $v['last_ok_at'] = is_int($prev['last_ok_at']) ? $prev['last_ok_at'] : null;
            $v['grace_until'] = (is_int($prev['last_ok_at']) ? $prev['last_ok_at'] : $now) + self::GRACE_SECONDS;
        }
        $v['domain'] = $domain;
        $v['domains'] = $res['domains'] ?? $prev['domains'];
        $v['max_domains'] = isset($res['max_domains']) ? (int)$res['max_domains'] : $prev['max_domains'];
        $v['key_fp'] = hash('sha256', $key);
        self::storeVerdict($v);
        return $v;
    }

    private static function invalidDetail(string $reason, array $res): string
    {
        switch ($reason) {
            case 'unknown_key':
                return 'That key was not recognized — check for typos. The app keeps working normally.';
            case 'domain_not_registered':
                $n = (int)($res['max_domains'] ?? 0);
                return 'This key is fine, but this domain has not claimed a license slot' .
                    ($n > 0 ? " ({$n} slot(s) on this key)." : '.') .
                    ' Register it from Settings → License. The app keeps working normally.';
            case 'domain_slots_exhausted':
                return 'All domain slots on this key are in use. Release an old domain from Settings → License (self-service, no support ticket needed). The app keeps working normally.';
            default:
                return 'License check did not pass (' . $reason . '). The app keeps working normally.';
        }
    }

    /**
     * Register the current domain against a key (install / "Activate" flow).
     * On success the key is stored. Never throws; unreachable → grace verdict
     * and the key is still stored so the next check can complete it.
     */
    public static function registerNow(string $key, ?string $domain = null): array
    {
        $now = time();
        $prev = self::readVerdict();
        $key = self::normalizeKey($key);
        $domain = $domain !== null ? self::normalizeDomain($domain) : self::currentDomain();

        if (!self::isEnabled() || $key === '') {
            return self::validateNow($key === '' ? null : $key, $domain);
        }

        $res = self::httpPost('register.php', ['key' => $key, 'domain' => $domain]);

        if ($res === null) {
            // Unreachable at install: store the key anyway (soft!), grace verdict.
            self::storeKey($key);
            $v = self::defaultVerdict('unreachable', 'server_unreachable');
            $v['detail'] = 'License server unreachable during setup — install continues normally and the license will validate automatically later.';
            $v['checked_at'] = $now;
            $v['grace_until'] = $now + self::GRACE_SECONDS;
            $v['domain'] = $domain;
            $v['key_fp'] = hash('sha256', $key);
            self::storeVerdict($v);
            return $v;
        }

        $reason = (string)($res['reason'] ?? 'unknown');
        if (!empty($res['valid'])) {
            self::storeKey($key);
            $v = self::defaultVerdict('valid', $reason === 'already_registered' ? 'already_registered' : 'registered');
            $v['detail'] = 'License activated for this domain.';
            $v['checked_at'] = $now;
            $v['last_ok_at'] = $now;
            $v['grace_until'] = $now + self::GRACE_SECONDS;
        } elseif ($reason === 'revoked') {
            $v = self::defaultVerdict('revoked', 'revoked');
            $v['detail'] = 'This license key has been revoked.';
            $v['checked_at'] = $now;
        } else {
            // unknown_key / domain_slots_exhausted: soft — do NOT store the key.
            $v = self::defaultVerdict('invalid', $reason);
            $v['detail'] = self::invalidDetail($reason, $res);
            $v['checked_at'] = $now;
            $v['grace_until'] = (is_int($prev['last_ok_at']) ? $prev['last_ok_at'] : $now) + self::GRACE_SECONDS;
        }
        $v['domain'] = $domain;
        $v['domains'] = $res['domains'] ?? $prev['domains'];
        $v['max_domains'] = isset($res['max_domains']) ? (int)$res['max_domains'] : $prev['max_domains'];
        $v['key_fp'] = hash('sha256', $key);
        self::storeVerdict($v);
        return $v;
    }

    /** Buyer self-service: free this install's domain slot on the server. */
    public static function releaseDomainNow(?string $domain = null): array
    {
        $key = self::normalizeKey((string)(Database::getSetting('license_key', '') ?? ''));
        $prev = self::readVerdict();
        $domain = $domain !== null ? self::normalizeDomain($domain) : ($prev['domain'] !== '' ? $prev['domain'] : self::currentDomain());

        if (!self::isEnabled() || $key === '') {
            return ['ok' => false, 'message' => 'No license server or key configured.'];
        }
        $res = self::httpPost('release.php', ['key' => $key, 'domain' => $domain]);
        if ($res === null) {
            return ['ok' => false, 'message' => 'Could not reach the license server. Try again later — nothing was changed.'];
        }
        // Refresh the verdict so the UI reflects the freed slot.
        self::validateNow($key, $domain);
        return ['ok' => true, 'message' => 'Domain slot released. You can now register this key on another domain.'];
    }

    private static function storeKey(string $key): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (\'license_key\', ?) ' .
            'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        $stmt->execute([$key]);
    }

    /**
     * Daily re-validation, called from cron/process_queue.php.
     * Runs at most once per RECHECK_SECONDS. Never throws, never blocks.
     */
    public static function dailyCheck(): void
    {
        try {
            if (!self::isEnabled() || !self::hasKey()) {
                return;
            }
            $v = self::readVerdict();
            // Skip when a verdict was checked recently — but ALWAYS recheck a
            // revoked key sooner (every 6h) so un-revoking restores sending
            // without the buyer waiting a full day.
            $interval = ($v['status'] === 'revoked') ? 21600 : self::RECHECK_SECONDS;
            if (is_int($v['checked_at']) && $v['checked_at'] > 0 && (time() - $v['checked_at']) < $interval) {
                return;
            }
            self::validateNow();
        } catch (\Throwable $e) {
            // The queue must never die because of licensing.
            error_log('[Licensing] dailyCheck failed (queue continues): ' . $e->getMessage());
        }
    }

    // ── behavior matrix ──────────────────────────────────────────────────

    /**
     * Pure decision function: does this verdict allow sending?
     * ONLY 'revoked' blocks. Everything else — valid, invalid, unreachable,
     * unlicensed, unknown, tampered-cache — allows. This is the soft lock.
     */
    public static function decideSending(array $verdict): bool
    {
        return ($verdict['status'] ?? 'unknown') !== 'revoked';
    }

    /** Send-gate entry point. True unless the cached verdict is 'revoked'. */
    public static function sendingAllowed(): bool
    {
        try {
            return self::decideSending(self::readVerdict());
        } catch (\Throwable $e) {
            return true; // fail open — licensing must never break sending by accident
        }
    }

    /** True when the unreachable grace period has expired (escalate the notice). */
    public static function graceExpired(array $verdict, ?int $now = null): bool
    {
        $now = $now ?? time();
        return ($verdict['status'] ?? '') === 'unreachable'
            && is_int($verdict['grace_until'])
            && $now > $verdict['grace_until'];
    }

    /**
     * Everything the License settings section needs. Never throws.
     */
    public static function statusForUi(): array
    {
        try {
            $v = self::readVerdict();
            if (!self::isEnabled()) {
                return [
                    'status' => 'unlicensed',
                    'label' => 'Unlicensed',
                    'detail' => 'No license server configured. The app is fully functional.',
                    'key_set' => false, 'domain' => '', 'domains' => [],
                    'max_domains' => 0, 'checked_at' => null,
                    'grace_expired' => false, 'sending_allowed' => true,
                ];
            }
            $labels = [
                'valid' => 'Licensed', 'invalid' => 'Key issue',
                'revoked' => 'Revoked', 'unreachable' => 'Server unreachable',
                'unlicensed' => 'Unlicensed', 'unknown' => 'Unknown',
            ];
            return [
                'status' => $v['status'],
                'label' => $labels[$v['status']] ?? 'Unknown',
                'detail' => $v['detail'] !== '' ? $v['detail'] : $v['reason'],
                'key_set' => self::hasKey(),
                'domain' => $v['domain'],
                'domains' => $v['domains'],
                'max_domains' => (int)$v['max_domains'],
                'checked_at' => !empty($v['checked_at']) ? (int)$v['checked_at'] : null,
                'grace_expired' => self::graceExpired($v),
                'sending_allowed' => self::decideSending($v),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unknown', 'label' => 'Unknown',
                'detail' => 'Could not read license status.',
                'key_set' => false, 'domain' => '', 'domains' => [],
                'max_domains' => 0, 'checked_at' => null,
                'grace_expired' => false, 'sending_allowed' => true,
            ];
        }
    }
}
