<?php

require_once 'SearchProvider.php';

class ExaProvider implements SearchProvider {
    private $apiKey;
    private $baseUrl = 'https://api.exa.ai/search';

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function getName(): string {
        return 'Exa';
    }

    public function search(string $query, int $limit = 10): array {
        $data = [
            'query' => $query,
            'numResults' => $limit,
            'useAutoprompt' => true // Uses Exa's LLM optimized querying
        ];

        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            // Exa returns error details in JSON
            throw new Exception("Exa API Error: HTTP $httpCode - $response");
        }

        $json = json_decode($response, true);
        if ($json === null || !isset($json['results'])) {
            throw new Exception("Exa API Error: Invalid JSON or missing results");
        }

        $results = [];
        foreach ($json['results'] as $item) {
            $results[] = [
                'title' => $item['title'] ?? '',
                'url' => $item['url'] ?? '',
                'content' => '', // Exa standard search doesn't always return full content
                'score' => $item['score'] ?? null
            ];
        }

        return $results;
    }
}
