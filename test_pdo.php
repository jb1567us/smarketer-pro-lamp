<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
if (class_exists('PDO')) {
    echo "PDO IS AVAILABLE. Extensions: " . implode(', ', \App\PDO::getAvailableDrivers());
} else {
    echo "PDO IS NOT AVAILABLE.";
}
?>
