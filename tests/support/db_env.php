<?php
/**
 * Shared scratch-DB credential helper for DB-backed test suites.
 *
 * Since config/db.php now resolves credentials environment-first, test
 * suites no longer rewrite config/db.php to point at a scratch database.
 * Instead they publish the four DB_* variables into the current process
 * environment (putenv + $_ENV + $_SERVER) and reset Database's memoized
 * singleton so the next getConnection() call picks them up.
 *
 * Benefits over the old file-swap pattern:
 *   - the repo tree is never touched (no backup/restore races between suites),
 *   - child processes (php -S servers, mysql CLI wrappers) inherit the real
 *     environment automatically,
 *   - a missed restore cannot leave a stale config file behind (nothing is
 *     written to disk).
 *
 * Usage (inside the test file's try block, where the file-swap used to be):
 *
 *   require_once __DIR__ . '/../support/db_env.php';   // path varies by suite
 *   test_db_use_env('127.0.0.1', $dbName, $dbUser, $dbPass);
 *   ...
 *   } finally {
 *       test_db_restore_env();
 *   }
 *
 * Prior values are captured on first use and restored afterwards, so a suite
 * never leaks its scratch credentials into a later suite in the same process.
 */
declare(strict_types=1);

function test_db_use_env(string $host, string $name, string $user, string $pass): void
{
    if (!class_exists(\App\Database::class)) {
        require_once dirname(__DIR__, 2) . '/includes/autoload.php';
    }
    if (!array_key_exists('__test_db_prior_env', $GLOBALS)) {
        $GLOBALS['__test_db_prior_env'] = [];
    }
    foreach (['DB_HOST' => $host, 'DB_NAME' => $name, 'DB_USER' => $user, 'DB_PASS' => $pass] as $k => $v) {
        if (!array_key_exists($k, $GLOBALS['__test_db_prior_env'])) {
            $prev = getenv($k);
            $GLOBALS['__test_db_prior_env'][$k] = $prev === false ? null : $prev;
        }
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
    test_db_reset_singleton();
}

function test_db_restore_env(): void
{
    foreach ($GLOBALS['__test_db_prior_env'] ?? [] as $k => $prev) {
        if ($prev === null) {
            putenv($k); // no '=' removes the variable from the environment
            unset($_ENV[$k], $_SERVER[$k]);
        } else {
            putenv("{$k}={$prev}");
            $_ENV[$k] = $prev;
            $_SERVER[$k] = $prev;
        }
    }
    $GLOBALS['__test_db_prior_env'] = [];
    test_db_reset_singleton();
}

/** Reset Database's memoized PDO singleton (replaces the old reflection blocks). */
function test_db_reset_singleton(): void
{
    if (!class_exists(\App\Database::class)) {
        return;
    }
    $ref = new \ReflectionProperty(\App\Database::class, 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
}
