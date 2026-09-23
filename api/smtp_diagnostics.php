<?php
/**
 * Outbound SMTP Socket diagnostics API
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$host = trim($input['host'] ?? '');
$port = (int)($input['port'] ?? 587);
$user = trim($input['user'] ?? '');
$pass = trim($input['pass'] ?? '');
$encryption = strtolower(trim($input['encryption'] ?? 'tls'));

if (empty($host)) {
    echo json_encode(['success' => false, 'error' => 'SMTP Host is required']);
    exit;
}

$logs = [];
$logs[] = "[INFO] Initiating SMTP connection diagnostics to {$host}:{$port} (Encryption: {$encryption})";

$socketHost = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
$errno = 0;
$errstr = '';

$logs[] = "[INFO] Attempting TCP socket connection to {$socketHost}:{$port}...";
$start = microtime(true);
$socket = @fsockopen($socketHost, $port, $errno, $errstr, 8);
$duration = round((microtime(true) - $start) * 1000);

if (!$socket) {
    $logs[] = "[FAIL] TCP Connection failed. Error: {$errstr} (Code: {$errno}) after {$duration}ms";
    $logs[] = "[SUGGESTION] This server likely has outgoing port {$port} blocked by its hosting firewall (CSF/iptables) or the SMTP host/port combination is incorrect.";
    echo json_encode(['success' => false, 'logs' => $logs, 'error' => 'TCP connection failed']);
    exit;
}

$logs[] = "[PASS] TCP Connection established in {$duration}ms";

function readSmtpResponse($socket, &$logs) {
    $response = "";
    while ($line = fgets($socket, 1024)) {
        $logs[] = "< " . trim($line);
        $response .= $line;
        if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
            break;
        }
    }
    return $response;
}

try {
    // 1. Welcome Greeting
    $res = readSmtpResponse($socket, $logs);
    if (strpos($res, '220') !== 0) {
        throw new Exception("Greeting mismatch (expected 220): " . trim($res));
    }

    // 2. EHLO Handshake
    $logs[] = "> EHLO SmarketerProDiagnostics";
    fwrite($socket, "EHLO SmarketerProDiagnostics\r\n");
    $res = readSmtpResponse($socket, $logs);
    if (strpos($res, '250') !== 0) {
        throw new Exception("EHLO Handshake failed (expected 250): " . trim($res));
    }

    // 3. STARTTLS Upgrade
    if ($encryption === 'tls') {
        $logs[] = "> STARTTLS";
        fwrite($socket, "STARTTLS\r\n");
        $res = readSmtpResponse($socket, $logs);
        if (strpos($res, '220') !== 0) {
            throw new Exception("STARTTLS command failed (expected 220): " . trim($res));
        }

        $logs[] = "[INFO] Upgrading socket connection to TLS/SSL encrypted stream...";
        $cryptoRes = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$cryptoRes) {
            throw new Exception("STARTTLS stream encryption failed to initialize.");
        }
        $logs[] = "[PASS] TLS stream successfully initialized";

        $logs[] = "> EHLO SmarketerProDiagnostics";
        fwrite($socket, "EHLO SmarketerProDiagnostics\r\n");
        $res = readSmtpResponse($socket, $logs);
        if (strpos($res, '250') !== 0) {
            throw new Exception("EHLO updated handshake failed (expected 250): " . trim($res));
        }
    }

    // 4. AUTH LOGIN
    if (!empty($user) && !empty($pass)) {
        $logs[] = "> AUTH LOGIN";
        fwrite($socket, "AUTH LOGIN\r\n");
        $res = readSmtpResponse($socket, $logs);
        if (strpos($res, '334') !== 0) {
            throw new Exception("AUTH LOGIN initialization failed (expected 334): " . trim($res));
        }

        $logs[] = "> [Base64 Username]";
        fwrite($socket, base64_encode($user) . "\r\n");
        $res = readSmtpResponse($socket, $logs);
        if (strpos($res, '334') !== 0) {
            throw new Exception("Username rejected by mail server (expected 334): " . trim($res));
        }

        $logs[] = "> [Base64 Password]";
        fwrite($socket, base64_encode($pass) . "\r\n");
        $res = readSmtpResponse($socket, $logs);
        if (strpos($res, '235') !== 0) {
            throw new Exception("Authentication credentials rejected (expected 235): " . trim($res));
        }
        $logs[] = "[PASS] Authentication succeeded!";
    } else {
        $logs[] = "[INFO] Skipping authentication (no username/password supplied).";
    }

    // 5. QUIT
    $logs[] = "> QUIT";
    fwrite($socket, "QUIT\r\n");
    readSmtpResponse($socket, $logs);

    fclose($socket);
    $logs[] = "[SUCCESS] Direct SMTP Diagnostics completed successfully! Your SMTP server is reachable and active.";
    echo json_encode(['success' => true, 'logs' => $logs]);

} catch (Exception $e) {
    @fclose($socket);
    $logs[] = "[FAIL] SMTP Diagnostics failed: " . $e->getMessage();
    echo json_encode(['success' => false, 'logs' => $logs, 'error' => $e->getMessage()]);
}
?>
