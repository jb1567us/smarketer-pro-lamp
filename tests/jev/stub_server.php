<?php
// Stub TypeSafe server for JevProvider behavioral tests.
// Run: php -S 127.0.0.1:PORT tests/jev/stub_server.php
// Scenario via query param: /systemone?scenario=ok
$tmp = sys_get_temp_dir() . '/jev_stub';
// Scenario is set by the test via a state file (the provider under test
// cannot send custom headers, so a query param would corrupt its URL).
$scenarioFile = $tmp . '/scenario';
$scenario = is_file($scenarioFile) ? trim(@file_get_contents($scenarioFile) ?: '') : '';
if ($scenario === '') { $scenario = $_GET['scenario'] ?? 'ok'; }

// Record the incoming request for assertions.
@mkdir($tmp, 0777, true);
$headers = function_exists('getallheaders') ? getallheaders() : [];
file_put_contents($tmp . '/last_request.json', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'authorization' => $headers['Authorization'] ?? $headers['authorization'] ?? null,
    'content_type' => $headers['Content-Type'] ?? $headers['content-type'] ?? null,
    'body' => file_get_contents('php://input'),
]));

switch ($scenario) {
    case 'ok':
        header('Content-Type: application/json');
        echo json_encode(['answers' => [
            'qualified' => ['noul' => 0.92, 'confidence' => 0.9],
        ]]);
        break;
    case 'lowconf':
        header('Content-Type: application/json');
        echo json_encode(['answers' => [
            'qualified' => ['noul' => 0.4, 'confidence' => 0.1],
        ]]);
        break;
    case 'unauthorized':
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid api key']);
        break;
    case 'validation':
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'question q1 malformed']);
        break;
    case 'ratelimit':
        // First hit 429, subsequent hits 200 — proves retry works.
        $counter = $tmp . '/ratelimit_count';
        $n = (int)@file_get_contents($counter);
        file_put_contents($counter, (string)($n + 1));
        if ($n === 0) {
            http_response_code(429);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'slow down']);
        } else {
            header('Content-Type: application/json');
            echo json_encode(['answers' => ['qualified' => ['noul' => 0.8, 'confidence' => 0.8]]]);
        }
        break;
    case 'servererror':
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'boom']);
        break;
    case 'badjson':
        header('Content-Type: text/html');
        echo '<html>not json</html>';
        break;
    case 'noanswers':
        header('Content-Type: application/json');
        echo json_encode(['foo' => 'bar']);
        break;
    default:
        http_response_code(404);
        echo 'unknown scenario';
}
