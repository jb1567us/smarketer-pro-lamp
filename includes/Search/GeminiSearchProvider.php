<?php

/**
 * GeminiSearchProvider
 * Uses Gemini's built-in Google Search tool capability for free search harvesting.
 */
class GeminiSearchProvider {
    private $apiKey;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public function getName() {
        return "Gemini Google Search (Free)";
    }

    public function search($query, $limit = 10) {
        if (!$this->apiKey) {
            throw new Exception("Gemini API key is missing for Search.");
        }

        $url = "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key={$this->apiKey}";
        
        $body = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => "Search for: {$query}. Return the top results as a JSON list of objects with 'title', 'link', and 'snippet'."]]]
            ],
            'tools' => [
                ['google_search_retrieval' => ['dynamic_retrieval_config' => ['mode' => 'DYNAMIC', 'dynamic_threshold' => 0.1]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Gemini Search API error: " . $response);
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '[]';
        
        $results = json_decode($text, true);
        
        // Normalize to standard harvester format
        $normalized = [];
        if (is_array($results)) {
            foreach (array_slice($results, 0, $limit) as $item) {
                $normalized[] = [
                    'title'   => $item['title'] ?? '',
                    'link'    => $item['link'] ?? '',
                    'snippet' => $item['snippet'] ?? '',
                    'source'  => 'gemini_google_search'
                ];
            }
        }

        return $normalized;
    }
}
