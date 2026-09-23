<?php
namespace App;

use Exception;
use mysqli;
use mysqli_stmt;

class PDOException extends Exception {}

class PDO
{
    public const FETCH_ASSOC = 2;
    public const FETCH_COLUMN = 7;
    public const FETCH_KEY_PAIR = 12;
    public const ERRMODE_EXCEPTION = 2;
    public const ATTR_ERRMODE = 3;
    public const ATTR_DEFAULT_FETCH_MODE = 19;
    public const ATTR_EMULATE_PREPARES = 20;

    private mysqli $mysqli;
    private array $attributes = [];

    public function __construct(string $dsn, string $username = '', string $password = '', array $options = [])
    {
        $parts = explode(':', $dsn, 2);
        if (count($parts) < 2) {
            throw new PDOException("Invalid DSN");
        }
        $params = [];
        foreach (explode(';', $parts[1]) as $kv) {
            $kvParts = explode('=', $kv, 2);
            if (count($kvParts) === 2) {
                $params[$kvParts[0]] = $kvParts[1];
            }
        }

        $host = $params['host'] ?? 'localhost';
        $dbname = $params['dbname'] ?? '';
        $charset = $params['charset'] ?? 'utf8mb4';

        \mysqli_report(\MYSQLI_REPORT_ERROR | \MYSQLI_REPORT_STRICT);
        try {
            $this->mysqli = new mysqli($host, $username, $password, $dbname);
            $this->mysqli->set_charset($charset);
        } catch (\mysqli_sql_exception $e) {
            throw new PDOException($e->getMessage(), $e->getCode(), $e);
        }

        $this->attributes = $options;
    }

    
    public function exec(string $statement): int|false
    {
        try {
            $result = $this->mysqli->query($statement);
            if ($result === false) {
                return false;
            }
            return $this->mysqli->affected_rows;
        } catch (\mysqli_sql_exception $e) {
            throw new PDOException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function prepare(string $query): PDOStatement|false
    {
        try {
            $stmt = $this->mysqli->prepare($query);
            if (!$stmt) {
                return false;
            }
            return new PDOStatement($stmt);
        } catch (\mysqli_sql_exception $e) {
            throw new PDOException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function query(string $query): PDOStatement|false
    {
        $stmt = $this->prepare($query);
        if ($stmt) {
            $stmt->execute();
        }
        return $stmt;
    }

    public function lastInsertId(): string
    {
        return (string)$this->mysqli->insert_id;
    }
}

class PDOStatement
{
    private mysqli_stmt $stmt;
    private $result = null;
    private $buffered_results = null;
    private $buffered_index = 0;

    public function __construct(mysqli_stmt $stmt)
    {
        $this->stmt = $stmt;
    }

    public function execute(array $params = null): bool
    {
        try {
            if ($params !== null && !empty($params)) {
                $types = '';
                $bindParams = [];
                foreach ($params as $param) {
                    if (is_int($param)) {
                        $types .= 'i';
                    } elseif (is_float($param)) {
                        $types .= 'd';
                    } else {
                        $types .= 's';
                    }
                }
                
                $bindParams[] = $types;
                $refs = [];
                foreach ($params as $key => $value) {
                    $refs[$key] = $params[$key];
                    $bindParams[] = &$refs[$key];
                }
                
                call_user_func_array([$this->stmt, 'bind_param'], $bindParams);
            }
            
            $success = $this->stmt->execute();
            if ($success) {
                if (method_exists($this->stmt, 'get_result')) {
                    $this->result = $this->stmt->get_result();
                } else {
                    $this->stmt->store_result();
                    $meta = $this->stmt->result_metadata();
                    if ($meta) {
                        $fields = [];
                        $row = [];
                        while ($field = $meta->fetch_field()) {
                            $fields[] = &$row[$field->name];
                        }
                        call_user_func_array([$this->stmt, 'bind_result'], $fields);
                        
                        $results = [];
                        while ($this->stmt->fetch()) {
                            $c = [];
                            foreach ($row as $key => $val) {
                                $c[$key] = $val;
                            }
                            $results[] = $c;
                        }
                        $this->buffered_results = $results;
                        $this->buffered_index = 0;
                    }
                }
            }
            return $success;
        } catch (\mysqli_sql_exception $e) {
            throw new PDOException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function fetch(int $mode = PDO::FETCH_ASSOC)
    {
        if ($this->result) {
            return $this->result->fetch_assoc() ?: false;
        }
        if ($this->buffered_results !== null) {
            if ($this->buffered_index < count($this->buffered_results)) {
                return $this->buffered_results[$this->buffered_index++];
            }
        }
        return false;
    }

    public function fetchColumn(int $columnNumber = 0)
    {
        $row = $this->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        $values = array_values($row);
        return $values[$columnNumber] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array
    {
        $rows = [];
        if ($this->result) {
            while ($row = $this->result->fetch_assoc()) {
                $rows[] = $row;
            }
        } elseif ($this->buffered_results !== null) {
            $rows = array_slice($this->buffered_results, $this->buffered_index);
            $this->buffered_index = count($this->buffered_results);
        }

        if ($mode === PDO::FETCH_KEY_PAIR) {
            $keyPair = [];
            foreach ($rows as $row) {
                $values = array_values($row);
                if (count($values) >= 2) {
                    $keyPair[$values[0]] = $values[1];
                }
            }
            return $keyPair;
        }

        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(fn($row) => array_values($row)[0] ?? null, $rows);
        }

        return $rows;
    }
    
    public function rowCount(): int
    {
        return $this->stmt->affected_rows;
    }
}
