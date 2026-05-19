<?php
require_once __DIR__ . '/autoload.php';

/**
 * SimpleHarvester
 * Orchestrates search harvesting using the active SearchProvider.
 */
class SimpleHarvester {
    private $db;
    private $provider;
    private $loadedApiKey;

    public function __construct($customProviderName = null) {
        global $pdo;
        if (!isset($pdo)) {
            $pdo = \App\Database::getConnection();
        }
        $this->db = $pdo;
        $this->loadProvider($customProviderName);
    }

    private function loadProvider($customProviderName = null) {
        if ($customProviderName !== null && !empty($customProviderName)) {
            $providerName = $customProviderName;
        } else {
            // Load active provider from settings
            $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_search_provider'");
            $stmt->execute();
            $row = $stmt->fetch();
            $providerName = ($row ? $row['setting_value'] : null) ?: 'searxng';
        }

        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE '%_api_key%' OR setting_key = 'searxng_url'");
        $stmt->execute();
        $settings = $stmt->fetchAll(\App\PDO::FETCH_KEY_PAIR);

        $rotator = new \App\Routers\SmartRotationManager($this->db);

        switch ($providerName) {
            case 'tavily':
                require_once __DIR__ . '/Search/TavilyProvider.php';
                $key = $rotator->selectActiveKey('tavily', $settings['tavily_api_key'] ?? '');
                $this->loadedApiKey = $key;
                $this->provider = new TavilyProvider($key);
                break;
            case 'exa':
                require_once __DIR__ . '/Search/ExaProvider.php';
                $key = $rotator->selectActiveKey('exa', $settings['exa_api_key'] ?? '');
                $this->loadedApiKey = $key;
                $this->provider = new ExaProvider($key);
                break;
            case 'firecrawl':
                require_once __DIR__ . '/Search/FirecrawlProvider.php';
                $rawKeys = ($settings['firecrawl_api_key'] ?? '') . ',' . ($settings['firecrawl_api_key_backup'] ?? '');
                $key = $rotator->selectActiveKey('firecrawl', $rawKeys);
                $this->loadedApiKey = $key;
                $this->provider = new FirecrawlProvider($key, '');
                break;
            case 'searxng':
                require_once __DIR__ . '/Search/SearXNGProvider.php';
                $this->loadedApiKey = '';
                $this->provider = new SearXNGProvider($settings['searxng_url'] ?? 'http://localhost:8080/search');
                break;
            case 'scrapingant':
                require_once __DIR__ . '/Search/ScrapingAntProvider.php';
                $rawKeys = ($settings['scrapingant_api_key'] ?? '') . ',' . ($settings['scrapingant_api_key_backup'] ?? '');
                $key = $rotator->selectActiveKey('scrapingant', $rawKeys);
                $this->loadedApiKey = $key;
                $this->provider = new ScrapingAntProvider($key, '');
                break;
            case 'serper':
                require_once __DIR__ . '/Search/SerperProvider.php';
                $key = $rotator->selectActiveKey('serper', $settings['serper_api_key'] ?? '');
                $this->loadedApiKey = $key;
                $this->provider = new SerperProvider($key);
                break;
            case 'gemini':
                require_once __DIR__ . '/Search/GeminiSearchProvider.php';
                $key = $rotator->selectActiveKey('gemini', $settings['gemini_api_key'] ?? '');
                $this->loadedApiKey = $key;
                $this->provider = new GeminiSearchProvider($key);
                break;
            case 'ddg':
                require_once __DIR__ . '/Search/DuckDuckGoProvider.php';
                $this->loadedApiKey = '';
                $this->provider = new DuckDuckGoProvider();
                break;
            default:
                $this->loadedApiKey = '';
                $this->provider = null;
        }
    }

