<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();
$mode = 'Simulation';
$stmt = $pdo->prepare("REPLACE INTO settings (setting_key, setting_value) VALUES ('operational_mode', ?)");
$stmt->execute([$mode]);
echo "Operational Mode set to: $mode\n";
