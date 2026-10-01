<?php
/**
 * Phase 3 test doubles: a fake \App\PDO that records every prepared
 * statement + execution params, with scripted fetch rows.
 *
 * Lets ReplyRouter be exercised end-to-end with zero database.
 */
declare(strict_types=1);

// Load App\PDO / App\PDOStatement eagerly: FakeStatement extends
// \App\PDOStatement, and the autoloader cannot resolve it (no dedicated
// includes/PDOStatement.php file — both classes live in includes/PDO.php).
require_once phase3_repo_root() . '/includes/PDO.php';

/** One fake statement: records execute() params, returns scripted fetch rows. */
class FakeStatement extends \App\PDOStatement
{
    /** @var array<int,array<string,mixed>> recorded execute() param sets */
    public array $executions = [];
    /** @var array<int,array<string,mixed>|false> rows returned by fetch() */
    public array $rows = [];
    private int $cursor = 0;
    public string $query;

    public static function make(string $query, array $rows = []): self
    {
        $ref = new ReflectionClass(self::class);
        /** @var self $stmt */
        $stmt = $ref->newInstanceWithoutConstructor();
        $stmt->query = $query;
        $stmt->rows = $rows;
        return $stmt;
    }

    public function execute(array $params = null): bool
    {
        $this->executions[] = $params ?? [];
        return true;
    }

    public function fetch(int $mode = \App\PDO::FETCH_ASSOC)
    {
        if ($this->cursor >= count($this->rows)) {
            return false;
        }
        return $this->rows[$this->cursor++];
    }

    public function fetchAll(int $mode = \App\PDO::FETCH_ASSOC): array
    {
        return $this->rows;
    }
}

/**
 * Fake \App\PDO. `$emailToLeadId` scripts findLeadIdByEmail():
 * email (lowercased) => lead id.
 */
class FakePdo extends \App\PDO
{
    /** @var FakeStatement[] every prepared statement, in order */
    public array $prepared = [];
    /** @var array<string,int> */
    public array $emailToLeadId = [];

    public function __construct()
    {
        // Deliberately does NOT call parent: no DB connection is opened.
    }

    public function prepare(string $query): \App\PDOStatement|false
    {
        $rows = [];
        if (stripos($query, 'SELECT id FROM leads WHERE email') !== false) {
            // findLeadIdByEmail — the email param arrives at execute() time;
            // FakeStatement rows are static, so resolve lazily: stash the
            // query and let a subclass hook handle it. Here we return one
            // row only if exactly one lead is mapped; the router test uses a
            // dedicated single-mapping fake for the resolution case.
            if (count($this->emailToLeadId) === 1) {
                $rows = [['id' => (int)array_values($this->emailToLeadId)[0]]];
            }
        }
        $stmt = FakeStatement::make($query, $rows);
        $this->prepared[] = $stmt;
        return $stmt;
    }

    /** @return string[] SQL of every prepared statement, in order */
    public function sqlLog(): array
    {
        return array_map(fn(FakeStatement $s) => $s->query, $this->prepared);
    }

    /** True when some prepared statement's SQL contains $fragment. */
    public function sawSql(string $fragment): bool
    {
        foreach ($this->prepared as $s) {
            if (stripos($s->query, $fragment) !== false) {
                return true;
            }
        }
        return false;
    }
}
