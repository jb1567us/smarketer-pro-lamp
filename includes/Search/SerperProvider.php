<?php

require_once 'SearchProvider.php';

/**
 * SerperProvider
 * Search provider using Serper.dev API (Google Search API)
 */
class SerperProvider implements SearchProvider {
    private $apiKey;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function getName(): string {
        return "Serper.dev (Google)";
    }

    public function search(string $query, int $limit = 10): array {
        if (!$this->apiKey) {
            throw new Exception("Serper API key is missing.");
        }

        $url = "https://google.serper.dev/search";
        
        $payload = json_encode([
            'q' => $query,
            'num' => $limit
        ]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-API-KEY: " . $this->apiKey,
            "Content-Type: application/json"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            $msg = $err['message'] ?? "Serper API error (Code: $httpCode)";
            throw new Exception($msg);
        }

        $data = json_decode($response, true);
        $results = [];

        if (isset($data['organic'])) {
            foreach ($data['organic'] as $item) {
                $results[] = [
                    'title'   => $item['title'] ?? '',
                    'url'     => $item['link'] ?? '',
                    'content' => $item['snippet'] ?? '',
                    'link'    => $item['link'] ?? '',
                    'snippet' => $item['snippet'] ?? '',
                    'score'   => null,
                    'source'  => 'serper'
                ];
            }
        }

        return $results;
    }
}
