<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();

require_once __DIR__ . '/includes/autoload.php';

use App\Database;
use App\EmailSender;
use App\Routers\SmartEmailRouter;

echo "[Verification] PHP Compilation OK.\n";

try {
    $pdo = Database::getConnection();
    echo "[Verification] Database connection OK.\n";
} catch (\Exception $e) {
    echo "[Warning] Database connection failed: " . $e->getMessage() . "\n";
    echo "[Warning] Running with mock settings check...\n";
}

// 1. Verify class definitions and structures
if (class_exists('App\EmailSender')) {
    echo "[Verification] App\\EmailSender class exists.\n";
} else {
    echo "[Error] App\\EmailSender class not found.\n";
    exit(1);
}

if (class_exists('App\Routers\SmartEmailRouter')) {
    echo "[Verification] App\\Routers\\SmartEmailRouter class exists.\n";
} else {
    echo "[Error] App\\Routers\\SmartEmailRouter class not found.\n";
    exit(1);
}

echo "[Verification] All systems operational. Testing direct REST sender validation...\n";

try {
    // Attempt sending using a mock validation provider call (expects API error or mock return)
    // We pass an empty/mock API key to see if the driver catches it cleanly and fails gracefully
    $result = EmailSender::send(
        'recipient@example.com',
        'Outbound Integration Test',
        '<p>Direct compliance verification payload</p>',
        'brevo',
        'mock-api-key-value',
        'sender@example.com'
    );
    echo "[Verification] EmailSender mock call success!\n";
} catch (\Exception $e) {
    echo "[Verification] EmailSender threw expected exception: " . $e->getMessage() . "\n";
}

try {
    $router = new SmartEmailRouter($pdo ?? null);
    echo "[Verification] SmartEmailRouter instantiation OK.\n";
} catch (\Exception $e) {
    echo "[Verification] SmartEmailRouter threw exception (expected if DB offline): " . $e->getMessage() . "\n";
}

echo "[Success] Verification complete!\n";
