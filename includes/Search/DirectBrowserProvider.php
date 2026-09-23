<?php

require_once 'SearchProvider.php';

class DirectBrowserProvider implements SearchProvider {
    
    public function getName(): string {
        return 'Direct Browser (Headless)';
    }

    public function search(string $query, int $limit = 10): array {
        // In a real LAMP environment, we would use symfony/panther or puppeteer-php here.
        // For this port, since we might not have Chrome installed in the PHP container,
        // we will fallback to a basic scraping method or simulation if headers are missing.
        
        // HOWEVER, the original Python tool used 'direct_browser.py'.
        // We can shell_exec out to the original Python script if it's available?
        // Or we can try to scrape Google directly with generic headers (risky but functional for demo).
        
        // Let's implement a basic "unauthentic" scrape for now, but mark it as a place 
        // where we would integrate `facebook/php-webdriver`.
        
        $url = "https://www.google.com/search?q=" . urlencode($query) . "&num=" . $limit;
        
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

        // Report failure to ProxyManager if cURL failed or returned proxy block codes (e.g. 407, 0)
        if ($proxyUrl && ($html === false || $httpCode === 0 || $httpCode === 407 || $httpCode === 403)) {
            $pm->reportFailure($proxyUrl);
        }

        curl_close($ch);

        if (($httpCode < 200 || $httpCode >= 300) || !$html) {
             throw new Exception("Direct Search Failed: HTTP $httpCode" . ($proxyUrl ? " (Using Proxy: $proxyUrl)" : ""));
        }

        // Robust regex-based Google search result parser
        $results = [];
        preg_match_all('/<a[^>]*href="([^"]+)"[^>]*><h3[^>]*>(.*?)<\/h3>/is', $html, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            if (count($results) >= $limit) break;
            
            $link = $match[1];
            $title = trim(strip_tags($match[2]));
            
            // Clean Google URL garbage
            if (strpos($link, '/url?q=') === 0) {
                $parts = parse_url($link);
                parse_str($parts['query'] ?? '', $queryParts);
                $link = $queryParts['q'] ?? $link;
            }
            
            // Basic check to ignore internal google pages
            if (strpos($link, 'google.com') !== false || strpos($link, 'webcache.googleusercontent.com') !== false) {
                continue;
            }
            
            $results[] = [
                'title' => $title,
                'url' => $link,
                'content' => '',
                'score' => null
            ];
        }

        return $results;
    }
}
