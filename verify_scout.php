<?php
$baseUrl = 'http://localhost/api/influencer_scout.php';

echo "--- INFLUENCER SCOUT VERIFICATION ---\n";

function post($url, $data) {
    $options = [
        'http' => [
            'header'  => "Content-type: application/json\r\n",
            'method'  => 'POST',
            'content' => json_encode($data),
        ],
    ];
    $context  = stream_context_create($options);
    return file_get_contents($url, false, $context);
}

// 1. Add Influencer
echo "[1] Adding Influencer...\n";
$res = post($baseUrl . '?action=add', [
    'name' => 'Tech Guru',
    'platform' => 'YouTube',
    'handle' => '@techguru123',
    'follower_count' => 50000
]);
echo "Response: $res\n";
$json = json_decode($res, true);
$id = $json['id'] ?? null;

if ($id) {
    // 2. List Influencers
    echo "\n[2] Listing Influencers...\n";
    $list = file_get_contents($baseUrl);
    echo "List Count: " . count(json_decode($list, true)['data']) . "\n";

    // 3. Update Status
    echo "\n[3] Updating Status...\n";
    $res = post($baseUrl . '?action=update_status', [
        'id' => $id,
        'status' => 'Vetted'
    ]);
    echo "Update Response: $res\n";
} else {
    echo "Failed to add influencer.\n";
}
