<?php
/**
 * Shadow-mode proof stub: serves whatever response JSON the proof script
 * writes to the script file. Lets each fixture control the Jev answer.
 */
$tmp = sys_get_temp_dir() . '/jev_shadow_proof';
$script = $tmp . '/next_response.json';
@mkdir($tmp, 0777, true);

if (isset($_GET['__ping'])) { echo 'pong'; exit; }

$raw = is_file($script) ? @file_get_contents($script) : '';
$spec = json_decode($raw ?: '{}', true) ?: [];

$status = (int)($spec['status'] ?? 200);
http_response_code($status);
header('Content-Type: application/json');
if ($status === 200) {
    echo json_encode(['answers' => $spec['answers'] ?? []]);
} else {
    echo json_encode(['error' => $spec['error'] ?? 'stub error']);
}
