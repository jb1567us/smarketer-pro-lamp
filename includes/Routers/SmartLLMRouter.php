<?php

declare(strict_types=1);

namespace App\Routers;

use App\Database;
use App\Exceptions\OutreachException;

class SmartLLMRouter
{
    private \App\PDO $pdo;
    private array $blacklist = []; // Runtime blacklist [provider => expiry_timestamp]

    public function __construct(\App\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param string $payload The full text prompt
     * @param string $preferredTier 'performance' or 'economy'
     * @param bool $forceJson Whether to instruct the provider to return JSON
     * @return array Decoded JSON response
     * @throws OutreachException
     */
    public function generate(string $payload, string $preferredTier = 'performance', bool $forceJson = false, float $temperature = 0.7): array
    {
        $tiers = [
            'performance' => ['openai', 'anthropic', 'gemini'],
            'economy'     => ['groq', 'openrouter', 'gemini']
        ];

        if (!isset($tiers[$preferredTier])) {
            $preferredTier = 'performance';
        }

        // Check if user has an active provider preference from dashboard
        $activeProvider = Database::getSetting('active_llm_provider');
        
        $fallbackTier = $preferredTier === 'performance' ? 'economy' : 'performance';

        // If user has an explicit preference, try that provider first
        if ($activeProvider && !$this->isBlacklisted($activeProvider)) {
            $res = $this->callProvider($activeProvider, $payload, $forceJson, $temperature);
            if ($res['success']) return $res['data'];
            $this->blacklistProvider($activeProvider);
        }

        // Try Preferred Tier
        foreach ($tiers[$preferredTier] as $provider) {
            if ($this->isBlacklisted($provider)) continue;

            $res = $this->callProvider($provider, $payload, $forceJson, $temperature);
            if ($res['success']) return $res['data'];

            $this->blacklistProvider($provider);
        }

        // Fallback to Alternate Tier
        foreach ($tiers[$fallbackTier] as $provider) {
            if ($this->isBlacklisted($provider)) continue;

            $res = $this->callProvider($provider, $payload, $forceJson, $temperature);
            if ($res['success']) return $res['data'];

            $this->blacklistProvider($provider);
        }

        throw new OutreachException("All LLM providers failed to generate a response.");
    }

    private function isBlacklisted(string $provider): bool
    {
        if (isset($this->blacklist[$provider])) {
            if (time() < $this->blacklist[$provider]) {
                return true;
            }
            unset($this->blacklist[$provider]);
        }
        return false;
    }

    private function blacklistProvider(string $provider): void
    {
        $this->blacklist[$provider] = time() + 900;
        error_log("[SmartLLMRouter] Blacklisting {$provider} for 15 mins.");
    }

    private function callProvider(string $provider, string $payload, bool $forceJson, float $temperature): array
    {
        $rawKeys = Database::getSetting("{$provider}_api_key");
        
        $rotator = new SmartRotationManager($this->pdo);
        $apiKey = $rotator->selectActiveKey($provider, $rawKeys);
        $apiKey = trim($apiKey ?? '', " \t\n\r\0\x0B\"'");

        if (!$apiKey) {
            return ['success' => false, 'error' => 'Missing Key'];
        }

        switch ($provider) {
            case 'gemini':
                $res = $this->callGemini($apiKey, $payload, $forceJson, $temperature);
                break;
            case 'groq':
                $res = $this->callGroq($apiKey, $payload, $forceJson, $temperature);
                break;
            case 'openai':
                $res = $this->callOpenAI($apiKey, $payload, $forceJson, $temperature);
                break;
            case 'anthropic':
                $res = $this->callAnthropic($apiKey, $payload, $forceJson, $temperature);
                break;
            case 'openrouter':
                $res = $this->callOpenRouter($apiKey, $payload, $forceJson, $temperature);
                break;
            default:
                return ['success' => false, 'error' => 'Unknown provider'];
        }

        if ($res['success']) {
            $rotator->logCall($provider, $apiKey, 'success');
        } else {
            $rotator->logCall($provider, $apiKey, 'failed', $res['error'] ?? 'Request failed');
        }

        return $res;
    }

    // -------------------------------------------------------------------------
    // Provider implementations
    // -------------------------------------------------------------------------

    private function callGemini(string $apiKey, string $payload, bool $forceJson, float $temperature): array
    {
        $url = "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key={$apiKey}";

        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $payload]]]
            ],
            'generationConfig' => [
                'temperature' => $temperature,
                'maxOutputTokens' => 2048,
            ]
        ];

        if ($forceJson) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }

        $response = $this->curlPost($url, $body);

        if (!$response['ok']) {
            error_log("[SmartLLMRouter] Gemini error: " . $response['body']);
            return ['success' => false, 'error' => 'Gemini request failed'];
        }

        $data = json_decode($response['body'], true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if ($text === null) {
            return ['success' => false, 'error' => 'Gemini empty response'];
        }

        if ($forceJson) {
            $parsed = json_decode($text, true);
            return ['success' => true, 'data' => $parsed ?? ['raw' => $text]];
        }

        return ['success' => true, 'data' => ['response' => $text, 'provider' => 'gemini']];
    }

    private function callGroq(string $apiKey, string $payload, bool $forceJson, float $temperature): array
    {
        $url = 'https://api.groq.com/openai/v1/chat/completions';

        $body = [
            'model'    => 'llama-3.1-8b-instant',
            'messages' => [['role' => 'user', 'content' => $payload]],
            'temperature' => $temperature,
        ];

        if ($forceJson) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->curlPost($url, $body, ['Authorization: Bearer ' . $apiKey]);

        if (!$response['ok']) {
            error_log("[SmartLLMRouter] Groq error: " . $response['body']);
            return ['success' => false, 'error' => 'Groq request failed'];
        }

        $data = json_decode($response['body'], true);
        $text = $data['choices'][0]['message']['content'] ?? null;

        if ($text === null) {
            return ['success' => false, 'error' => 'Groq empty response'];
        }

        if ($forceJson) {
            $parsed = json_decode($text, true);
            return ['success' => true, 'data' => $parsed ?? ['raw' => $text]];
        }

        return ['success' => true, 'data' => ['response' => $text, 'provider' => 'groq']];
    }

    private function callOpenAI(string $apiKey, string $payload, bool $forceJson, float $temperature): array
    {
        $url = 'https://api.openai.com/v1/chat/completions';

        $body = [
            'model'    => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $payload]],
            'temperature' => $temperature,
        ];

        if ($forceJson) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->curlPost($url, $body, [
            'Authorization: Bearer ' . $apiKey,
            'OpenAI-Beta: assistants=v2'
        ]);

        if (!$response['ok']) {
            error_log("[SmartLLMRouter] OpenAI error: " . $response['body']);
            return ['success' => false, 'error' => 'OpenAI request failed'];
        }

        $data = json_decode($response['body'], true);
        $text = $data['choices'][0]['message']['content'] ?? null;

        if ($text === null) {
            return ['success' => false, 'error' => 'OpenAI empty response'];
        }

        if ($forceJson) {
            $parsed = json_decode($text, true);
            return ['success' => true, 'data' => $parsed ?? ['raw' => $text]];
        }

        return ['success' => true, 'data' => ['response' => $text, 'provider' => 'openai']];
    }

    private function callAnthropic(string $apiKey, string $payload, bool $forceJson, float $temperature): array
    {
        $url = 'https://api.anthropic.com/v1/messages';

        $body = [
            'model'      => 'claude-3-haiku-20240307',
            'max_tokens' => 2048,
            'messages'   => [['role' => 'user', 'content' => $payload]],
            'temperature' => $temperature,
        ];

        $response = $this->curlPost($url, $body, [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ]);

        if (!$response['ok']) {
            error_log("[SmartLLMRouter] Anthropic error: " . $response['body']);
            return ['success' => false, 'error' => 'Anthropic request failed'];
        }

        $data = json_decode($response['body'], true);
        $text = $data['content'][0]['text'] ?? null;

        if ($text === null) {
            return ['success' => false, 'error' => 'Anthropic empty response'];
        }

        if ($forceJson) {
            $parsed = json_decode($text, true);
            return ['success' => true, 'data' => $parsed ?? ['raw' => $text]];
        }

        return ['success' => true, 'data' => ['response' => $text, 'provider' => 'anthropic']];
    }

    private function callOpenRouter(string $apiKey, string $payload, bool $forceJson, float $temperature): array
    {
        $url = 'https://openrouter.ai/api/v1/chat/completions';

        // Use a free model by default — can be configured
        $model = Database::getSetting('openrouter_model') ?: 'google/gemini-2.0-flash-exp:free';

        $body = [
            'model'    => $model,
            'messages' => [['role' => 'user', 'content' => $payload]],
            'temperature' => $temperature,
        ];

        if ($forceJson) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->curlPost($url, $body, [
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://smarketer-pro.com',
            'X-Title: Smarketer Pro'
        ]);

        if (!$response['ok']) {
            error_log("[SmartLLMRouter] OpenRouter error: " . $response['body']);
            return ['success' => false, 'error' => 'OpenRouter request failed'];
        }

        $data = json_decode($response['body'], true);
        $text = $data['choices'][0]['message']['content'] ?? null;

        if ($text === null) {
            return ['success' => false, 'error' => 'OpenRouter empty response'];
        }

        if ($forceJson) {
            $parsed = json_decode($text, true);
            return ['success' => true, 'data' => $parsed ?? ['raw' => $text]];
        }

        return ['success' => true, 'data' => ['response' => $text, 'provider' => 'openrouter']];
    }

    // -------------------------------------------------------------------------
    // Shared cURL helper
    // -------------------------------------------------------------------------

    private function curlPost(string $url, array $body, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        $headers = array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $extraHeaders
        );

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("[SmartLLMRouter] cURL error: {$curlError}");
            return ['ok' => false, 'body' => $curlError];
        }

        return [
            'ok'   => $httpCode >= 200 && $httpCode < 300,
            'body' => $responseBody,
            'code' => $httpCode,
        ];
    }
}
