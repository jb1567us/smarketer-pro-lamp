<?php

/**
 * DuckDuckGoProvider
 * Scraping-based search provider for high-volume free harvesting.
 * Uses DDG Lite (HTML) to avoid JS and API keys.
 */
require_once 'SearchProvider.php';

class DuckDuckGoProvider implements SearchProvider {
    public function __construct($unused = null) {
        // No key needed
    }

    public function getName(): string {
        return "DuckDuckGo (Free / Mass)";
    }

    public function search(string $query, int $limit = 50): array {
        $results = [];
        $queryEncoded = urlencode($query);
        
        // Use the 'html' version for easy parsing
        $url = "https://html.duckduckgo.com/html/?q=" . $queryEncoded;

        $pdo = \App\Database::getConnection();
        require_once __DIR__ . '/../ProxyManager.php';
        $pm = new ProxyManager($pdo);
        $proxyUrl = $pm->getProxy();

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: max-age=0',
                'Sec-Ch-Ua: "Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
                'Sec-Ch-Ua-Mobile: ?0',
                'Sec-Ch-Ua-Platform: "Windows"',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: none',
                'Sec-Fetch-User: ?1',
                'Upgrade-Insecure-Requests: 1'
            ]
        ];

        if ($proxyUrl) {
            $cleanProxy = preg_replace('/^https?:\/\//i', '', $proxyUrl);
            $parts = explode(':', $cleanProxy);
            
            if (count($parts) >= 4) {
                $options[CURLOPT_PROXY] = $parts[0] . ':' . $parts[1];
                $options[CURLOPT_PROXYUSERPWD] = $parts[2] . ':' . $parts[3];
            } else {
                $options[CURLOPT_PROXY] = $cleanProxy;
            }
        }

        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($html === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception("DuckDuckGo cURL failed: " . $err);
        }

        // Report failure to ProxyManager if cURL failed or returned proxy block codes (e.g. 407, 0)
        if ($proxyUrl && ($httpCode === 0 || $httpCode === 407 || $httpCode === 403)) {
            $pm->reportFailure($proxyUrl);
        }

        curl_close($ch);

        // DuckDuckGo Lite uses 202 for CAPTCHA challenge pages. Any non-200 is an error/block.
        if ($httpCode !== 200) {
            $isCaptcha = (
                stripos($html, 'captcha') !== false ||
                stripos($html, 'robot') !== false ||
                stripos($html, 'human') !== false ||
                stripos($html, 'verification') !== false ||
                stripos($html, 'challenge') !== false ||
                stripos($html, 'unusual traffic') !== false
            );
            $msg = $isCaptcha ? "DuckDuckGo blocked by CAPTCHA/Challenge (HTTP {$httpCode})" : "DuckDuckGo returned non-200 HTTP status: {$httpCode}";
            throw new \Exception($msg);
        }

        // Inspect body content for bot prevention/CAPTCHA keywords
        $captchaKeywords = [
            'ddg-captcha',
            'captcha',
            'bots use duckduckgo too',
            'confirm this search was made by a human',
            'robot',
            'unusual traffic',
            'blocked'
        ];
        foreach ($captchaKeywords as $keyword) {
            if (stripos($html, $keyword) !== false) {
                throw new \Exception("DuckDuckGo blocked by CAPTCHA/Bot protection (found '{$keyword}' in body)");
            }
        }

        // Explode-based robust parsing for DDG HTML (no DOMDocument dependency)
        $blocks = explode('<div class="result ', $html);
        array_shift($blocks); // Remove header block
        
        error_log("[DuckDuckGo Debug] Exploded blocks found: " . count($blocks));
        if (count($blocks) === 0) {
            error_log("[DuckDuckGo Debug] Raw HTML snippet:\n" . htmlspecialchars(substr($html, 0, 800)));
            
            // Organic "no results" page should say something like "No results."
            $isOrganicZero = (
                stripos($html, 'no results') !== false ||
                stripos($html, 'no matches') !== false ||
                stripos($html, 'did not match any documents') !== false ||
                stripos($html, 'did not find any results') !== false
            );
            if (!$isOrganicZero) {
                throw new \Exception("DuckDuckGo returned 0 results without standard 'no results' message (possible captcha/parsing failure).");
            }
        }
        
        foreach ($blocks as $block) {
            if (count($results) >= $limit) break;
            
            if (preg_match('/<a[^>]*class="result__a"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', $block, $aMatch)) {
                $link = $this->cleanUrl($aMatch[1]);
                $title = trim(strip_tags($aMatch[2]));
                
                $snippet = '';
                if (preg_match('/<a[^>]*class="result__snippet"[^>]*>(.*?)<\/a>/is', $block, $snippetMatch)) {
                    $snippet = trim(strip_tags($snippetMatch[1]));
                }
                
                $results[] = [
                    'title'   => $title,
                    'link'    => $link,
                    'url'     => $link,
                    'snippet' => $snippet,
                    'content' => $snippet,
                    'source'  => 'ddg'
                ];
            }
        }

        return $results;
    }

    private function cleanUrl($url) {
        // Support protocol-relative URLs
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }
        
        $parts = parse_url($url);
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            if (isset($query['uddg'])) {
                return urldecode($query['uddg']);
            }
            if (isset($query['u'])) {
                return urldecode($query['u']);
            }
        }
        return $url;
    }
}