    /**
     * Harvests results for a given query.
     * @param string $query
     * @param int $limit
     * @return array
     */
    public function harvest($query, $limit = 50) {
        if (!$this->provider) {
            throw new Exception("No search provider loaded.");
        }

        $rotator = new \App\Routers\SmartRotationManager($this->db);
        $providerName = strtolower($this->provider->getName());

        try {
            $results = $this->provider->search($query, $limit);
            
            // Log successful request in the usage logs
            if (!empty($this->loadedApiKey)) {
                $rotator->logCall($providerName, $this->loadedApiKey, 'success');
            }

            // On success, reset consecutive failures
            $this->setConsecutiveFailures(0);
            
            return $results;
        } catch (Exception $e) {
            // Log failed request in the usage logs
            if (!empty($this->loadedApiKey)) {
                $rotator->logCall($providerName, $this->loadedApiKey, 'failed', $e->getMessage());
            }

            // Increment and check consecutive failures
            $failures = $this->incrementConsecutiveFailures();
            
            // Load failover settings
            $threshold = (int)($this->getSetting('failover_threshold') ?: 3);
            $fallback = $this->getSetting('fallback_search_provider') ?: 'searxng';
            
            if ($failures >= $threshold) {
                // Log and trigger failover
                $oldProviderName = $this->getActiveProviderName();
                
                // Update active search provider to fallback
                $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_search_provider', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$fallback, $fallback]);
                
                // Reset failures count
                $this->setConsecutiveFailures(0);
                
                // Reload provider
                $this->loadProvider();
                
                // Try once more with fallback provider
                if ($this->provider) {
                    try {
                        $results = $this->provider->search($query, $limit);
                        if (!empty($this->loadedApiKey)) {
                            $rotator->logCall(strtolower($this->provider->getName()), $this->loadedApiKey, 'success');
                        }
                        return $results;
                    } catch (Exception $fallbackEx) {
                        if (!empty($this->loadedApiKey)) {
                            $rotator->logCall(strtolower($this->provider->getName()), $this->loadedApiKey, 'failed', $fallbackEx->getMessage());
                        }
                        throw new Exception("Failover triggered from $oldProviderName to " . $this->getActiveProviderName() . " but fallback provider failed too: " . $fallbackEx->getMessage(), 0, $fallbackEx);
                    }
                }
            }
            
            throw $e;
        }
    }

    private function getSetting(string $key): string {
        $stmt = $this->db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : '';
    }

    private function setConsecutiveFailures(int $count) {
        $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('search_consecutive_failures', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([(string)$count, (string)$count]);
    }

    private function incrementConsecutiveFailures(): int {
        $current = (int)$this->getSetting('search_consecutive_failures');
        $next = $current + 1;
        $this->setConsecutiveFailures($next);
        return $next;
    }

    public function getActiveProviderName() {
        return $this->provider ? $this->provider->getName() : 'Unknown';
    }

    /**
     * Stage harvested results into the leads table as 'New' entries.
     * Prevents duplicates by domain mapping.
     */
    public static function stageResults($db, array $results, string $query, ?int $campaignId, string $leadPersona): array {
        $stagedCount = 0;
        $duplicatesCount = 0;

        foreach ($results as $item) {
            $url = $item['url'] ?? '';
            if (empty($url)) continue;

            // Extract clean domain
            $parsedUrl = parse_url($url);
            $host = isset($parsedUrl['host']) ? strtolower($parsedUrl['host']) : '';
            $hostClean = preg_replace('/^www\./', '', $host);
            if (empty($hostClean)) continue;

            // Check for duplicates in leads table
            $checkStmt = $db->prepare("SELECT id FROM leads WHERE website LIKE ? OR email LIKE ?");
            $checkStmt->execute(["%{$hostClean}%", "%{$hostClean}%"]);
            if ($checkStmt->fetch()) {
                $duplicatesCount++;
                continue;
            }

            // Parse a friendly company name from the title or domain
            $title = $item['title'] ?? '';
            $companyName = '';
            if ($title) {
                $parts = preg_split('/[-|–|—]/', $title);
                $companyName = trim($parts[0]);
            }
            if (empty($companyName) || strlen($companyName) < 3) {
                $domainParts = explode('.', $hostClean);
                $companyName = ucfirst($domainParts[0]);
            }

            // Unique placeholder email
            $placeholderEmail = 'pending_' . md5($url) . '@placeholder.com';

            // Notes field with metadata
            $notes = "Harvested from: " . $url . "\nSnippet: " . ($item['content'] ?? '') . "\nQuery: " . $query;
            if (!empty($leadPersona)) {
                $notes .= "\nTarget Persona: " . $leadPersona;
                $contactName = $leadPersona;
            } else {
                $contactName = '';
            }

            // Insert lead
            $insertStmt = $db->prepare("INSERT INTO leads (company_name, contact_name, email, website, source, campaign_id, notes, status) VALUES (?, ?, ?, ?, 'Harvested', ?, ?, 'New')");
            $insertStmt->execute([
                $companyName,
                $contactName,
                $placeholderEmail,
                $url,
                $campaignId,
                $notes
            ]);
            $stagedCount++;
        }

        return [
            'staged' => $stagedCount,
            'duplicates' => $duplicatesCount
        ];
    }
}
