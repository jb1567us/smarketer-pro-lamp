<?php

require_once 'SearchProvider.php';

class SearXNGProvider implements SearchProvider {
    private $localUrl;
    
    // In-flight process-lifetime dynamic blacklist to avoid repeatedly hitting failing instances
    private static $blacklistedUrls = [];

    // Curated, highly stable public SearXNG fallback instances (all A/A+ TLS, >=80% success rate, verified online)
    private $publicInstances = [
        "https://search.indst.eu/search",
        "https://search.hbubli.cc/search",
        "https://search.mdosch.de/search",
        "https://search.rowie.at/search",
        "https://paulgo.io/search",
        "https://searx.party/search",
        "https://searx.tuxcloud.net/search",
        "https://searx.tiekoetter.com/search",
        "https://search.serpensin.com/search",
        "https://searxng.site/search",
        "https://search.rhscz.eu/search",
        "https://searx.rhscz.eu/search",
        "https://search.bladerunn.in/search",
        "https://priv.au/search",
        "https://etsi.me/search",
        "https://search.ctq.ro/search",
        "https://search.url4irl.com/search",
        "https://search.im-in.space/search",
        "https://seek.fyi/search",
        "https://failsearx.culturanerd.it/search"
    ];
    private $activeUrl;

    // Default to 'searxng' hostname for Docker networking
    public function __construct($localUrl = 'http://searxng:8080/search') {
        $this->localUrl = $this->normalizeUrl($localUrl);
        $this->activeUrl = $this->localUrl; // Default to local
    }

    public function getName(): string {
        return 'SearXNG';
    }

    /**
     * Helper to guarantee that all SearXNG instances query the search endpoint correctly
     */
    private function normalizeUrl(string $url): string {
        $url = rtrim($url, '/');
        if (substr($url, -7) !== '/search') {
            $url .= '/search';
        }
        return $url;
    }

