<?php

require_once 'SearchProvider.php';

class ScrapingAntProvider implements SearchProvider {
    private $apiKey;
    private $backupApiKey;
    private $baseUrl = 'https://api.scrapingant.com/v2/general';

    public function __construct($apiKey, $backupApiKey = '') {
        $this->apiKey = $apiKey ?? '';
        $this->backupApiKey = $backupApiKey ?? '';
    }

    public function getName(): string {
        return 'ScrapingAnt';
    }

    private function performRequest(string $targetUrl, ?string $key): array {
        $params = [
            'url' => $targetUrl,
            'x-api-key' => $key ?? '',
            'proxy_type' => 'datacenter', // Cheaper/Free tier
            'browser' => 'false' // Use 'true' for JS rendering (costs more credits)
        ];

        $url = $this->baseUrl . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'code' => $httpCode,
            'body' => $response
        ];
    }

    public function search(string $query, int $limit = 10): array {
        // Similar logic: query vs URL
        $targetUrl = $query;
        if (!filter_var($query, FILTER_VALIDATE_URL)) {
            $targetUrl = "https://www.google.com/search?q=" . urlencode($query);
        }

        // Try primary key
        $result = $this->performRequest($targetUrl, $this->apiKey);

        // If primary key fails, try backup key if provided
        if ($result['code'] !== 200 && !empty($this->backupApiKey)) {
            $result = $this->performRequest($targetUrl, $this->backupApiKey);
        }

        if ($result['code'] !== 200) {
            throw new Exception("ScrapingAnt Error: HTTP " . $result['code'] . " - " . substr($result['body'], 0, 150));
        }

        // Returns raw HTML string directly
        return [[
            'title'   => 'ScrapingAnt Result',
            'url'     => $targetUrl,
            'content' => substr(strip_tags($result['body']), 0, 500) . '...',
            'score'   => null
        ]];
    }
}
