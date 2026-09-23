<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/autoload.php';

echo "<h2>[Diagnostics] Detailed step-by-step diagnostics...</h2>";

try {
    echo "1. Checking App\\Database class availability...<br>";
    if (class_exists('App\\Database')) {
        echo "   App\\Database exists.<br>";
    } else {
        echo "   App\\Database does not exist!<br>";
    }
    
    echo "2. Attempting Database::getConnection()...<br>";
    try {
        $pdo = \App\Database::getConnection();
        echo "   Database::getConnection() succeeded!<br>";
    } catch (\Exception $e) {
        echo "   Database::getConnection() failed as expected: " . $e->getMessage() . "<br>";
    }
    
    echo "3. Instantiating SmartEmailRouter...<br>";
    $router = new \App\Routers\SmartEmailRouter();
    echo "   SmartEmailRouter instantiated successfully!<br>";
    
} catch (\Exception $e) {
    echo "   Exception caught: " . $e->getMessage() . "<br>";
    echo "   Stack trace: <pre>" . $e->getTraceAsString() . "</pre><br>";
} catch (\Throwable $t) {
    echo "   Throwable caught: " . $t->getMessage() . "<br>";
    echo "   Stack trace: <pre>" . $t->getTraceAsString() . "</pre><br>";
}

echo "Diagnostics complete.<br>";
