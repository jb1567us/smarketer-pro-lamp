<?php

declare(strict_types=1);

namespace App;

/**
 * ApiAuth — long-lived API-key auth for server-to-server automation
 * consumers (Phase 4 n8n reply-ingestion workflow).
 *
 * Session cookies do not work for a headless poller, so the ingestion seam
 * (api/ingest_reply.php) also accepts a per-install API key presented as
 *   X-Api-Key: <key>
 * or
 *   Authorization: Bearer <key>
 *
 * Key resolution order:
 *   1. SMARKETER_INGEST_API_KEY environment variable (preferred on hosts
 *      that support env config — never committed to git).
 *   2. Per-install key file config/ingest_api_key.php, generated once with
 *      random_bytes(32) on first need (mode 0640; config/.htaccess already
 *      denies web access — see Auth::saveCredentials()).
 *
 * The configured key is NEVER returned except to the file that stores it.
 * All comparisons are timing-safe (hash_equals). The pure helpers
 * (extractProvidedKey / keysMatch) take explicit arguments so they are
 * unit-testable without touching config.
 */
class ApiAuth
{
    /** Env var override for the ingest API key. */
    public const ENV_KEY = 'SMARKETER_INGEST_API_KEY';

    /** Raw body size ceiling enforced by api/ingest_reply.php (256 KiB). */
    public const MAX_BODY_BYTES = 262144;

    /** Rate limit: requests per IP per window on the ingestion seam. */
    public const RATE_LIMIT = 60;
    public const RATE_WINDOW_SECONDS = 60;

    /** Dedupe store cap: how many message digests are remembered. */
    public const DEDUPE_CAP = 500;

