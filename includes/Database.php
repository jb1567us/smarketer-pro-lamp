<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\OutreachException;

class Database
{
    private static ?PDO $instance = null;

    /**
     * Resolve database credentials. Order:
     *   1. config/db.php (written by install.php) — ['host','name','user','pass']
     *   2. DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables
     *   3. Fail closed with an actionable message (never a password literal).
     */
    private static function credentials(): array
    {
        $file = dirname(__DIR__) . '/config/db.php';
        if (is_file($file)) {
            $cfg = require $file;
            if (is_array($cfg)) {
                $host = (string)($cfg['host'] ?? '');
                $name = (string)($cfg['name'] ?? '');
                $user = (string)($cfg['user'] ?? '');
                $pass = (string)($cfg['pass'] ?? '');
                if ($host !== '' && $name !== '' && $user !== '' && $pass !== '') {
                    return [$host, $name, $user, $pass];
                }
            }
            throw new OutreachException(
                'Database configuration file config/db.php is present but incomplete. ' .
                'Re-run install.php or fix the file.'
            );
        }

        $host = getenv('DB_HOST') ?: 'localhost';
        $name = getenv('DB_NAME') ?: '';
        $user = getenv('DB_USER') ?: '';
        $pass = getenv('DB_PASS');

        // Fail closed: the database password must come from the config file
        // or the environment. Never commit a password literal here.
        if ($pass === false || $pass === '' || $name === '' || $user === '') {
            throw new OutreachException(
                'Database is not configured. Run install.php in your browser, ' .
                'or set the DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables.'
            );
        }
        return [$host, $name, $user, $pass];
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
}
