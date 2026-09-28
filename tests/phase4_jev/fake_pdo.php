<?php
/**
 * Phase 4 test doubles: a scripted \\App\\PDO that returns canned rows per
 * query fragment, so the new decision actions can be exercised end-to-end
 * with zero database and zero network.
 *
 * Reuses FakeStatement from tests/phase3/fake_pdo.php (requires
 * phase3/common.php first for phase3_repo_root()).
 */
declare(strict_types=1);

// Need phase3's FakeStatement without pulling in phase3/common.php's
// check() (our common.php already defines it). Define the one helper
// fake_pdo.php needs, guarded in case phase3/common.php was loaded first.
if (!function_exists('phase3_repo_root')) {
    function phase3_repo_root(): string
    {
        return dirname(__DIR__, 2);
    }
}
require_once __DIR__ . '/../phase3/fake_pdo.php';

/**
 * One scripted statement: behaves like FakeStatement, except fetchAll() with
 * FETCH_KEY_PAIR returns the rows as-is (scripted key-pair maps for the
 * settings-preload query).
 */
class ScriptedStatement extends FakeStatement
{
    /** @var array<int|string,mixed> rows as fetchAll(FETCH_KEY_PAIR) should return */
    public array $keyPairRows = [];

    public function fetchAll(int $mode = \App\PDO::FETCH_ASSOC): array
    {
        if ($mode === \App\PDO::FETCH_KEY_PAIR && $this->keyPairRows !== []) {
            return $this->keyPairRows;
        }
        return parent::fetchAll($mode);
    }
}

/**
 * Param-aware variant: resolves fetch rows from the execute() params via a
 * caller-supplied callback. Used for queries whose result depends on the
 * bound parameter (e.g. settings lookups by key).
 */
class ParamStatement extends ScriptedStatement
{
    /** @var callable|null (array $params): array<int,array<string,mixed>> */
    public $rowFn = null;

    public function execute(array $params = null): bool
    {
        $p = $params ?? [];
        $this->executions[] = $p;
        if ($this->rowFn !== null) {
            $this->rows = ($this->rowFn)($p);
        }
        return true;
    }
}

/**
 * Scripted fake PDO. Matchers: list of ['match' => substring (case-insensitive),
 * 'rows' => fetch rows, 'keypair' => key-pair rows for fetchAll(KEY_PAIR),
 * 'rowFn' => param-aware row callback (overrides 'rows' at execute() time)].
 * First matching rule wins; unmatched queries return no rows.
 */
class ScriptedPdo extends \App\PDO
{
    /** @var array<int,array{match:string,rows:array,keypair:array}> */
    public array $scripts = [];
    /** @var string[] SQL of every prepared/executed statement, in order */
    public array $sqlLog = [];

    public function __construct()
    {
        // Deliberately does NOT call parent: no DB connection is opened.
    }

    /**
     * @param array<int,array{match:string,rows?:array,keypair?:array,rowFn?:callable}> $scripts
     */
    public static function make(array $scripts): self
    {
        $ref = new ReflectionClass(self::class);
        /** @var self $pdo */
        $pdo = $ref->newInstanceWithoutConstructor();
        $pdo->scripts = $scripts;
        return $pdo;
    }

    public function prepare(string $query): \App\PDOStatement|false
    {
        $this->sqlLog[] = $query;
        [$rows, $keypair, $rowFn] = $this->rowsFor($query);
        if ($rowFn !== null) {
            $ref = new ReflectionClass(ParamStatement::class);
            /** @var ParamStatement $stmt */
            $stmt = $ref->newInstanceWithoutConstructor();
            $stmt->query = $query;
            $stmt->rows = $rows;
            $stmt->keyPairRows = $keypair;
            $stmt->rowFn = $rowFn;
            return $stmt;
        }
        $stmt = ScriptedStatement::make($query, $rows);
        $stmt->keyPairRows = $keypair;
        return $stmt;
    }

    public function query(string $query): \App\PDOStatement|false
    {
        $this->sqlLog[] = $query;
        [$rows, $keypair] = $this->rowsFor($query);
        $stmt = ScriptedStatement::make($query, $rows);
        $stmt->keyPairRows = $keypair;
        return $stmt;
    }

    /** @return array{0:array,1:array,2:callable|null} */
    private function rowsFor(string $query): array
    {
        foreach ($this->scripts as $s) {
            if (stripos($query, $s['match']) !== false) {
                return [$s['rows'] ?? [], $s['keypair'] ?? [], $s['rowFn'] ?? null];
            }
        }
        return [[], [], null];
    }

    /** True when some statement's SQL contains $fragment. */
    public function sawSql(string $fragment): bool
    {
        foreach ($this->sqlLog as $sql) {
            if (stripos($sql, $fragment) !== false) {
                return true;
            }
        }
        return false;
    }
}
