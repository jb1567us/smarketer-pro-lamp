<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\OutreachException;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): \App\PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = getenv('DB_HOST') ?: 'db';
        $dbname = getenv('DB_NAME') ?: 'b2b_outreach';
        $username = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASS') ?: 'rootpassword';
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
            } catch (PDOException $e) {
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
