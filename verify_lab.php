<?php
$url = 'http://localhost/api/agent_chat.php';
$data = [
    'persona' => 'B2B ICP Specialist',
    'context' => "Company: Golden Master Corp\nWebsite: \nContact: ",
    'instruction' => ''
];

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
    ],
];
$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

echo "Response:\n" . $result . "\n";
