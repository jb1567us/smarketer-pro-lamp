<?php
/**
 * Item 9 — persona text must never land in leads.contact_name.
 *
 * contact_name holds an actual person's name (or NULL); user-entered
 * role/audience descriptions ("CTO at SaaS companies", "marketing managers")
 * belong in leads.target_persona ONLY.
 *
 * Standalone (no database needed):
 *     php tests/compliance/PersonaNameTest.php
 *
 * Also callable from the MariaDB-backed suite:
 *     tests/compliance/run_compliance_tests.php
 * via persona_name_tests(callable $ok).
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/SimpleHarvester.php';

/** Minimal PDO stand-in that records INSERTs and reports no duplicates. */
final class PersonaNameFakeStmt
{
    /** @var array<int,array> */
    public array $executes = [];
    /** @var callable|null */
    private $onExecute;

    public function __construct(?callable $onExecute = null)
    {
        $this->onExecute = $onExecute;
    }

    public function execute(?array $params = null): bool
    {
        $params = $params ?? [];
        $this->executes[] = $params;
        if ($this->onExecute !== null) {
            ($this->onExecute)($params);
        }
        return true;
    }

    /** No duplicates in the fake: always "not found". */
    public function fetch()
    {
        return false;
    }
}

final class PersonaNameFakePDO
{
    /** @var array<int,array> INSERT param sets, in execution order. */
    public array $inserts = [];

    public function prepare(string $sql): PersonaNameFakeStmt
    {
        if (stripos(ltrim($sql), 'INSERT INTO leads') === 0) {
            return new PersonaNameFakeStmt(fn (array $p) => $this->inserts[] = $p);
        }
        return new PersonaNameFakeStmt();
    }
}

/**
 * @param callable(bool,string):void $ok
 */
function persona_name_tests(callable $ok): void
{
    // --- Mapper: persona text must NOT become contact_name -----------------
    [$cn, $tp] = \App\LeadFields::mapContactAndPersona('CTO at SaaS companies');
    $ok($cn === null, 'persona "CTO at SaaS companies" -> contact_name NULL');
    $ok($tp === 'CTO at SaaS companies', 'persona "CTO at SaaS companies" -> target_persona set');

    [$cn, $tp] = \App\LeadFields::mapContactAndPersona('marketing managers');
    $ok($cn === null, 'persona "marketing managers" -> contact_name NULL');
    $ok($tp === 'marketing managers', 'persona "marketing managers" -> target_persona set');

    [$cn, $tp] = \App\LeadFields::mapContactAndPersona('Prospect Lead');
    $ok($cn === null, 'fallback "Prospect Lead" -> contact_name NULL');
    $ok($tp === 'Prospect Lead', 'fallback "Prospect Lead" -> target_persona set');

    [$cn, $tp] = \App\LeadFields::mapContactAndPersona('');
    $ok($cn === null && $tp === null, 'empty input -> both NULL');

    // --- Mapper: real names go to contact_name ------------------------------
    [$cn, $tp] = \App\LeadFields::mapContactAndPersona('Jane Smith');
    $ok($cn === 'Jane Smith', 'real name "Jane Smith" -> contact_name set');
    $ok($tp === null, 'real name with no persona -> target_persona NULL');

    [$cn, $tp] = \App\LeadFields::mapContactAndPersona('Jane Smith', 'CTO at SaaS companies');
    $ok($cn === 'Jane Smith', 'name + persona: contact_name keeps the name');
    $ok($tp === 'CTO at SaaS companies', 'name + persona: target_persona keeps the persona');

    // --- isPersonName edge cases --------------------------------------------
    $ok(\App\LeadFields::isPersonName('Jane') === true, 'single first name accepted');
    $ok(\App\LeadFields::isPersonName('J. Smith') === true, 'middle initial accepted');
    $ok(\App\LeadFields::isPersonName("O'Brien") === true, 'apostrophe accepted');
    $ok(\App\LeadFields::isPersonName('Mary-Jane Watson') === true, 'hyphen accepted');
    $ok(\App\LeadFields::isPersonName('jane smith') === false, 'lowercase is not a name (conservative)');
    $ok(\App\LeadFields::isPersonName('Jane Smith 2') === false, 'digits are not a name');
    $ok(\App\LeadFields::isPersonName('info@acme.com') === false, 'email address is not a name');
    $ok(\App\LeadFields::isPersonName('https://acme.com/about') === false, 'URL is not a name');
    $ok(\App\LeadFields::isPersonName('Marketing Director') === false, 'role title is not a name');
    $ok(\App\LeadFields::isPersonName('Jane Smith Director of Sales at Acme') === false, 'long role phrase is not a name');
    $ok(\App\LeadFields::isPersonName('') === false, 'empty string is not a name');

    // --- stageResults: persona text lands in target_persona, not contact ----
    // INSERT placeholders in SimpleHarvester::stageResults ('Harvested', 'New',
    // 'unknown', 'unknown' are literals):
    //   0 company_name, 1 contact_name, 2 email, 3 website, 4 campaign_id,
    //   5 notes, 6 target_persona, 7 email_source, 8 source_url, 9 is_role_based
    $db = new PersonaNameFakePDO();
    $results = [[
        'url' => 'https://acme.example/',
        'title' => 'Acme Corp - We build widgets',
        'content' => 'Acme Corp makes widgets. Reach jane@acme.example for details.',
        'snippet' => '',
    ]];
    $out = \SimpleHarvester::stageResults($db, $results, 'saas widgets', null, 'CTO at SaaS companies');

    $ok($out['staged'] === 1, 'stageResults staged the harvested result');
    $insert = $db->inserts[0] ?? null;
    $ok($insert !== null, 'stageResults issued an INSERT into leads');
    if ($insert !== null) {
        $ok($insert[1] === null, 'stageResults: contact_name is NULL (no persona leak)');
        $ok($insert[6] === 'CTO at SaaS companies', 'stageResults: target_persona carries the persona');
        $ok($insert[0] === 'Acme Corp', 'stageResults: company_name still parsed from title');
    }

    // Harvest with no persona: both stay empty/NULL.
    $db2 = new PersonaNameFakePDO();
    \SimpleHarvester::stageResults($db2, $results, 'saas widgets', null, '');
    $row2 = $db2->inserts[0] ?? null;
    $ok($row2 !== null && $row2[1] === null, 'stageResults without persona: contact_name NULL');
    $ok($row2 !== null && $row2[6] === null, 'stageResults without persona: target_persona NULL');
}

// Standalone CLI entry point — no database required.
if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    $passed = 0;
    $failed = 0;
    persona_name_tests(function (bool $cond, string $name) use (&$passed, &$failed): void {
        if ($cond) {
            $passed++;
            echo "  PASS: {$name}\n";
        } else {
            $failed++;
            echo "  FAIL: {$name}\n";
        }
    });
    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
}
