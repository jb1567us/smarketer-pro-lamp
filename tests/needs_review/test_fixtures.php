#!/usr/bin/env php
<?php
/**
 * Fixture verification: the needs_review fixture factory produces one lead
 * per qualification state (qualified / unqualified / needs_review) with
 * production-faithful fields.
 *
 * Fully independent of the sibling subjects — the scratch leads table
 * declares the full ENUM itself, and the notes markers come from the live
 * QualifyLeadAction::notesMarker() code path.
 *
 * Usage: php tests/needs_review/test_fixtures.php
 * The repo tree is left exactly as it was (config/db.php restored/deleted).
 */
declare(strict_types=1);

require __DIR__ . '/common.php';
require nr_repo_root() . '/includes/autoload.php';
require __DIR__ . '/fixtures.php';

try {
    $pdo = nr_scratch_db('nr_fixtures', 'nr_test', 'nr_test_pw_4x8');

    // --- DDL ----------------------------------------------------------------
    $pdo->exec(nr_leads_table_ddl());
    nr_check('leads ENUM includes Needs Review',
        str_contains(nr_status_enum($pdo), "'needs review'"));

    // --- Seeding --------------------------------------------------------------
    $ids = nr_seed_fixtures($pdo);
    nr_check('one fixture per qualification state', count($ids) === 3
        && isset($ids['qualified'], $ids['needs_review'], $ids['unqualified']));
    nr_check('fixture ids are distinct', count(array_unique($ids)) === 3);

    $rows = [];
    foreach ($pdo->query('SELECT id, company_name, email, status, lead_score, notes FROM leads')->fetchAll() as $r) {
        $rows[$r['status']] = $r;
    }

    // --- States ----------------------------------------------------------------
    nr_check('qualified fixture has status Qualified', ($rows['Qualified']['status'] ?? '') === 'Qualified');
    nr_check('unqualified fixture has status Unqualified', ($rows['Unqualified']['status'] ?? '') === 'Unqualified');
    nr_check('needs_review fixture has status Needs Review', ($rows['Needs Review']['status'] ?? '') === 'Needs Review');

    // --- Score bands -------------------------------------------------------------
    nr_check('qualified score at/above qualify threshold (75)',
        (int)$rows['Qualified']['lead_score'] >= 75);
    nr_check('needs_review score inside the 50-75 review band',
        (int)$rows['Needs Review']['lead_score'] >= 50 && (int)$rows['Needs Review']['lead_score'] < 75);
    nr_check('unqualified score below review band',
        (int)$rows['Unqualified']['lead_score'] < 50);

    // --- Notes markers (production-faithful) --------------------------------------
    nr_check('needs_review notes carry the Needs Review marker with fit score',
        str_contains((string)$rows['Needs Review']['notes'], 'Needs Review (fit 62/100'));
    nr_check('needs_review notes name the qualify threshold',
        str_contains((string)$rows['Needs Review']['notes'], 'below qualify threshold 75'));
    nr_check('qualified notes carry the Qualified marker',
        str_contains((string)$rows['Qualified']['notes'], 'Qualified (fit 85/100'));

    // --- Isolation between fixtures -------------------------------------------------
    $emails = array_column($rows, 'email');
    nr_check('fixture emails are distinct', count(array_unique($emails)) === 3);
    nr_check('fixture companies are distinct',
        count(array_unique(array_column($rows, 'company_name'))) === 3);
} catch (\Throwable $e) {
    nr_check('fixture suite completed without exception', false, $e->getMessage());
} finally {
    nr_restore_db_config();
}

exit(nr_summary('test_fixtures.php'));
