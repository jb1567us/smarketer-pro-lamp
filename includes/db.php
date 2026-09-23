<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Basic configuration (Modified for Docker/Environment Variables)
$db_config = [
    'host' => getenv('DB_HOST') ?: 'db', // Docker service name is 'db'
    'dbname' => getenv('DB_NAME') ?: 'b2b_outreach',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') ?: '',
    'charset' => 'utf8mb4'
];

$max_retries = 5;
$retry_count = 0;
while ($retry_count < $max_retries) {
    try {
        $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset={$db_config['charset']}";
        $options = [
            3 => 2, // PDO::ATTR_ERR_MODE => PDO::ERR_MODE_EXCEPTION
            19 => 2, // PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            20 => false, // PDO::ATTR_EMULATE_PREPARES => false
        ];
        $pdo = new PDO($dsn, $db_config['username'], $db_config['password'], $options);
        break; // Success!
    } catch (PDOException $e) {
        $retry_count++;
        if ($retry_count >= $max_retries) {
            die("Database connection failed after $max_retries attempts: (" . $db_config['host'] . ") " . $e->getMessage());
        }
        sleep(2); // Wait before retrying
    }
}

/**
 * Helper to get a setting from the database
 */
function getSetting($pdo, $key, $default = null) {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    return $result ? $result['setting_value'] : $default;
}
?>
