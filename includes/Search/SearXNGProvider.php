<?php

require_once 'SearchProvider.php';

class SearXNGProvider implements SearchProvider {
    private $localUrl;
    private $publicInstances = [
        "https://searx.be/search",
        "https://searx.work/search",
        "https://priv.au/search"
    ];
    private $activeUrl;

    // Default to 'searxng' hostname for Docker networking
    public function __construct($localUrl = 'http://searxng:8080/search') {
        $this->localUrl = $localUrl;
        $this->activeUrl = $localUrl; // Default to local
    }

    public function getName(): string {
        return 'SearXNG';
    }

    public function search(string $query, int $limit = 10): array {
        // Try local first
        try {
            return $this->performSearch($this->localUrl, $query, $limit);
        } catch (Exception $e) {
            // Log warning?
            // Failover to public
            foreach ($this->publicInstances as $instance) {
                try {
                    return $this->performSearch($instance, $query, $limit);
                } catch (Exception $ex) {
                    continue; // Try next
                }
            }
            throw new Exception("All SearXNG instances failed.");
        }
    }

    private function performSearch($baseUrl, $query, $limit) {
        $params = [
            'q' => $query,
            'format' => 'json',
            'count' => $limit,
            'language' => 'en-US'
        ];
        
        $url = $baseUrl . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        // Important: User Agent to avoid blocking on public instances
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36");
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new Exception("SearXNG Error ($baseUrl): HTTP $httpCode");
        }

        $json = json_decode($response, true);
        if ($json === null || !isset($json['results'])) {
            throw new Exception("SearXNG Error ($baseUrl): Invalid JSON or missing results");
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
