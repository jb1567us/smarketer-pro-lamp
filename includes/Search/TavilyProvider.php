<?php

require_once 'SearchProvider.php';

class TavilyProvider implements SearchProvider {
    private $apiKey;
    private $baseUrl = 'https://api.tavily.com/search';

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function getName(): string {
        return 'Tavily';
    }

    public function search(string $query, int $limit = 10): array {
        $data = [
            'api_key' => $this->apiKey,
            'query' => $query,
            'search_depth' => 'basic',
            'include_answer' => false,
            'include_images' => false,
            'max_results' => $limit
        ];

        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Tavily API Error: HTTP $httpCode - $response");
        }

        $json = json_decode($response, true);
        if ($json === null || !isset($json['results'])) {
            throw new Exception("Tavily API Error: Invalid JSON or missing results");
        }

        $results = [];
        foreach ($json['results'] as $item) {
            $results[] = [
                'title' => $item['title'] ?? '',
                'url' => $item['url'] ?? '',
                'content' => $item['content'] ?? '',
                'score' => $item['score'] ?? null
            ];
        }

        return $results;
    }
}