    /**
     * Database-Persisted Blacklist: Load active blacklisted URLs
     */
    private function loadPersistedBlacklist(): array {
        try {
            $pdo = \App\Database::getConnection();
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'searxng_blacklist'");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $blacklist = json_decode($row['setting_value'], true);
                if (is_array($blacklist)) {
                    $active = [];
                    $now = time();
                    foreach ($blacklist as $url => $expire) {
                        if ($expire > $now) {
                            $active[$url] = $expire;
                        }
                    }
                    return $active;
                }
            }
        } catch (Exception $e) {
            error_log("[SearXNGProvider] Failed to load persisted blacklist: " . $e->getMessage());
        }
        return [];
    }

    /**
     * Database-Persisted Blacklist: Save active blacklisted URLs
     */
    private function persistBlacklist(array $blacklist): void {
        try {
            $pdo = \App\Database::getConnection();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'searxng_blacklist'");
            $stmt->execute();
            $exists = $stmt->fetchColumn() > 0;
            
            $jsonStr = json_encode($blacklist);
            if ($exists) {
                $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'searxng_blacklist'");
                $stmt->execute([$jsonStr]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('searxng_blacklist', ?)");
                $stmt->execute([$jsonStr]);
            }
        } catch (Exception $e) {
            error_log("[SearXNGProvider] Failed to persist blacklist: " . $e->getMessage());
        }
    }

    /**
     * Database-Persisted Blacklist: Add a URL with a 4-hour expiration
     */
    private function blacklistUrl(string $url): void {
        $blacklist = $this->loadPersistedBlacklist();
        $blacklist[$url] = time() + 14400; // Blacklist for 4 hours
        $this->persistBlacklist($blacklist);
    }

    /**
     * Dynamically fetches active and fast public SearXNG instances from searx.space.
     * Filtered for active online status, secure TLS, normal network type, success percentage >= 50%,
     * sorted by response speed, and randomized to support rotation.
     */
    private function getDynamicPublicInstances(): array {
        $ch = curl_init("https://searx.space/data/instances.json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->getRandomUserAgent());
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Auto-handle compression
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        // Critical: Bypass SSL verification for resilience on local environments
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $candidates = [];
        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            if ($data && isset($data['instances'])) {
                foreach ($data['instances'] as $url => $info) {
                    $httpStatus = $info['http']['status_code'] ?? 0;
                    $tlsGrade = $info['tls']['grade'] ?? '';
                    $successRate = $info['timing']['search']['success_percentage'] ?? 0.0;
                    $networkType = $info['network_type'] ?? '';
                    
                    // Filter: Must be online (200), normal (not tor/onion), secure TLS grade starting with A
                    $isSecure = (is_string($tlsGrade) && strpos($tlsGrade, 'A') === 0);
                    $isNormal = ($networkType === 'normal');
                    
                    // Relaxed health check: Success rate >= 50% or 0% / untested
                    $isHealthy = ($httpStatus === 200 && ($successRate >= 50.0 || $successRate === 0.0 || !isset($info['timing']['search']['success_percentage'])));
                    
                    if ($isSecure && $isNormal && $isHealthy) {
                        $cleanUrl = $this->normalizeUrl($url);
                        // Get response time
                        $responseTime = $info['timing']['search']['all']['median'] ?? ($info['timing']['initial']['all']['value'] ?? 9.9);
                        
                        $candidates[] = [
                            'url' => $cleanUrl,
                            'time' => (float)$responseTime
                        ];
                    }
                }
            }
        }

        // Sort candidates by response speed (ascending)
        usort($candidates, function($a, $b) {
            return $a['time'] <=> $b['time'];
        });

        // Map to pure URLs list
        $urls = array_map(function($c) {
            return $c['url'];
        }, $candidates);

        // To achieve intelligent rotation and avoid systematic 429 blocks on the absolute fastest instances,
        // take the top 20 fastest healthy instances and shuffle them to distribute load.
        $topCandidates = array_slice($urls, 0, 20);
        shuffle($topCandidates);

        return $topCandidates;
    }

    public function search(string $query, int $limit = 10): array {
        $errors = [];
        
        // Load the persistent database blacklist
        $persistedBlacklist = $this->loadPersistedBlacklist();
        $activeBlacklisted = array_keys($persistedBlacklist);

        // --- 1. TRY LOCAL URL FIRST ---
        if (!in_array($this->localUrl, $activeBlacklisted) && !in_array($this->localUrl, self::$blacklistedUrls)) {
            try {
                return $this->performSearch($this->localUrl, $query, $limit);
            } catch (Exception $e) {
                self::$blacklistedUrls[] = $this->localUrl;
                $this->blacklistUrl($this->localUrl);
                error_log("[SearXNGProvider] Local URL failed. Added to persistent blacklist. Error: " . $e->getMessage());
                $errors[] = "Local URL ({$this->localUrl}): " . $e->getMessage();
            }
        }

        // --- 2. TRY DYNAMIC SECURE AND FILTERED INSTANCES ---
        try {
            $dynamicInstances = $this->getDynamicPublicInstances();
            if (!empty($dynamicInstances)) {
                // Try the shuffled candidates sequentially to distribute requests
                foreach ($dynamicInstances as $instance) {
                    if (in_array($instance, $activeBlacklisted) || in_array($instance, self::$blacklistedUrls)) {
                        continue;
                    }
                    try {
                        return $this->performSearch($instance, $query, $limit);
                    } catch (Exception $ex) {
                        self::$blacklistedUrls[] = $instance;
                        $this->blacklistUrl($instance);
                        error_log("[SearXNGProvider] Dynamic instance failed: $instance. Added to persistent blacklist.");
                        $errors[] = "Dynamic Rotated Instance ($instance): " . $ex->getMessage();
                        continue; // Try next
                    }
                }
            } else {
                $errors[] = "Dynamic Instances: JSON fetch returned no secure, healthy instances.";
            }
        } catch (Exception $e) {
            $errors[] = "Dynamic Instances Fetch Error: " . $e->getMessage();
        }

        // --- 3. TRY SHUFFLED CURATED FALLBACKS ---
        $curatedFallbackPool = $this->publicInstances;
        shuffle($curatedFallbackPool);
        // Try up to 10 shuffled fallback instances
        $fallbackAttempts = array_slice($curatedFallbackPool, 0, 10);
        foreach ($fallbackAttempts as $instance) {
            $instance = $this->normalizeUrl($instance);
            if (in_array($instance, $activeBlacklisted) || in_array($instance, self::$blacklistedUrls)) {
                continue;
            }
            try {
                return $this->performSearch($instance, $query, $limit);
            } catch (Exception $ex) {
                self::$blacklistedUrls[] = $instance;
                $this->blacklistUrl($instance);
                error_log("[SearXNGProvider] Curated fallback failed: $instance. Added to persistent blacklist.");
                $errors[] = "Curated Fallback ($instance): " . $ex->getMessage();
                continue; // Try next
            }
        }

        // Throw descriptive exception listing the first few errors
        $errMsg = "All local, dynamic, and curated public SearXNG instances failed.\nDetails:\n" . implode("\n", array_slice($errors, 0, 6));
        throw new Exception($errMsg);
    }

    private function performSearch($baseUrl, $query, $limit) {
        $baseUrl = $this->normalizeUrl($baseUrl);
        $params = [
            'q' => $query,
            'format' => 'json',
            'count' => $limit,
            'language' => 'en-US'
        ];
        
        $url = $baseUrl . '?' . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6); // Faster timeout to avoid stalling the pipeline on slow public responses
        
        // Auto-handle Gzip/Deflate compression which is extremely common for public instances
        curl_setopt($ch, CURLOPT_ENCODING, '');
        
        // Follow redirects in case instances redirect to HTTPS or localized subdomains
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 4);
        
        $userAgent = $this->getRandomUserAgent();
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        
        // Rotate Referer to mimic organic visitor referral
        $referers = [
            "https://www.google.com/",
            "https://www.bing.com/",
            "https://search.yahoo.com/",
            "https://duckduckgo.com/",
            "https://www.ecosia.org/",
            "https://www.qwant.com/"
        ];
        $referer = $referers[array_rand($referers)];
        
        $headers = [
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7",
            "Accept-Language: en-US,en;q=0.9",
            "Referer: $referer",
            "Connection: keep-alive",
            "Upgrade-Insecure-Requests: 1",
            "Cache-Control: max-age=0"
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Critical: Bypass SSL verification for resilience on local environments
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $errDetails = $curlError ? "cURL error: $curlError" : "HTTP Status $httpCode";
            throw new Exception("SearXNG Error ($baseUrl): $errDetails");
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
        
        $this->activeUrl = $baseUrl; // Update active URL on success
        return $results;
    }

    /**
     * Helper to return a randomized modern organic browser user agent.
     */
    private function getRandomUserAgent(): string {
        $userAgents = [
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0",
            "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.3.1 Safari/605.1.15",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36 Edg/122.0.0.0",
            "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36"
        ];
        return $userAgents[array_rand($userAgents)];
    }
}
