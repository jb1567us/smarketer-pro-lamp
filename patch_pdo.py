content = open(r'D:\sandbox\b2b_outreach_lamp\includes\PDO.php', 'r', encoding='utf-8').read()

new_class = """class PDOStatement
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
        } catch (\\mysqli_sql_exception $e) {
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

    public function fetchAll(int $mode = PDO::FETCH_ASSOC): array
    {
        if ($this->result) {
            $rows = [];
            while ($row = $this->result->fetch_assoc()) {
                $rows[] = $row;
            }
            return $rows;
        }
        if ($this->buffered_results !== null) {
            $rows = array_slice($this->buffered_results, $this->buffered_index);
            $this->buffered_index = count($this->buffered_results);
            return $rows;
        }
        return [];
    }
    
    public function rowCount(): int
    {
        return $this->stmt->affected_rows;
    }
}
"""

start_idx = content.find("class PDOStatement")
if start_idx != -1:
    content = content[:start_idx] + new_class

open(r'D:\sandbox\b2b_outreach_lamp\includes\PDO.php', 'w', encoding='utf-8').write(content)
print("Updated PDO.php")
