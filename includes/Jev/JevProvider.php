<?php

declare(strict_types=1);

namespace App\Jev;

/**
 * JevProvider — TypeSafe AI "Jev" System One decision model.
 *
 * Jev is NOT a generative LLM. It takes program state + typed questions and
 * returns typed, calibrated decisions in a single parallel pass (~70-500ms).
 *
 * Endpoint:  POST https://api.typesafe.ai/v1/systemone
 * Auth:      Authorization: Bearer <TYPESAFE_API_KEY>
 * Body:      {"model": "jev-latest", "state": <str|dict|list>, "questions": {...}}
 * Response:  {"answers": {...}, "usage": {...}}
 *
 * Question types:
 *   - noul:   {"type": "noul", "instructions": "..."}  -> {"noul": 0.0-1.0}
 *   - choice: {"type": "choice", "options": [...], "instructions": "...",
 *              "criteria": {opt: desc}}                -> {"choice": str, "probabilities": {...}, "confidence": 0-1}
 *   - score:  {"type": "score", "instructions": "...",
 *              "criteria": ["level0 desc", ..., "levelN desc"]}  (2-10 levels)
 *                                                       -> {"score": position 0.0-(N-1), "probabilities": {...}, "confidence": 0-1}
 *
 * Pricing (vendor-published, verify before forecasting): $0.042 / 1M input
 * tokens, output tokens free.
 *
 * Docs: https://docs.typesafe.ai/api
 *
 * PHP port of smarketer-pro's src/llm/jev.py. Uses cURL (matching the rest of
 * this codebase) instead of the requests library.
 */
class JevProvider
{
    public const DEFAULT_MODEL = 'jev-latest'; // pinned versions are retired by the vendor (jev-1.13 -> 404 as of 2026-09); re-check /v1/models periodically
    public const DEFAULT_BASE_URL = 'https://api.typesafe.ai/v1';
    public const SYSTEMONE_PATH = '/systemone';

    /** Retryable statuses per TypeSafe API reference (backoff on 429 and 529). */
    private const RETRYABLE = [429, 529];

    /** Approximate input budget guard: states larger than this are truncated. */
    private const MAX_STATE_CHARS = 120000;

