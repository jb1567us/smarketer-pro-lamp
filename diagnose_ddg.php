<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$query = "site:linkedin.com \"Austin\" \"CEO\"";
$queryEncoded = urlencode($query);
$url = "https://html.duckduckgo.com/html/?q=" . $queryEncoded;

$ch = curl_init($url);
$options = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
        'Accept-Language: en-US,en;q=0.9',
        'Cache-Control: max-age=0',
        'Sec-Ch-Ua: "Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
        'Sec-Ch-Ua-Mobile: ?0',
        'Sec-Ch-Ua-Platform: "Windows"',
        'Sec-Fetch-Dest: document',
        'Sec-Fetch-Mode: navigate',
        'Sec-Fetch-Site: none',
        'Sec-Fetch-User: ?1',
        'Upgrade-Insecure-Requests: 1'
    ]
];

curl_setopt_array($ch, $options);
$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "=== DIAGNOSE DDG CURL ===\n";
echo "HTTP Code: $httpCode\n";
echo "cURL Error: $curlError\n";
echo "HTML Length: " . strlen($html) . "\n";
echo "Contains result block: " . (strpos($html, '<div class="result ') !== false ? "YES" : "NO") . "\n";
echo "\nFirst 1000 chars of HTML:\n";
echo substr($html, 0, 1000) . "\n";
