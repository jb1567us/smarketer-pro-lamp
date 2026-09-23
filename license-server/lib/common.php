<?php
/**
 * License server — shared bootstrap (NOT part of the main app; no App\ deps).
 *
 * Provides: json_response(), read_json_body(), db(), client_ip().
 */
declare(strict_types=1);

// PDO polyfill for shared hosts without the pdo_mysql extension.
require_once __DIR__ . '/pdo_shim.php';

function ls_json_response(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    header('Content-Type: application/json');
    // Never cache license decisions at the HTTP layer.
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

function ls_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function ls_db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    // Mode 1 — standalone: local config.php with DB credentials.
    $cfgFile = dirname(__DIR__) . '/config.php';
    if (is_file($cfgFile)) {
        $cfg = require $cfgFile;
        $ok = is_array($cfg);
        foreach (['host', 'name', 'user', 'pass'] as $k) {
            if (!isset($cfg[$k])) { $ok = false; }
        }
        if ($ok) {
            $pdo = new PDO(
                "mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4",
                $cfg['user'],
                $cfg['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            return $pdo;
        }
    }
    // Mode 2 — co-hosted: borrow the sibling app's DB layer (same machine,
    // same database; credentials never duplicated). No return-type hint:
    // the app exposes its mysqli-backed App\\PDO shim, which is
    // duck-type compatible with the global PDO polyfill above.
    $appBase = dirname(dirname(__DIR__)) . '/b2b_outreach_lamp';
    if (is_file($appBase . '/includes/autoload.php')) {
        require_once $appBase . '/includes/autoload.php';
    }
    if (is_file($appBase . '/includes/Database.php')) {
        require_once $appBase . '/includes/Database.php';
        $pdo = \App\Database::getConnection();
        return $pdo;
    }
    ls_json_response(['valid' => false, 'reason' => 'server_misconfigured'], 500);
}

/** Best-effort client IP (direct connections on shared hosting; no trusted-proxy chain). */
function ls_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr((string)$ip, 0, 45);
}

/**
 * Normalize a domain for licensing comparisons.
 *
 * Rules (documented — the client implements the identical rules):
 *   - lowercase, trim
 *   - strip scheme (http://, https://), userinfo, path, query, fragment, port
 *   - strip trailing dot
 *   - strip ONE leading "www."  →  www.example.com and example.com share a slot
 */
function ls_normalize_domain(string $input): string
{
    $d = strtolower(trim($input));
    // Strip scheme.
    $d = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $d);
    // Strip userinfo.
    if (strpos($d, '@') !== false) {
        $d = substr($d, (int)strrpos($d, '@') + 1);
    }
    // Strip path / query / fragment.
    // Strip path / query / fragment (first token before /, ?, or #).
    $d = (string)strtok($d, '/?#');
    // Strip port.
    $d = preg_replace('#:\d+$#', '', $d);
    $d = rtrim(trim($d), '.');
    // www. folds into the bare domain (documented rule).
    if (str_starts_with($d, 'www.')) {
        $d = substr($d, 4);
    }
    return $d;
}

/** Normalize a license key: trim + uppercase (dashes kept as issued). */
function ls_normalize_key(string $key): string
{
    return strtoupper(trim($key));
}

/** Deterministic lookup fingerprint: sha256 of the normalized key. */
function ls_key_fingerprint(string $normalizedKey): string
{
    return hash('sha256', $normalizedKey);
}

/**
 * Generate a new license key: SMP-XXXX-XXXX-XXXX.
 *
 * 12 characters from an unambiguous 32-char alphabet (no 0/O, 1/I/L) =
 * 60 bits of entropy. The plaintext is shown ONCE at issuance and never
 * stored; only the fingerprint + password_hash live in the database.
 */
function ls_generate_key(): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $bytes = random_bytes(12);
    $chars = '';
    for ($i = 0; $i < 12; $i++) {
        $chars .= $alphabet[ord($bytes[$i]) & 31];
    }
    return 'SMP-' . substr($chars, 0, 4) . '-' . substr($chars, 4, 4) . '-' . substr($chars, 8, 4);
}

/**
 * Look up a license row by plaintext key. Returns the row array or null.
 * Deliberately does not distinguish "unknown key" from "wrong key".
 */
function ls_find_license($pdo, string $key): ?array
{
    $norm = ls_normalize_key($key);
    if ($norm === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM licenses WHERE key_fp = ? LIMIT 1');
    $stmt->execute([ls_key_fingerprint($norm)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (!password_verify($norm, $row['key_hash'])) {
        return null;
    }
    return $row;
}

function ls_license_domains($pdo, int $licenseId): array
{
    $stmt = $pdo->prepare('SELECT domain FROM license_domains WHERE license_id = ? ORDER BY domain');
    $stmt->execute([$licenseId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Simple per-IP rate limit backed by the rate_limits table.
 * Emits a 429 JSON response when exceeded; otherwise returns silently.
 */
function ls_rate_limit($pdo, string $ip, int $maxHits = 60, int $windowSeconds = 60): void
{
    $now = time();
    $windowStart = (int)floor($now / $windowSeconds) * $windowSeconds;
    $useTx = method_exists($pdo, 'beginTransaction');
    if ($useTx) { $pdo->beginTransaction(); }
    try {
        $stmt = $pdo->prepare('SELECT window_start, hits FROM rate_limits WHERE ip = ? FOR UPDATE');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['window_start'] !== $windowStart) {
            $stmt = $pdo->prepare(
                'INSERT INTO rate_limits (ip, window_start, hits) VALUES (?, ?, 1) ' .
                'ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), hits = 1'
            );
            $stmt->execute([$ip]);
        } else {
            $hits = (int)$row['hits'] + 1;
            $stmt = $pdo->prepare('UPDATE rate_limits SET hits = ? WHERE ip = ?');
            $stmt->execute([$hits, $ip]);
            if ($hits > $maxHits) {
                if ($useTx) { $pdo->commit(); }
                ls_json_response(['valid' => false, 'reason' => 'rate_limited', 'retry_after' => $windowStart + $windowSeconds - $now], 429);
            }
        }
        if ($useTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($useTx && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // Rate limiting must never take the API down: fail open on limiter errors.
    }
}