    private string $apiKey;
    private string $model;
    private string $endpoint;
    private int $timeout;
    private int $maxRetries;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null,
        int $timeout = 30,
        int $maxRetries = 3
    ) {
        $apiKey = $apiKey ?: (getenv('TYPESAFE_API_KEY') ?: '');
        if ($apiKey === '') {
            throw new JevAuthException(
                'TYPESAFE_API_KEY is not set. Get a key at console.typesafe.ai, ' .
                'set the jev_api_key setting, or export TYPESAFE_API_KEY.'
            );
        }
        $this->apiKey = $apiKey;
        $this->model = $model ?: (getenv('JEV_MODEL') ?: self::DEFAULT_MODEL);
        $baseUrl = rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/');
        $this->endpoint = $baseUrl . self::SYSTEMONE_PATH;
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
    }

    // ------------------------------------------------------------------
    // Raw call
    // ------------------------------------------------------------------

    /**
     * One batched System One call. Questions are independent and evaluated
     * in parallel against the same state.
     *
     * @param array|string $state
     * @return array The "answers" dict.
     * @throws JevException
     */
    public function systemOne($state, array $questions): array
    {
        if (empty($questions)) {
            throw new JevValidationException('At least one question is required.');
        }

        $payload = [
            'model' => $this->model,
            'state' => self::truncateState($state),
            'questions' => $questions,
        ];
        $body = json_encode($payload);
        if ($body === false) {
            throw new JevValidationException('Failed to JSON-encode the Jev request payload.');
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $result = $this->post($body);

            if ($result['curl_error'] !== '') {
                if ($attempt <= $this->maxRetries) {
                    $this->backoff($attempt);
                    continue;
                }
                throw new JevException('Jev request failed: ' . $result['curl_error'], null, true);
            }

            $code = $result['code'];

            if ($code === 200) {
                $data = json_decode($result['body'], true);
                if (!is_array($data)) {
                    throw new JevException('Jev returned a non-JSON response.');
                }
                $answers = $data['answers'] ?? null;
                if (!is_array($answers)) {
                    throw new JevException("Jev response missing 'answers' map.");
                }
                return $answers;
            }

            if ($code === 401) {
                throw new JevAuthException('Jev rejected the API key (401).');
            }
            if ($code === 422) {
                throw new JevValidationException('Jev rejected the request (422): ' . substr($result['body'], 0, 500));
            }
            if (in_array($code, self::RETRYABLE, true) && $attempt <= $this->maxRetries) {
                error_log("[JevProvider] Jev {$code} — backing off (attempt {$attempt}/{$this->maxRetries}).");
                $this->backoff($attempt);
                continue;
            }

            throw new JevException(
                "Jev request failed with {$code}: " . substr($result['body'], 0, 500),
                $code,
                in_array($code, self::RETRYABLE, true)
            );
        }
    }

    // ------------------------------------------------------------------
    // Convenience builders
    // ------------------------------------------------------------------

    public static function noulQuestion(string $instructions): array
    {
        return ['type' => 'noul', 'instructions' => $instructions];
    }

    public static function choiceQuestion(string $instructions, array $options, ?array $criteria = null): array
    {
        $q = ['type' => 'choice', 'instructions' => $instructions, 'options' => array_values($options)];
        if ($criteria) {
            $q['criteria'] = $criteria;
        }
        return $q;
    }

    /**
     * Score question: position on an ordered spectrum described in words.
     * Criteria: 2-10 level descriptions, low end first (level 0) to high end.
     * The API returns `score` as a position that may land between levels;
     * convert it back to a 0-100 scale with scoreToPercent().
     *
     * @throws JevValidationException when criteria has fewer than 2 or more than 10 levels.
     */
    public static function scoreQuestion(string $instructions, array $criteria): array
    {
        $criteria = array_values($criteria);
        $n = count($criteria);
        if ($n < 2 || $n > 10) {
            throw new JevValidationException('Score criteria must have 2-10 ordered levels; got ' . $n . '.');
        }
        return ['type' => 'score', 'instructions' => $instructions, 'criteria' => $criteria];
    }

    /**
     * Convert a score answer's level position back to a 0-100 scale.
     */
    public static function scoreToPercent(float $position, int $levelCount): float
    {
        if ($levelCount < 2) {
            return 0.0;
        }
        $pct = $position / ($levelCount - 1) * 100;
        return max(0.0, min(100.0, round($pct, 1)));
    }

    // ------------------------------------------------------------------
    // Convenience askers
    // ------------------------------------------------------------------

    /**
     * @return array [decision_bool, probability]
     */
    public function askNoul($state, string $instructions, float $threshold = 0.5): array
    {
        $answers = $this->systemOne($state, ['q' => self::noulQuestion($instructions)]);
        $prob = (float)($answers['q']['noul'] ?? 0.0);
        return [$prob >= $threshold, $prob];
    }

    /**
     * @return array [choice, probabilities, confidence]
     */
    public function askChoice($state, string $instructions, array $options, ?array $criteria = null): array
    {
        $answers = $this->systemOne($state, ['q' => self::choiceQuestion($instructions, $options, $criteria)]);
        $a = $answers['q'] ?? [];
        return [$a['choice'] ?? null, $a['probabilities'] ?? [], (float)($a['confidence'] ?? 0.0)];
    }

    /**
     * @return array [score 0-100, confidence]
     */
    public function askScore($state, string $instructions, array $criteria): array
    {
        $answers = $this->systemOne($state, ['q' => self::scoreQuestion($instructions, $criteria)]);
        $a = $answers['q'] ?? [];
        $position = (float)($a['score'] ?? 0.0);
        return [self::scoreToPercent($position, count($criteria)), (float)($a['confidence'] ?? 0.0)];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function post(string $body): array
    {
        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            error_log('[JevProvider] cURL error: ' . $curlError);
        }

        return ['code' => $httpCode, 'body' => (string)$responseBody, 'curl_error' => $curlError];
    }

    private function backoff(int $attempt): void
    {
        // Exponential backoff: 2s, 4s, 8s ... (mirrors the Python client)
        $seconds = 2 ** $attempt;
        error_log("[JevProvider] Retrying in {$seconds}s (attempt {$attempt}).");
        sleep($seconds);
    }

    /**
     * Keep the request inside the vendor's state budget. Long strings are
     * truncated; arrays are JSON-encoded first.
     *
     * @param array|string $state
     * @return array|string
     */
    private static function truncateState($state)
    {
        if (is_array($state)) {
            $encoded = json_encode($state);
            if ($encoded !== false && strlen($encoded) > self::MAX_STATE_CHARS) {
                return substr($encoded, 0, self::MAX_STATE_CHARS) . '…[truncated]';
            }
            return $state;
        }
        if (is_string($state) && strlen($state) > self::MAX_STATE_CHARS) {
            return substr($state, 0, self::MAX_STATE_CHARS) . '…[truncated]';
        }
        return $state;
    }
}
