<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();

$dir = new RecursiveDirectoryIterator(__DIR__);
$iterator = new RecursiveIteratorIterator($dir);

$files = [];
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && strpos($file->getPathname(), 'includes') === false && strpos($file->getPathname(), 'vendor') === false && $file->getFilename() !== 'refactor_db.php') {
        $files[] = $file->getPathname();
    }
}

foreach ($files as $filePath) {
    $content = file_get_contents($filePath);
    $original = $content;

    // Replace require db.php
    $content = preg_replace("/require_once\s+['\"].*?includes\/db\.php['\"];/", "require_once __DIR__ . '/" . (strpos($filePath, 'api') !== false || strpos($filePath, 'cron') !== false ? '../' : '') . "includes/autoload.php';\n\$pdo = \\App\\Database::getConnection();", $content);

    // Replace require runner.php
    $content = preg_replace("/require_once\s+['\"].*?includes\/runner\.php['\"];/", "", $content);

    // Replace runner usage
    $content = preg_replace("/\\\$runner\s*=\s*new\s+OutreachRunner\(\\\$pdo\);/i", "\$processor = new \\App\\Domain\\TaskProcessor(\$pdo, new \\App\\Routers\\SmartLLMRouter(\$pdo));", $content);
    $content = preg_replace("/\\\$runner->processTask\((.*?)\);/i", "\$processor->processTask($1);", $content);

    if ($content !== $original) {
        file_put_contents($filePath, $content);
        echo "Updated: $filePath\n";
    }
}