    /**
     * Resolve the configured ingest API key, or null when none is usable.
     * Generates and persists the per-install key on first call when the
     * config dir is writable and no env override exists.
     *
     * @param string|null $configDir override for tests (defaults to config/)
     */
    public static function ingestKey(?string $configDir = null): ?string
    {
        $env = getenv(self::ENV_KEY);
        if (is_string($env) && strlen($env) >= 16) {
            return $env;
        }

        $dir = $configDir ?? (__DIR__ . '/../config');
        $file = rtrim($dir, '/\\') . '/ingest_api_key.php';

        if (is_file($file)) {
            $key = @include $file;
            if (is_string($key) && strlen($key) >= 32) {
                return $key;
            }
        }

        // Generate once; fail closed (null → session-only auth) when the
        // config dir cannot be created/written.
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return null;
        }
        $htaccess = rtrim($dir, '/\\') . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }
        $key = bin2hex(random_bytes(32));
        $payload = "<?php\n"
            . "// Smarketer Pro ingest API key — do not edit by hand.\n"
            . "// Send as header 'X-Api-Key' or 'Authorization: Bearer'.\n"
            . "return " . var_export($key, true) . ";\n";
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            return null;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            return null;
        }
        return $key;
    }

    /**
     * True when the request carries a valid API key for the configured key.
     * Pure given its arguments (unit-testable).
     *
     * @param string|null $configuredKey the key from ingestKey(); null/'' → false
     * @param array       $server        $_SERVER-style array
     */
    public static function keyAuthValid(?string $configuredKey, array $server): bool
    {
        if (!is_string($configuredKey) || $configuredKey === '') {
            return false;
        }
        $provided = self::extractProvidedKey($server);
        if ($provided === '') {
            return false;
        }
        return hash_equals($configuredKey, $provided);
    }

    /**
     * Extract the presented API key from request headers.
     * Prefers X-Api-Key; falls back to Authorization: Bearer.
     *
     * @param array $server $_SERVER-style array
     */
    public static function extractProvidedKey(array $server): string
    {
        $h = $server['HTTP_X_API_KEY'] ?? '';
        if (is_string($h) && trim($h) !== '') {
            return trim($h);
        }
        $auth = $server['HTTP_AUTHORIZATION'] ?? '';
        if (is_string($auth) && stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return '';
    }

    // ------------------------------------------------------------------
    // File-based per-IP rate limiting (no DB needed).
    // ------------------------------------------------------------------

    /**
     * Sliding-window rate check. Returns ['allowed'=>bool,'retry_after'=>int].
     * State lives in <logsDir>/ingest_rate.json; best-effort (fail-open on
     * I/O errors so logging can never hard-block ingestion).
     *
     * @param string      $ip      client IP
     * @param string|null $logsDir override for tests (defaults to logs/)
     */
    public static function rateCheck(string $ip, ?string $logsDir = null): array
    {
        $dir = $logsDir ?? (__DIR__ . '/../logs');
        $file = rtrim($dir, '/\\') . '/ingest_rate.json';
        $now = time();
        $windowStart = $now - self::RATE_WINDOW_SECONDS;

        $data = [];
        $fp = null;
        if (is_dir($dir) || @mkdir($dir, 0750, true)) {
            $fp = @fopen($file, 'c+');
        }
        if ($fp === false || $fp === null) {
            return ['allowed' => true, 'retry_after' => 0]; // fail-open
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return ['allowed' => true, 'retry_after' => 0];
            }
            $raw = stream_get_contents($fp);
            $data = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            if (!is_array($data)) {
                $data = [];
            }

            $hits = array_values(array_filter(
                (array)($data[$ip] ?? []),
                static fn($t) => is_int($t) && $t > $windowStart
            ));

            if (count($hits) >= self::RATE_LIMIT) {
                $retryAfter = (int)(min($hits) + self::RATE_WINDOW_SECONDS - $now) + 1;
                return ['allowed' => false, 'retry_after' => max($retryAfter, 1)];
            }

            $hits[] = $now;
            $data[$ip] = $hits;
            // Prune other IPs' stale entries to bound file growth.
            foreach ($data as $k => $v) {
                $v = array_values(array_filter((array)$v, static fn($t) => is_int($t) && $t > $windowStart));
                if ($v === []) {
                    unset($data[$k]);
                } else {
                    $data[$k] = $v;
                }
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($data));
            return ['allowed' => true, 'retry_after' => 0];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    // ------------------------------------------------------------------
    // Idempotency: duplicate POSTs return the cached verdict.
    // ------------------------------------------------------------------

    /**
     * Build a dedupe key for one ingestion request. Prefers the email
     * Message-ID when supplied; otherwise hashes the content so an
     * identical accidental repost still dedupes.
     */
    public static function dedupeKey(string $messageId, string $subject, string $body, string $from): string
    {
        $messageId = trim($messageId);
        if ($messageId !== '') {
            // Normalize "<abc@x>" / "abc@x" forms; case-insensitive.
            $norm = strtolower(trim($messageId, " \t<>"));
            return 'mid:' . hash('sha256', $norm);
        }
        return 'hash:' . hash('sha256', $subject . "\n" . $body . "\n" . strtolower($from));
    }

    /**
     * Look up a previously stored verdict. Returns the cached verdict array
     * or null. Best-effort (fail-open → null on I/O errors).
     *
     * @param string|null $logsDir override for tests (defaults to logs/)
     */
    public static function dedupeGet(string $key, ?string $logsDir = null): ?array
    {
        $store = self::dedupeLoad($logsDir);
        $entry = $store[$key] ?? null;
        return (is_array($entry) && isset($entry['verdict']) && is_array($entry['verdict']))
            ? $entry['verdict']
            : null;
    }

    /**
     * Store a verdict for a dedupe key (bounded to DEDUPE_CAP entries,
     * oldest first). Never throws.
     *
     * @param string|null $logsDir override for tests (defaults to logs/)
     */
    public static function dedupePut(string $key, array $verdict, ?string $logsDir = null): void
    {
        $dir = $logsDir ?? (__DIR__ . '/../logs');
        $file = rtrim($dir, '/\\') . '/ingest_dedupe.json';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return;
        }
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return;
            }
            $raw = stream_get_contents($fp);
            $store = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
            if (!is_array($store)) {
                $store = [];
            }
            unset($store[$key]); // re-insert at the end (newest)
            $store[$key] = ['verdict' => $verdict, 'stored_at' => date('c')];
            while (count($store) > self::DEDUPE_CAP) {
                array_shift($store);
            }
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($store, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** @return array<string,array> */
    private static function dedupeLoad(?string $logsDir): array
    {
        $dir = $logsDir ?? (__DIR__ . '/../logs');
        $file = rtrim($dir, '/\\') . '/ingest_dedupe.json';
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        $store = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        return is_array($store) ? $store : [];
    }
}
