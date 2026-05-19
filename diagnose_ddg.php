<?php
require_once __DIR__ . '/includes/autoload.php';
$pdo = \App\Database::getConnection();

echo "--- Proxies Status in DB ---\n";
$stmt = $pdo->query("SELECT * FROM proxies");
$proxies = $stmt->fetchAll(\App\PDO::FETCH_ASSOC);
echo "Total Proxies: " . count($proxies) . "\n";
foreach ($proxies as $p) {
    echo "- ID: {$p['id']} URL: " . substr($p['url'], 0, 30) . "... Status: {$p['status']} Fails: {$p['fail_count']}\n";
}

echo "\n--- Running DDG Search WITHOUT Proxy ---\n";
// Let's call DDG without proxies
$url = "https://html.duckduckgo.com/html/?q=" . urlencode("linkedin.com/in Real Estate owner Austin");
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "HTML Length: " . strlen($html) . "\n";
if ($html) {
    echo "\n--- HTML Snippet ---\n";
    echo htmlspecialchars(substr($html, 0, 1500)) . "\n";
    
    echo "\n--- Div tags check ---\n";
    if (preg_match_all('/<div[^>]*class="([^"]+)"/i', $html, $matches)) {
        echo "Found classes: " . implode(', ', array_unique($matches[1])) . "\n";
    }
}
