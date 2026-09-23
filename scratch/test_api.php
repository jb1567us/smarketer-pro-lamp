<?php
$ch = curl_init('http://localhost/b2b_outreach_lamp/api/mass_tools.php?action=harvest');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['keywords' => "test\nanother test"]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
var_dump(curl_getinfo($ch, CURLINFO_HTTP_CODE), $response);
