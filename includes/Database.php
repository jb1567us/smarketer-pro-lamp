<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\OutreachException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Resolve database credentials. Order:
     *   1. config/db.php — the shipped loader, which reads (in order) the
     *      DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables
     *      (getenv(), then $_SERVER, then $_ENV for SAPI variance), then the
     *      gitignored server-side config/.env file, and FAILS CLOSED naming
     *      any missing variable. The environment always wins.
     *   2. A complete legacy ['host','name','user','pass'] array returned by
     *      config/db.php (installer-written on existing deploys, or the old
     *      test-harness file-swap pattern) is honoured as-is for backwards
     *      compatibility.
     *   3. If config/db.php is absent (never in a shipped tree), resolve from
     *      the environment directly (same four variables + config/.env).
     *   4. Fail closed with an actionable message (never a password literal).
     */
    private static function credentials(): array
    {
        $file = dirname(__DIR__) . '/config/db.php';
        if (is_file($file)) {
            try {
                $cfg = require $file;
            } catch (\Throwable $e) {
                // The loader fails closed with a precise missing-variable
                // message; surface it as the app's config exception type.
                throw new OutreachException($e->getMessage(), 0, $e);
            }
            if (!is_array($cfg)) {
                throw new OutreachException(
                    'Database configuration file config/db.php did not return a credentials array.'
                );
            }
            $missing = [];
            foreach (['host', 'name', 'user', 'pass'] as $k) {
                if (!isset($cfg[$k]) || (string)$cfg[$k] === '') {
                    $missing[] = $k;
                }
            }
            if ($missing !== []) {
                throw new OutreachException(
                    'Database configuration file config/db.php is incomplete (missing: ' .
                    implode(', ', $missing) . '). Re-run install.php, or set the DB_HOST / ' .
                    'DB_NAME / DB_USER / DB_PASS environment variables.'
                );
            }
            return [(string)$cfg['host'], (string)$cfg['name'], (string)$cfg['user'], (string)$cfg['pass']];
        }

        // No config file: environment-only resolution (mirrors config/db.php).
        $envVal = static function (string $name): ?string {
            $v = getenv($name);
            if (is_string($v) && $v !== '') {
                return $v;
            }
            foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $candidate) {
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
            return null;
        };
        $vals = [
            'DB_HOST' => $envVal('DB_HOST'),
            'DB_NAME' => $envVal('DB_NAME'),
            'DB_USER' => $envVal('DB_USER'),
            'DB_PASS' => $envVal('DB_PASS'),
        ];
        // config/.env fill (gitignored; silently skipped when absent).
        if (in_array(null, $vals, true) || in_array('', $vals, true)) {
            $dotEnv = dirname(__DIR__) . '/config/.env';
            if (is_file($dotEnv) && is_readable($dotEnv)) {
                foreach (file($dotEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                        continue;
                    }
                    $eq = strpos($line, '=');
                    if ($eq === false) {
                        continue;
                    }
                    $k = trim(substr($line, 0, $eq));
                    if (!array_key_exists($k, $vals)) {
                        continue;
                    }
                    $v = trim(substr($line, $eq + 1));
                    if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[0] === $v[strlen($v) - 1]) {
                        $v = substr($v, 1, -1);
                    }
                    if (($vals[$k] === null || $vals[$k] === '') && $v !== '') {
                        $vals[$k] = $v;
                    }
                }
            }
        }
        $missing = [];
        foreach ($vals as $var => $val) {
            if ($val === null || $val === '') {
                $missing[] = $var;
            }
        }
        if ($missing !== []) {
            throw new OutreachException(
                'Database is not configured. Missing: ' . implode(', ', $missing) . '. ' .
                'Set the DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables, ' .
                'or add them to config/.env (gitignored, never committed).'
            );
        }
        return [$vals['DB_HOST'], $vals['DB_NAME'], $vals['DB_USER'], $vals['DB_PASS']];
    }

    public static function getConnection(): \App\PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        [$host, $dbname, $username, $password] = self::credentials();
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
        $options = [
            \App\PDO::ATTR_ERRMODE            => \App\PDO::ERRMODE_EXCEPTION,
            \App\PDO::ATTR_DEFAULT_FETCH_MODE => \App\PDO::FETCH_ASSOC,
            \App\PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        $maxRetries = 5;
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            try {
                self::$instance = new PDO($dsn, $username, $password, $options);
                return self::$instance;
            } catch (\App\PDOException $e) {
                $retryCount++;
                if ($retryCount >= $maxRetries) {
                    throw new OutreachException("Database connection failed after {$maxRetries} attempts: {$e->getMessage()}", 0, $e);
                }
                sleep(2);
            }
        }

        throw new OutreachException("Unexpected database connection failure.");
    }

    public static function getSetting(string $key, ?string $default = null): ?string
    {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetch();
            $val = $result ? $result['setting_value'] : $default;
            if (!$val) {
                error_log("[Database] Key '$key' not found or empty. Returning default.");
            }
            return $val;
        } catch (\Exception $e) {
            error_log("[Database] Error fetching setting '$key': " . $e->getMessage());
            return $default;
        }
    }

    /**
     * Upsert a settings row. Same INSERT ... ON DUPLICATE KEY UPDATE pattern
     * as api/settings.php; used by the cron worker to record its last tick.
     * Never throws.
     */
    public static function setSetting(string $key, string $value): bool
    {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ' .
                'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            return $stmt->execute([$key, $value]);
        } catch (\Exception $e) {
            error_log("[Database] Error saving setting '$key': " . $e->getMessage());
            return false;
        }
    }
}
