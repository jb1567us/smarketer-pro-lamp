<?php

require_once 'SearchProvider.php';

class FirecrawlProvider implements SearchProvider {
    private $apiKey;
    private $backupApiKey;
    private $baseUrl = 'https://api.firecrawl.dev/v0/search';

    public function __construct($apiKey, $backupApiKey = '') {
        $this->apiKey = $apiKey;
        $this->backupApiKey = $backupApiKey;
    }

    public function getName(): string {
        return 'Firecrawl';
    }

    private function performRequest(string $query, int $limit, string $key): array {
        $data = [
            'query' => $query,
            'limit' => $limit,
            'pageOptions' => ['fetchPageContent' => false] // Faster
        ];

        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'code' => $httpCode,
            'body' => $response
        ];
    }

    public function search(string $query, int $limit = 10): array {
        // Try primary key
        $result = $this->performRequest($query, $limit, $this->apiKey);

        // If primary key fails, try backup key if provided
        if ($result['code'] !== 200 && !empty($this->backupApiKey)) {
            $result = $this->performRequest($query, $limit, $this->backupApiKey);
        }

        if ($result['code'] !== 200) {
            throw new Exception("Firecrawl API Error: HTTP " . $result['code'] . " - " . $result['body']);
        }

        $json = json_decode($result['body'], true);
        
        if (!isset($json['data'])) {
            return [];
        }

        $results = [];
        foreach ($json['data'] as $item) {
            $results[] = [
                'title' => $item['title'] ?? '',
                'url' => $item['url'] ?? '',
                'content' => $item['markdown'] ?? '', 
                'score' => null
            ];
        }

        return $results;
    }
}
