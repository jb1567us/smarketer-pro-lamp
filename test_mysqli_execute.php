<?php
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'lookover_b2b';
$username = getenv('DB_USER') ?: 'lookover_b2b';
$password = getenv('DB_PASS') ?: '';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysqli = new mysqli($host, $username, $password, $dbname);
    $stmt = $mysqli->prepare("SELECT 1 AS val WHERE 1 = ?");
    $stmt->execute([1]);
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    echo "SUCCESS: " . $row['val'];
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
