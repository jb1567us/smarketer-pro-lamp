<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/autoload.php';

echo "Autoload initialized.\n";

// Let's manually require CreativeAgent.php to register the subclasses
if (file_exists(__DIR__ . '/includes/Agents/CreativeAgent.php')) {
    require_once __DIR__ . '/includes/Agents/CreativeAgent.php';
    echo "CreativeAgent.php required manually.\n";
}

try {
    echo "Testing SocialMediaAgent instantiation...\n";
    $agent = new \App\Agents\SocialMediaAgent(null);
    echo "Success: SocialMediaAgent instantiated!\n";
} catch (Throwable $e) {
    echo "Failed SocialMediaAgent: " . $e->getMessage() . "\n";
}

try {
    echo "Testing VideoAgent instantiation...\n";
    $agent = new \App\Agents\VideoAgent(null);
    echo "Success: VideoAgent instantiated!\n";
} catch (Throwable $e) {
    echo "Failed VideoAgent: " . $e->getMessage() . "\n";
}
