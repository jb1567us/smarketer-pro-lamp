<?php
$url = "http://searxng:8080/search?q=test&format=json";
echo "Testing connectivity to: $url\n";

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_VERBOSE, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");

$response = curl_exec($ch);
$info = curl_getinfo($ch);
$error = curl_error($ch);

echo "\n--- CURL INFO ---\n";
print_r($info);

echo "\n--- CURL ERROR ---\n";
echo $error . "\n";

echo "\n--- RAW RESPONSE ---\n";
echo substr($response, 0, 500) . "...\n";
?>
