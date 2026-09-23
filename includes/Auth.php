<?php

declare(strict_types=1);

namespace App;

/**
 * Auth — session-based authentication guard for the dashboard and API.
 *
 * Shared-hosting friendly: no extensions beyond stock PHP, no database
 * dependency (credentials live in config/auth.php, created by setup.php).
 *
 * Usage:
 *   \App\Auth::requirePageAuth();  // top of index.php / pages → redirects to login.php
 *   \App\Auth::requireApiAuth();    // top of api/*.php → 401 JSON when not logged in
 */
class Auth
{
    private const CONFIG_DIR  = __DIR__ . '/../config';
    private const AUTH_FILE   = __DIR__ . '/../config/auth.php';
    private const THROTTLE_FILE = __DIR__ . '/../config/throttle.json';

    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 900; // 15 minutes
    private const SESSION_LIFETIME = 7200; // 2 hours idle

    // ------------------------------------------------------------------
    // Session
    // ------------------------------------------------------------------

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Idle timeout
        if (isset($_SESSION['auth_last_activity'])
            && (time() - (int)$_SESSION['auth_last_activity'] > self::SESSION_LIFETIME)) {
            self::logout();
            return;
        }
        $_SESSION['auth_last_activity'] = time();
    }

    // ------------------------------------------------------------------
    // Credential storage
    // ------------------------------------------------------------------

    public static function isSetup(): bool
    {
        return is_file(self::AUTH_FILE);
    }

    /** @return array{username:string,hash:string,cron_token:string,created_at:string}|null */
    public static function loadCredentials(): ?array
    {
        if (!self::isSetup()) {
            return null;
        }
        $data = include self::AUTH_FILE;
        if (!is_array($data) || empty($data['username']) || empty($data['hash'])) {
            return null;
        }
        return $data;
    }

    public static function saveCredentials(string $username, string $password): void
    {
        if (!is_dir(self::CONFIG_DIR)) {
            mkdir(self::CONFIG_DIR, 0750, true);
        }
        // Deny web access to the config dir on Apache hosts.
        $htaccess = self::CONFIG_DIR . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }

        // Preserve the existing cron token across re-saves (e.g. password
        // rehash); only generate one when none exists yet.
        $existing = self::loadCredentials();
        $cronToken = $existing['cron_token'] ?? bin2hex(random_bytes(32));

        $payload = "<?php\n"
            . "// Smarketer Pro auth config — do not edit by hand. Created " . date('c') . ".\n"
            . "// Accessing this file over HTTP returns a blank page.\n"
            . "return " . var_export([
                'username'   => $username,
                'hash'       => password_hash($password, PASSWORD_DEFAULT),
                'cron_token' => $cronToken,
                'created_at' => date('c'),
            ], true) . ";\n";

        $tmp = self::AUTH_FILE . '.tmp';
        file_put_contents($tmp, $payload, LOCK_EX);
        @chmod($tmp, 0640);
        rename($tmp, self::AUTH_FILE);
    }

    public static function getCronToken(): ?string
    {
        $creds = self::loadCredentials();
        return $creds['cron_token'] ?? null;
    }

    // ------------------------------------------------------------------
    // Login / logout
    // ------------------------------------------------------------------

    public static function isLoggedIn(): bool
    {
        self::startSession();
        return !empty($_SESSION['auth_user']) && !empty($_SESSION['auth_ok']);
    }

    public static function attemptLogin(string $username, string $password): bool
    {
        self::startSession();
        $ip = self::clientIp();

        if (self::isLockedOut($ip)) {
            return false;
        }

        $creds = self::loadCredentials();
        $ok = $creds
            && hash_equals($creds['username'], $username)
            && password_verify($password, $creds['hash']);

        if ($ok) {
            self::clearAttempts($ip);
            session_regenerate_id(true);
            $_SESSION['auth_ok'] = true;
            $_SESSION['auth_user'] = $creds['username'];
            $_SESSION['auth_last_activity'] = time();
            // Re-hash if the algorithm upgraded.
            if (password_needs_rehash($creds['hash'], PASSWORD_DEFAULT)) {
                self::saveCredentials($creds['username'], $password);
            }
            return true;
        }

        self::recordAttempt($ip);
        // Constant-ish time: always run a dummy verify so timing leaks less.
        password_verify(random_bytes(16), '$2y$10$' . str_repeat('0', 53));
        return false;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '',
                $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    /** For HTML pages: redirect to login.php when not authenticated. */
    public static function requirePageAuth(): void
    {
        if (!self::isSetup()) {
            header('Location: setup.php');
            exit;
        }
        if (!self::isLoggedIn()) {
            $next = urlencode($_SERVER['REQUEST_URI'] ?? 'index.php');
            header('Location: login.php?next=' . $next);
            exit;
        }
    }

    /** For api/*.php: emit 401 JSON when not authenticated. */
    public static function requireApiAuth(): void
    {
        if (!self::isSetup() || !self::isLoggedIn()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Authentication required']);
            exit;
        }
    }

    /**
     * Validate a post-login redirect target. Only allows relative paths
     * inside this app — never absolute URLs or protocol-relative URLs.
     */
    public static function safeNext(?string $next, string $default = 'index.php'): string
    {
        if (!is_string($next) || $next === '') {
            return $default;
        }
        // Reject absolute URLs, protocol-relative URLs, backslashes, and
        // anything trying to escape with .. or null bytes.
        if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|\\\\)#i', $next)) {
            return $default;
        }
        if (strpos($next, '..') !== false || strpos($next, "\0") !== false) {
            return $default;
        }
        // Must look like a plain relative path: index.php, api/x.php, foo/bar.php
        if (!preg_match('#^[a-zA-Z0-9._/-]+$#', $next)) {
            return $default;
        }
        return $next;
    }

    // ------------------------------------------------------------------
    // CSRF
    // ------------------------------------------------------------------
    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateCsrf(?string $token): bool
    {
        self::startSession();
        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Enforce CSRF on a state-changing request. Accepts the token via the
     * X-CSRF-Token header (preferred for fetch/JSON APIs) or a `csrf_token`
     * body field. Fails closed with 403 JSON.
     *
     * Primary CSRF defense remains the SameSite=Lax session cookie (see
     * startSession()); this is defense-in-depth for sensitive endpoints.
     */
    public static function requireCsrf(): void
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if ($token === null && isset($_POST['csrf_token'])) {
            $token = $_POST['csrf_token'];
        }
        if (!self::validateCsrf(is_string($token) ? $token : null)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Invalid or missing CSRF token']);
            exit;
        }
    }

    // ------------------------------------------------------------------
    // Login throttling (file-based, no DB needed)
    // ------------------------------------------------------------------

    private static function clientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    /** @return array<string, array{count:int, first:int, locked_until:int}> */
    private static function loadThrottle(): array
    {
        if (!is_file(self::THROTTLE_FILE)) {
            return [];
        }
        $raw = @file_get_contents(self::THROTTLE_FILE);
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }

    private static function saveThrottle(array $data): void
    {
        if (!is_dir(self::CONFIG_DIR)) {
            mkdir(self::CONFIG_DIR, 0750, true);
        }
        file_put_contents(self::THROTTLE_FILE, json_encode($data), LOCK_EX);
    }

    private static function isLockedOut(string $ip): bool
    {
        $data = self::loadThrottle();
        $entry = $data[$ip] ?? null;
        if (!$entry) {
            return false;
        }
        if ($entry['locked_until'] > time()) {
            return true;
        }
        // Lock expired — reset if the window passed.
        if (time() - $entry['first'] > self::LOCKOUT_SECONDS) {
            unset($data[$ip]);
            self::saveThrottle($data);
        }
        return false;
    }

    private static function recordAttempt(string $ip): void
    {
        $data = self::loadThrottle();
        $now = time();
        $entry = $data[$ip] ?? ['count' => 0, 'first' => $now, 'locked_until' => 0];
        if ($now - $entry['first'] > self::LOCKOUT_SECONDS) {
            $entry = ['count' => 0, 'first' => $now, 'locked_until' => 0];
        }
        $entry['count']++;
        if ($entry['count'] >= self::MAX_ATTEMPTS) {
            $entry['locked_until'] = $now + self::LOCKOUT_SECONDS;
        }
        $data[$ip] = $entry;
        self::saveThrottle($data);
    }

    private static function clearAttempts(string $ip): void
    {
        $data = self::loadThrottle();
        unset($data[$ip]);
        self::saveThrottle($data);
    }
}
