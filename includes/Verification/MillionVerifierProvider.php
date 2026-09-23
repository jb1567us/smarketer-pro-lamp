<?php

declare(strict_types=1);

namespace App\Verification;

/**
 * MillionVerifier real-time email verification adapter.
 *
 * WHY MILLIONVERIFIER (chosen over Verifalia, AbstractAPI, Emailable, Hunter):
 *  - API simplicity: a single GET
 *      https://api.millionverifier.com/api/v3?api=<key>&email=<email>&timeout=<2-60>
 *    with the API key as a plain query parameter. No auth headers, no
 *    job-submission/polling (Verifalia v2.6), no bulk-upload workflow.
 *  - Response semantics map 1:1 onto our four states:
 *      result=ok        -> valid
 *      result=invalid    -> invalid
 *      result=disposable -> invalid (burner addresses hard-bounce)
 *      result=catch_all  -> risky  (domain accepts everything; can't confirm mailbox)
 *      result=unknown|error -> unknown (infrastructure failure / greylisting)
 *  - Cost: free signup credits (~100 lookups) for evaluation, then roughly
 *    $39 per 10,000 credits — the cheapest per-verification rate among the
 *    evaluated candidates, so leaving the gate on is affordable.
 *  - Docs: public API page with result-code table, official PHP sample, and a
 *    no-credit test key ("API_KEY_FOR_TEST") for dry-running the integration.
 *  - A credits-balance endpoint (/api/v3/credits?api=KEY) exists for future
 *    low-balance monitoring.
 *
 * The API key is supplied at construction time only; it is never logged,
 * never written to the raw audit payload (the key is stripped before the raw
 * response is stored), and must live in settings/ENV, never in code.
 *
 * Robustness: 10s connect + 10s total cURL timeouts; ANY transport failure
 * (DNS, connect, timeout, non-2xx, bad JSON, missing result field) yields an
 * 'unknown' result. This adapter never throws to the caller on outage.
 */
class MillionVerifierProvider implements EmailVerificationProvider
{
    private const ENDPOINT = 'https://api.millionverifier.com/api/v3';
    private const CONNECT_TIMEOUT = 10;
    private const TOTAL_TIMEOUT = 10;

    public function __construct(private readonly string $apiKey)
    {
        if (trim($this->apiKey) === '') {
            throw new \InvalidArgumentException('MillionVerifierProvider requires a non-empty API key.');
        }
    }

    public function name(): string
    {
        return 'millionverifier';
    }

    public function verify(string $email): EmailVerificationResult
    {
        $email = strtolower(trim($email));
        $now = new \DateTimeImmutable();

        // Fail fast locally on syntax errors — no credit spent, no network call.
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new EmailVerificationResult(EmailVerificationResult::INVALID, $this->name(), $now, [
                'note' => 'Rejected locally: address fails RFC syntax validation.',
                'result' => 'invalid',
            ]);
        }

        $response = $this->callApi($email);
        if ($response === null) {
            // $response null = transport-level failure (logged inside callApi).
            return new EmailVerificationResult(EmailVerificationResult::UNKNOWN, $this->name(), $now, [
                'note' => 'Provider unreachable or unparseable response.',
            ]);
        }

        $result = strtolower(trim((string)($response['result'] ?? '')));
        // Never leak the key into the audit payload.
        $raw = $response;
        unset($raw['api'], $raw['key'], $raw['api_key']);

        $status = match ($result) {
            'ok' => EmailVerificationResult::VALID,
            'invalid' => EmailVerificationResult::INVALID,
            // Burner domains: real today, dead tomorrow, guaranteed hard bounces.
            'disposable' => EmailVerificationResult::INVALID,
            // Domain accepts everything; the mailbox itself can't be confirmed.
            'catch_all', 'catchall' => EmailVerificationResult::RISKY,
            // 'unknown', 'error', or anything unrecognized: provider-side
            // uncertainty, never a verdict on the address.
            default => EmailVerificationResult::UNKNOWN,
        };

        return new EmailVerificationResult($status, $this->name(), $now, $raw);
    }

    /**
     * One synchronous GET to the v3 endpoint. Returns the decoded payload, or
     * null on ANY failure (network, HTTP status, JSON, missing result field).
     */
    private function callApi(string $email): ?array
    {
        $url = self::ENDPOINT . '?' . http_build_query([
            'api' => $this->apiKey,
            'email' => $email,
            'timeout' => 8, // provider-side SMTP probe budget (2-60s)
        ]);

        $ch = curl_init();
        if ($ch === false) {
            error_log('[MillionVerifier] curl_init failed');
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'smarketer-pro-lamp/verification-gate',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            error_log("[MillionVerifier] transport failure for {$email}: curl errno {$errno}");
            return null;
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("[MillionVerifier] HTTP {$httpCode} for {$email}");
            return null;
        }
        $data = json_decode((string)$body, true);
        if (!is_array($data) || !isset($data['result'])) {
            error_log("[MillionVerifier] unparseable response for {$email}: " . substr((string)$body, 0, 120));
            return null;
        }
        return $data;
    }
}
