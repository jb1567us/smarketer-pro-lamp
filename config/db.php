<?php
/**
 * Database credentials — environment-first resolution.
 *
 * NO SECRET LITERALS ARE STORED IN THIS REPO. Credentials resolve at runtime
 * in this order:
 *
 *   1. Process environment: DB_HOST / DB_NAME / DB_USER / DB_PASS.
 *      Read via getenv() first, then $_SERVER, then $_ENV, so every common
 *      SAPI is covered (php-fpm / fastcgi often expose env vars only in
 *      $_SERVER, while some CGI builds populate only $_ENV). The environment
 *      ALWAYS wins over the sources below.
 *
 *   2. Server-side env file: config/.env (same four KEY=VALUE lines).
 *      This file is OUTSIDE version control (.gitignore) and is silently
 *      skipped when absent. It exists for shared hosting (cPanel) where true
 *      process environment variables cannot be set: upload a config/.env
 *      with the four values (chmod 0600) instead of editing this file.
 *      NEVER commit config/.env — see config/.env.example for the format.
 *
 *   3. FAIL CLOSED: if any of the four values is still missing or empty, this
 *      file throws, naming the missing variable(s). The app never attempts a
 *      connection with empty/default credentials and never falls back to
 *      hardcoded placeholder credentials.
 *
 * Legacy note: includes/Database.php still honours an existing
 * installer-written config/db.php that returns a COMPLETE
 * ['host','name','user','pass'] array (backwards compatibility for deployed
 * installs). New installs should use environment variables or config/.env.
 */
declare(strict_types=1);

$dbEnvValue = static function (string $name): ?string {
    $fromGetenv = getenv($name);
    if (is_string($fromGetenv) && $fromGetenv !== '') {
        return $fromGetenv;
    }
    // SAPI variance: FPM/fastcgi commonly surface env vars in $_SERVER only,
    // some CGI builds only in $_ENV.
    foreach ([$_SERVER[$name] ?? null, $_ENV[$name] ?? null] as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }
    return null;
};

$dbHost = $dbEnvValue('DB_HOST');
$dbName = $dbEnvValue('DB_NAME');
$dbUser = $dbEnvValue('DB_USER');
$dbPass = $dbEnvValue('DB_PASS');

// Second source: config/.env (gitignored; silently skipped when absent).
// Fills only the keys the environment did not provide — env always wins.
if ($dbHost === null || $dbName === null || $dbUser === null || $dbPass === null) {
    $dbDotEnvFile = __DIR__ . '/.env';
    if (is_file($dbDotEnvFile) && is_readable($dbDotEnvFile)) {
        $dbDotEnvVars = [];
        foreach (file($dbDotEnvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $dbDotEnvLine) {
            $dbDotEnvLine = trim($dbDotEnvLine);
            if ($dbDotEnvLine === '' || $dbDotEnvLine[0] === '#' || $dbDotEnvLine[0] === ';') {
                continue;
            }
            $dbEq = strpos($dbDotEnvLine, '=');
            if ($dbEq === false) {
                continue;
            }
            $dbDotEnvKey = trim(substr($dbDotEnvLine, 0, $dbEq));
            $dbDotEnvVal = trim(substr($dbDotEnvLine, $dbEq + 1));
            if (strlen($dbDotEnvVal) >= 2 && ($dbDotEnvVal[0] === '"' || $dbDotEnvVal[0] === "'")
                && $dbDotEnvVal[0] === $dbDotEnvVal[strlen($dbDotEnvVal) - 1]) {
                $dbDotEnvVal = substr($dbDotEnvVal, 1, -1);
            }
            $dbDotEnvVars[$dbDotEnvKey] = $dbDotEnvVal;
        }
        if ($dbHost === null && isset($dbDotEnvVars['DB_HOST']) && $dbDotEnvVars['DB_HOST'] !== '') {
            $dbHost = $dbDotEnvVars['DB_HOST'];
        }
        if ($dbName === null && isset($dbDotEnvVars['DB_NAME']) && $dbDotEnvVars['DB_NAME'] !== '') {
            $dbName = $dbDotEnvVars['DB_NAME'];
        }
        if ($dbUser === null && isset($dbDotEnvVars['DB_USER']) && $dbDotEnvVars['DB_USER'] !== '') {
            $dbUser = $dbDotEnvVars['DB_USER'];
        }
        if ($dbPass === null && isset($dbDotEnvVars['DB_PASS']) && $dbDotEnvVars['DB_PASS'] !== '') {
            $dbPass = $dbDotEnvVars['DB_PASS'];
        }
        unset($dbDotEnvVars);
    }
}

// Fail closed: name every missing variable. Never connect with empty creds,
// never fall back to hardcoded placeholder credentials.
$dbMissing = [];
foreach (['DB_HOST' => $dbHost, 'DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'DB_PASS' => $dbPass] as $dbVar => $dbVal) {
    if ($dbVal === null || $dbVal === '') {
        $dbMissing[] = $dbVar;
    }
}
if ($dbMissing !== []) {
    throw new \RuntimeException(
        'Database credentials are not configured. Missing: ' . implode(', ', $dbMissing) . '. ' .
        'Set the DB_HOST / DB_NAME / DB_USER / DB_PASS environment variables in your hosting ' .
        'environment, or add them to config/.env (gitignored, never committed — see ' .
        'config/.env.example). No database credentials are stored in the codebase.'
    );
}

$dbCreds = ['host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass];
// Keep this file's scope clean for whoever requires it.
unset($dbEnvValue, $dbHost, $dbName, $dbUser, $dbPass, $dbDotEnvFile, $dbDotEnvLine, $dbMissing, $dbVar, $dbVal);

return $dbCreds;
