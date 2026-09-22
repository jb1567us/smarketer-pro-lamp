<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'lookoverhere_wp947';
$username = getenv('DB_USER') ?: 'lookoverhere_wp947';
$password = getenv('DB_PASS') ?: '';

$mysqli = new mysqli($host, $username, $password, $dbname);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

$sql = file_get_contents(__DIR__ . '/schema.sql');

if ($mysqli->multi_query($sql)) {
    do {
        // Store first result set
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
    echo "Schema imported successfully!\n";
} else {
    echo "Error importing schema: " . $mysqli->error . "\n";
}

$mysqli->close();
