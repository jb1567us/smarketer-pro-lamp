<?php

require_once 'SearchProvider.php';

class VercelBridgeProvider implements SearchProvider {
    private $bridgeUrl;

    public function __construct($bridgeUrl) {
        // bridgeUrl should be the deployed Vercel URL (e.g., https://my-scraper.vercel.app/api)
        $this->bridgeUrl = rtrim($bridgeUrl, '/');
    }

    public function getName(): string {
        return 'Vercel Bridge (Remote Chrome)';
    }

    public function search(string $query, int $limit = 10): array {
        // The Vercel bridge is a scraper, not a search engine.
        // It takes a target URL.
        // If 'query' looks like a URL, scrape it.
        // If 'query' is keywords, we scrape a Search Engine (DuckDuckGo HTML).
        
        $targetUrl = $query;
        if (!filter_var($query, FILTER_VALIDATE_URL)) {
            $targetUrl = "https://html.duckduckgo.com/html/?q=" . urlencode($query);
        }

        $endpoint = $this->bridgeUrl . "?url=" . urlencode($targetUrl) . "&wait=2000";
        
        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Headless chrome takes time
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Vercel Bridge Error: HTTP $httpCode");
        }

        $json = json_decode($response, true);
        
        // Return structured as a search result
        // If we scraped a search engine, we would parse HTML here.
        // For now, we return the Page Title/Content as a single "Deep Scrape Result".
        
        return [[
            'title'   => $json['title'] ?? 'Deep Scrape Result',
            'url'     => $json['url'] ?? $targetUrl,
            'content' => substr($json['content'] ?? '', 0, 500) . '...',
            'score'   => null
        ]];
    }
}
