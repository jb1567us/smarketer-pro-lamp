content = open(r'D:\sandbox\b2b_outreach_lamp\includes\PDO.php', 'r', encoding='utf-8').read()

exec_method = """
    public function exec(string $statement): int|false
    {
        try {
            $result = $this->mysqli->query($statement);
            if ($result === false) {
                return false;
            }
            return $this->mysqli->affected_rows;
        } catch (\\mysqli_sql_exception $e) {
            throw new PDOException($e->getMessage(), $e->getCode(), $e);
        }
    }
"""

start_idx = content.find("public function prepare")
if start_idx != -1:
    content = content[:start_idx] + exec_method + "\n    " + content[start_idx:]

open(r'D:\sandbox\b2b_outreach_lamp\includes\PDO.php', 'w', encoding='utf-8').write(content)
print("Updated PDO.php with exec()")
