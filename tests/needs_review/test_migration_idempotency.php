#!/usr/bin/env php
<?php
/**
 * Migration idempotency: the sibling-1 ENUM migration (adds 'Needs Review'
 * to the lead qualification-status ENUM) must apply cleanly TWICE to a
 * scratch DB — the second apply is a clean no-op, existing rows keep their
 * statuses, and all pre-existing ENUM values survive.
 *
 * Setup mirrors a real pre-migration install: the scratch leads table is
 * created with the CURRENT production ENUM (no 'Needs Review'), seeded with
 * one row per existing status, then the migration is applied twice.
 *
 * If sibling 1's migration file has not landed yet, every check is SKIPPED
 * with an explicit PENDING reason — the suite stays green and honest.
 *
 * Usage: php tests/needs_review/test_migration_idempotency.php
 * The repo tree is left exactly as it was (config/db.php restored/deleted).
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require nr_repo_root() . '/includes/autoload.php';

$migFile = nr_schema_migration();
if ($migFile === null) {
    nr_skip('ENUM migration applies on a pre-migration schema', 'PENDING sibling 1 (schema): no *needs*review* migration in migrations/');
    nr_skip('ENUM migration re-applies cleanly (idempotent)', 'PENDING sibling 1 (schema)');
    nr_skip("leads.status ENUM contains 'Needs Review' after migration", 'PENDING sibling 1 (schema)');
    nr_skip('existing lead statuses unchanged by migration', 'PENDING sibling 1 (schema)');
    nr_skip('new lead can be inserted with Needs Review status', 'PENDING sibling 1 (schema)');
    echo "  NOTE: sibling 1 has not landed yet — rerun this file once its migration is committed.\n";
    exit(nr_summary('test_migration_idempotency.php'));
}

echo "  using migration: " . substr($migFile, strlen(nr_repo_root()) + 1) . "\n";

try {
    $pdo = nr_scratch_db('nr_enum_mig', 'nr_test', 'nr_test_pw_4x8');

    // Pre-migration production schema: the 7 current ENUM values.
    $pdo->exec("CREATE TABLE leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) UNIQUE NOT NULL,
        status ENUM('New', 'Enriched', 'Contacted', 'Qualified', 'Unqualified', 'Converted', 'Drafted') DEFAULT 'New',
        lead_score INT DEFAULT 0,
        notes TEXT
    ) ENGINE=InnoDB");
    $before = ['New', 'Enriched', 'Contacted', 'Qualified', 'Unqualified', 'Converted', 'Drafted'];
    foreach ($before as $i => $st) {
        $pdo->exec("INSERT INTO leads (company_name, email, status, lead_score) VALUES " .
            "('Co {$i}', 'pre{$i}@example.test', '{$st}', " . (10 * $i) . ")");
    }
    nr_check('pre-migration ENUM has no Needs Review',
        !str_contains(nr_status_enum($pdo), "'needs review'"));

    // --- First apply -----------------------------------------------------------
    try {
        nr_apply_migration($pdo, $migFile);
        nr_check('ENUM migration applies on a pre-migration schema', true);
    } catch (\Throwable $e) {
        nr_check('ENUM migration applies on a pre-migration schema', false, substr($e->getMessage(), 0, 160));
    }

    $enumAfter1 = nr_status_enum($pdo);
    nr_check("leads.status ENUM contains 'Needs Review' after migration",
        str_contains($enumAfter1, "'needs review'"));

    // --- Second apply: must be a clean no-op -----------------------------------
    try {
        nr_apply_migration($pdo, $migFile);
        nr_check('ENUM migration re-applies cleanly (idempotent)', true);
    } catch (\Throwable $e) {
        nr_check('ENUM migration re-applies cleanly (idempotent)', false, substr($e->getMessage(), 0, 160));
    }
    nr_check('ENUM unchanged after second apply', nr_status_enum($pdo) === $enumAfter1);

    // --- Data preservation -------------------------------------------------------
    $after = $pdo->query('SELECT status FROM leads ORDER BY id')->fetchAll(\App\PDO::FETCH_COLUMN);
    nr_check('existing lead statuses unchanged by migration', $after === $before,
        'got: ' . implode(',', array_map('strval', (array)$after)));

    // All pre-existing ENUM values still accepted.
    foreach ($before as $st) {
        try {
            $pdo->exec("INSERT INTO leads (company_name, email, status) VALUES ('X', 're_{$st}@example.test', '{$st}')");
            $okVal = true;
        } catch (\Throwable) {
            $okVal = false;
        }
        nr_check("pre-existing ENUM value '{$st}' still insertable", $okVal);
    }

    // The new value is usable.
    try {
        $pdo->exec("INSERT INTO leads (company_name, email, status, lead_score) VALUES " .
            "('Review Co', 'needsreview@example.test', 'Needs Review', 62)");
        nr_check('new lead can be inserted with Needs Review status', true);
        $st = $pdo->query("SELECT status FROM leads WHERE email = 'needsreview@example.test'")->fetchColumn();
        nr_check('Needs Review round-trips through the ENUM', $st === 'Needs Review', "got: {$st}");
    } catch (\Throwable $e) {
        nr_check('new lead can be inserted with Needs Review status', false, substr($e->getMessage(), 0, 160));
    }
} catch (\Throwable $e) {
    nr_check('migration idempotency suite completed without exception', false, $e->getMessage());
} finally {
    nr_restore_db_config();
}

exit(nr_summary('test_migration_idempotency.php'));
