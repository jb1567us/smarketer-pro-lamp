<?php
/**
 * MillionVerifier connection test (ITEM 1).
 *
 * POST api/verification_test.php
 *   Body (JSON): { "api_key": "<optional unsaved key>", }
 *
 * Verifies a MillionVerifier API key server-side WITHOUT spending a
 * verification credit: it calls the provider's credits-balance endpoint
 * (GET /api/v3/credits?api=KEY) rather than verifying an address.
 *
 * Key resolution: an explicit "api_key" in the request body wins (so the
 * buyer can test a freshly typed key before saving it); otherwise the stored
 * `verification_api_key` setting is used. The endpoint never persists, never
 * logs, and never echoes the key back in any response.
 *
 * Response: { "success": true, "credits": <int|null>, "message": "..." }
 *           or { "success": false, "error": "..." }.
 * "credits" is null when the key is accepted but the balance could not be
 * parsed from the response.
 *
 * Security model: authenticated session + CSRF token required (same as the
 * settings API). Outbound call has tight timeouts and verifies TLS.
 */
declare(strict_types=1);

const VERIFICATION_TEST_CREDITS_ENDPOINT = 'https://api.millionverifier.com/api/v3/credits';
const VERIFICATION_TEST_CONNECT_TIMEOUT = 8;
const VERIFICATION_TEST_TOTAL_TIMEOUT = 12;

/**
 * Test a MillionVerifier key against the credits endpoint.
 *
 * @param callable|null $httpGet Stub for tests: fn(string $url): array{httpCode:int, body:string}
 * @return array{success:bool, credits:?int, message:string} — never contains the key.
 */
function testMillionVerifierKey(string $key, ?callable $httpGet = null): array
{
    $key = trim($key);
    if ($key === '') {
        return ['success' => false, 'credits' => null, 'message' => 'No MillionVerifier API key configured. Paste your key and try again.'];
    }

    $url = VERIFICATION_TEST_CREDITS_ENDPOINT . '?' . http_build_query(['api' => $key]);

    if ($httpGet === null) {
        $httpGet = function (string $u): array {
            $ch = curl_init();
            if ($ch === false) {
                return ['httpCode' => 0, 'body' => ''];
            }
            curl_setopt_array($ch, [
                CURLOPT_URL => $u,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => VERIFICATION_TEST_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => VERIFICATION_TEST_TOTAL_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => 'smarketer-pro-lamp/verification-test',
            ]);
            $body = curl_exec($ch);
            $errno = curl_errno($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($errno !== 0 || $body === false) {
                // Log transport failures WITHOUT the URL (it embeds the key).
                error_log('[verification_test] transport failure contacting MillionVerifier (curl errno ' . $errno . ')');
                return ['httpCode' => 0, 'body' => ''];
            }
            return ['httpCode' => $httpCode, 'body' => (string)$body];
        };
    }

    $res = $httpGet($url);
    $httpCode = (int)($res['httpCode'] ?? 0);
    $body = trim((string)($res['body'] ?? ''));

    if ($httpCode === 0) {
        return ['success' => false, 'credits' => null, 'message' => 'Could not reach MillionVerifier. Check that your server allows outbound HTTPS.'];
    }
    if ($httpCode === 401 || $httpCode === 403) {
        // Invalid key — log the fact, never the key.
        error_log('[verification_test] MillionVerifier rejected the supplied key (HTTP ' . $httpCode . ')');
        return ['success' => false, 'credits' => null, 'message' => 'MillionVerifier rejected this key. Double-check it and try again.'];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('[verification_test] MillionVerifier credits endpoint returned HTTP ' . $httpCode);
        return ['success' => false, 'credits' => null, 'message' => 'MillionVerifier returned an unexpected response (HTTP ' . $httpCode . '). Try again in a minute.'];
    }

    // 2xx: inspect the payload. MillionVerifier signals an invalid key with
    // HTTP 200 + {"result":"error","error":"apikey_not_found"}, so the status
    // line alone cannot be trusted. Extract the balance defensively — the
    // endpoint answers with either a bare number or JSON.
    $credits = null;
    if ($body !== '' && is_numeric($body)) {
        $credits = (int)$body;
    } else {
        $data = json_decode($body, true);
        if (is_array($data)) {
            $resultField = strtolower(trim((string)($data['result'] ?? '')));
            $errorField = trim((string)($data['error'] ?? ''));
            if ($resultField === 'error' || $errorField !== '') {
                // Key rejected — log the fact only, never the key or raw body.
                error_log('[verification_test] MillionVerifier rejected the supplied key');
                return ['success' => false, 'credits' => null, 'message' => 'MillionVerifier rejected this key. Double-check it and try again.'];
            }
            if (isset($data['credits']) && is_numeric($data['credits'])) {
                $credits = (int)$data['credits'];
            }
        }
    }

    return [
        'success' => true,
        'credits' => $credits,
        'message' => $credits !== null
            ? "Key accepted. Remaining MillionVerifier credits: {$credits}."
            : 'Key accepted by MillionVerifier. (Credit balance could not be read from the response.)',
    ];
}

if (!defined('VERIFICATION_TEST_UNIT')) {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../includes/autoload.php';
    \App\Auth::requireApiAuth();
    \App\Auth::requireCsrf();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    // Explicit key wins (lets the buyer test an unsaved key); otherwise fall
    // back to the stored setting. The stored value is never written to, and
    // the key never appears in any response or log.
    $key = trim((string)($input['api_key'] ?? ''));
    if ($key === '') {
        try {
            $key = trim((string)(\App\Database::getSetting('verification_api_key', '') ?? ''));
        } catch (\Throwable $e) {
            error_log('[verification_test] settings lookup failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Could not read stored settings.']);
            exit;
        }
    }

    $result = testMillionVerifierKey($key);
    echo json_encode($result);
}
