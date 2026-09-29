<?php
/**
 * needs_review fixtures: one lead per qualification state — qualified,
 * unqualified, needs_review — with production-faithful fields.
 *
 * The notes markers are produced by QualifyLeadAction::notesMarker() itself
 * (same code path the pipeline uses), so fixture text can never drift from
 * the real qualification output. The leads-table DDL declares the full ENUM
 * including 'Needs Review' — the value sibling 1 (schema) adds — so these
 * fixtures are usable before and after the sibling lands.
 *
 * Not a test file: required by the test_* files via nr_seed_fixtures().
 */
declare(strict_types=1);

use App\Actions\QualifyLeadAction;

/** leads-table DDL with the full qualification-status ENUM incl. 'Needs Review'. */
function nr_leads_table_ddl(): string
{
    return "CREATE TABLE leads (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(255) NOT NULL,
        contact_name VARCHAR(255),
        email VARCHAR(255) UNIQUE NOT NULL,
        website VARCHAR(255),
        status ENUM('New', 'Enriched', 'Contacted', 'Qualified', 'Unqualified',
                    'Converted', 'Drafted', 'Needs Review') DEFAULT 'New',
        lead_score INT DEFAULT 0,
        source VARCHAR(100),
        notes TEXT,
        country_code CHAR(2) NULL,
        consent_status VARCHAR(20) NOT NULL DEFAULT 'unknown',
        campaign_id INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB";
}

/**
 * Fixture definitions keyed by qualification state.
 *
 * @return array<string, array{status: string, score: int, verdict: string, company: string, email: string}>
 */
function nr_fixture_defs(): array
{
    return [
        'qualified' => [
            'status' => 'Qualified', 'score' => 85, 'verdict' => 'qualified',
            'company' => 'Acme Logistics', 'email' => 'jane@acmelogistics.test',
        ],
        'needs_review' => [
            'status' => 'Needs Review', 'score' => 62, 'verdict' => 'needs_review',
            'company' => 'Beta Freight', 'email' => 'bob@betafreight.test',
        ],
        'unqualified' => [
            'status' => 'Unqualified', 'score' => 12, 'verdict' => 'unqualified',
            'company' => 'Gamma Retail', 'email' => 'gus@gammaretail.test',
        ],
    ];
}

/** Build the production-faithful notes marker for a fixture. */
function nr_fixture_notes(array $def): string
{
    $result = [
        'fit_score' => $def['score'],
        'verdict' => $def['verdict'],
        'reason' => 'Fixture: synthesized qualification verdict.',
        'source' => 'weighted',
        'thresholds' => ['qualify' => 75, 'review' => 50],
        'dimensions' => ['company_size' => 8, 'industry_fit' => 7, 'tech_stack' => 6],
    ];
    $status = $def['status'];
    return QualifyLeadAction::notesMarker($result, $status);
}

/**
 * Seed one lead per qualification state. Returns state => lead id.
 */
function nr_seed_fixtures(\App\PDO $pdo): array
{
    $ids = [];
    foreach (nr_fixture_defs() as $state => $def) {
        $stmt = $pdo->prepare(
            "INSERT INTO leads (company_name, contact_name, email, website, status, lead_score, notes, country_code, consent_status) " .
            "VALUES (?, ?, ?, ?, ?, ?, ?, 'US', 'express')"
        );
        $stmt->execute([
            $def['company'],
            'Test Contact',
            $def['email'],
            'https://example.test/' . $state,
            $def['status'],
            $def['score'],
            nr_fixture_notes($def),
        ]);
        $ids[$state] = (int)$pdo->lastInsertId();
    }
    return $ids;
}
