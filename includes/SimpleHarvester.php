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
        
        // Priority list of all search providers
        $providersOrder = ['searxng', 'serper', 'tavily', 'exa', 'firecrawl', 'scrapingant', 'gemini', 'ddg'];
        
        $currentProviderName = strtolower($this->provider->getName());
        if (strpos($currentProviderName, 'duckduckgo') !== false) {
            $currentProviderName = 'ddg';
        } elseif (strpos($currentProviderName, 'scrapingant') !== false) {
            $currentProviderName = 'scrapingant';
        }
        
        $startIndex = array_search($currentProviderName, $providersOrder);
        if ($startIndex === false) {
            $startIndex = 0;
        }
        
        $errors = [];
        
        // 1. Try from current provider onwards in the priority list
        for ($i = $startIndex; $i < count($providersOrder); $i++) {
            $providerName = $providersOrder[$i];
            
            // Skip API providers if their key is not configured in settings
            if ($providerName === 'serper' && empty($this->getSetting('serper_api_key'))) continue;
            if ($providerName === 'tavily' && empty($this->getSetting('tavily_api_key'))) continue;
            if ($providerName === 'exa' && empty($this->getSetting('exa_api_key'))) continue;
            if ($providerName === 'firecrawl' && empty($this->getSetting('firecrawl_api_key'))) continue;
            if ($providerName === 'scrapingant' && empty($this->getSetting('scrapingant_api_key'))) continue;
            if ($providerName === 'gemini' && empty($this->getSetting('gemini_api_key'))) continue;
            
            try {
                $this->loadProvider($providerName);
                if (!$this->provider) continue;
                
                $results = $this->provider->search($query, $limit);
                
                // Log successful request in the usage logs
                if (!empty($this->loadedApiKey)) {
                    $rotator->logCall($providerName, $this->loadedApiKey, 'success');
                }
                
                // Reset consecutive failures
                $this->setConsecutiveFailures(0);
                
                // If we recovered using a fallback, update active_search_provider setting in DB
                if ($providerName !== $currentProviderName) {
                    $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_search_provider', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$providerName, $providerName]);
                    error_log("[SimpleHarvester] Dynamically recovered search. Active provider updated to: " . $providerName);
                }
                
                return $results;
            } catch (Exception $e) {
                $errors[] = "$providerName failed: " . $e->getMessage();
                if (!empty($this->loadedApiKey)) {
                    $rotator->logCall($providerName, $this->loadedApiKey, 'failed', $e->getMessage());
                }
                // Continue to next provider
            }
        }
        
        // 2. Try the providers BEFORE the starting index just in case they recovered
        for ($i = 0; $i < $startIndex; $i++) {
            $providerName = $providersOrder[$i];
            
            if ($providerName === 'serper' && empty($this->getSetting('serper_api_key'))) continue;
            if ($providerName === 'tavily' && empty($this->getSetting('tavily_api_key'))) continue;
            if ($providerName === 'exa' && empty($this->getSetting('exa_api_key'))) continue;
            if ($providerName === 'firecrawl' && empty($this->getSetting('firecrawl_api_key'))) continue;
            if ($providerName === 'scrapingant' && empty($this->getSetting('scrapingant_api_key'))) continue;
            if ($providerName === 'gemini' && empty($this->getSetting('gemini_api_key'))) continue;
            
            try {
                $this->loadProvider($providerName);
                if (!$this->provider) continue;
                
                $results = $this->provider->search($query, $limit);
                
                if (!empty($this->loadedApiKey)) {
                    $rotator->logCall($providerName, $this->loadedApiKey, 'success');
                }
                
                $this->setConsecutiveFailures(0);
                
                $stmt = $this->db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('active_search_provider', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$providerName, $providerName]);
                error_log("[SimpleHarvester] Dynamically recovered search. Active provider updated to: " . $providerName);
                
                return $results;
            } catch (Exception $e) {
                $errors[] = "$providerName failed: " . $e->getMessage();
                if (!empty($this->loadedApiKey)) {
                    $rotator->logCall($providerName, $this->loadedApiKey, 'failed', $e->getMessage());
                }
            }
        }
        
        // Increment consecutive failures count as all failed
        $this->incrementConsecutiveFailures();
        
        throw new Exception("All search providers failed.\nDetails:\n" . implode("\n", $errors));
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
     * Score harvested search results with the real TrustScorer.
     *
     * TrustScorer::computeTrustScore() is a PURE function (no network, no LLM:
     * microseconds per call), so scoring runs synchronously at harvest time.
     * There is deliberately NO queue/cron involvement: the existing job queue
     * exists for slow work (search API calls); scoring is not slow. The only
     * network input is the DNS receptivity check, which is cached per unique
     * domain within the batch and fails soft (any DNS error =>
     * dnsReceptive=false), so a flaky resolver can never break a harvest.
     * A settings kill-switch ('trustscorer_harvest_dns', default ON) lets a
     * buyer with a pathological resolver disable the DNS probe entirely.
     *
     * Honest evidence mapping at harvest time (see docs/ITEM4_TRUSTSCORER.md):
     *  - hasLevel1 = false — no on-site page extraction happens during
     *    harvest, so on-site evidence is NEVER claimed.
     *  - hasLevel2 = false — no directory-baseline lookup happens during
     *    harvest, so directory evidence is NEVER claimed.
     *  - hasLevel3 = true when a contact email is found in the harvest item's
     *    own snippet text (title/content/snippet). The item IS a search-engine
     *    snippet, so this is exactly what Level 3 ("contact mentioned in
     *    general search engine snippets") means.
     *  - dnsReceptive = MX/A check on the contact's email domain, falling
     *    back to the prospect website's domain when the snippet has no email.
     *  - crossReferenceMatch = false — a single source only at harvest time.
     *
     * HONESTY: a score is attached to an item ONLY when it was genuinely
     * computed (score > 0, i.e. at least one evidence input was true). Items
     * with zero evidence are returned untouched — no 'score' key — so the
     * card renderer keeps showing the honest "Not scored" badge. Nothing is
     * ever defaulted, interpolated, or fabricated.
     *
     * @param array $results Harvest items (title/url/content/snippet).
     * @param callable|null $dnsCheck Optional DNS probe (tests):
     *        fn(string $domain): bool. Defaults to DNSChecker::checkReceptivity.
     * @param bool $dnsEnabled Kill-switch: false skips DNS probes entirely.
     * @return array Same items; scored ones gain score/trust_score/trust_tier/
     *         trust_breakdown/verification_status. 'score' is 0..1 to match the
     *         harvest card renderer (it computes Math.round(item.score * 100)).
     */
    public static function scoreHarvestResults(array $results, ?callable $dnsCheck = null, bool $dnsEnabled = true): array
    {
        require_once __DIR__ . '/ExtractionEngine.php';
        if ($dnsCheck === null) {
            $dnsCheck = function (string $domain): bool {
                try {
                    $r = \App\Verification\DNSChecker::checkReceptivity($domain);
                    return (bool)($r['is_receptive'] ?? false);
                } catch (\Throwable $e) {
                    return false;
                }
            };
        }

        $dnsCache = [];
        foreach ($results as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $evidence = self::harvestEvidence($item, $dnsCache, $dnsCheck, $dnsEnabled);

            $computed = \App\Verification\TrustScorer::computeTrustScore(
                false,                    // hasLevel1: no on-site extraction at harvest time
                false,                    // hasLevel2: no directory lookup at harvest time
                $evidence['hasLevel3'],
                $evidence['dnsReceptive'],
                1.0,
                1.0,
                false                     // crossReferenceMatch: single source at harvest time
            );

            $trustScore = (int)($computed['trust_score'] ?? 0);
            if ($trustScore > 0) {
                $results[$i]['score'] = round($trustScore / 100, 4);
                $results[$i]['trust_score'] = $trustScore;
                $results[$i]['trust_tier'] = $computed['trust_tier'] ?? 'unverified';
                $results[$i]['trust_breakdown'] = $computed['breakdown'] ?? [];
                $results[$i]['verification_status'] = $computed['verification_status'] ?? 'unverified';
            }
            // trustScore == 0 means "genuinely computed: no evidence". The
            // item keeps no score key, so the UI shows "Not scored" — an
            // honest state, not a fabricated zero.
        }
        return $results;
    }

    /**
     * Gather the TrustScorer evidence inputs actually available for one
     * harvest item. DNS results are cached per domain for the whole batch.
     */
    private static function harvestEvidence(array $item, array &$dnsCache, callable $dnsCheck, bool $dnsEnabled): array
    {
        $text = ($item['title'] ?? '') . ' ' . ($item['content'] ?? '') . ' ' . ($item['snippet'] ?? '');
        $emails = \App\ExtractionEngine::extractEmails($text);
        $hasLevel3 = !empty($emails);

        $domain = '';
        if (!empty($emails)) {
            $parts = explode('@', $emails[0]);
            $domain = strtolower(trim((string)end($parts)));
        }
        if ($domain === '' && !empty($item['url'])) {
            $host = parse_url((string)$item['url'], PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $domain = strtolower((string)preg_replace('/^www\./', '', $host));
            }
        }

        $dnsReceptive = false;
        if ($dnsEnabled && $domain !== '') {
            if (!array_key_exists($domain, $dnsCache)) {
                try {
                    $dnsCache[$domain] = (bool)$dnsCheck($domain);
                } catch (\Throwable $e) {
                    // Fail soft: a broken DNS probe degrades to "no DNS
                    // evidence", never to a failed harvest.
                    $dnsCache[$domain] = false;
                }
            }
            $dnsReceptive = $dnsCache[$domain];
        }

        return ['hasLevel3' => $hasLevel3, 'dnsReceptive' => $dnsReceptive];
    }

    /**
     * Kill-switch for the harvest-time DNS probe (settings key
     * 'trustscorer_harvest_dns', default ON). Lets a buyer with a pathological
     * resolver disable the only network I/O in scoring without touching code.
     */
    public static function isHarvestDnsEnabled($db): bool
    {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'trustscorer_harvest_dns'");
            $stmt->execute();
            $row = $stmt->fetch();
            if (is_array($row) && array_key_exists('setting_value', $row)) {
                return $row['setting_value'] !== '0';
            }
        } catch (\Throwable $e) {
            // settings table missing/unreadable: default to enabled.
        }
        return true;
    }

    /**
     * True when the leads table carries the trust columns (trust_score,
     * trust_breakdown) — i.e. schema.sql or the item-4 migration has been
     * applied. Harvest must never fatal on installs that skipped it.
     */
    private static function leadsHasTrustColumns($db): bool
    {
        try {
            $stmt = $db->query("SELECT trust_score, trust_breakdown FROM leads LIMIT 0");
            return $stmt !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Stage harvested results into the leads table as 'New' entries.
     * Prevents duplicates by domain mapping.
     */
    public static function stageResults($db, array $results, string $query, ?int $campaignId, string $leadPersona): array {
        $stagedCount = 0;
        $duplicatesCount = 0;

        // Detect the trust columns ONCE per call (not per row). Installs that
        // never ran schema.sql / the item-4 migration simply skip trust
        // persistence instead of fataling the whole harvest.
        $trustCols = self::leadsHasTrustColumns($db);

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

            // Extract emails from content and title
            require_once __DIR__ . '/ExtractionEngine.php';
            $itemText = ($item['title'] ?? '') . ' ' . ($item['content'] ?? '') . ' ' . ($item['snippet'] ?? '');
            $extractedEmails = \App\ExtractionEngine::extractEmails($itemText);

            $primaryEmail = $placeholderEmail;
            $emailSource = null;
            if (!empty($extractedEmails)) {
                $primaryEmail = $extractedEmails[0];
                $emailSource = 'search_snippet';
            }

            // Flag role-based addresses (info@, sales@, ...) — deliverable
            // maybe, but not a person. Buyers can include/exclude deliberately.
            $isRoleBased = 0;
            $localPart = strtolower(explode('@', $primaryEmail)[0] ?? '');
            if (in_array($localPart, ['info','sales','contact','support','hello','team','office','billing','careers','jobs','press','media','marketing','help','service','enquiries','enquiry'], true)) {
                $isRoleBased = 1;
            }

            // Notes field with metadata
            $notes = "Harvested from: " . $url . "\nSnippet: " . ($item['content'] ?? '') . "\nQuery: " . $query;
            if (!empty($extractedEmails)) {
                $notes .= "\nHarvested Emails: " . implode(', ', $extractedEmails);
            }
            
            if (!empty($leadPersona)) {
                $notes .= "\nTarget Persona: " . $leadPersona;
            }
            // Item 9: harvested search results carry no parsed person name,
            // so contact_name stays NULL. User-entered persona text goes to
            // target_persona only — never to contact_name.
            $contactName = null;

            // Insert lead.
            //
            // Item 4: persist the genuine TrustScorer result when this harvest
            // item was actually scored. scoreHarvestResults() only attaches
            // trust_score/trust_breakdown/verification_status when a score was
            // genuinely computed (score > 0), so isset() here is an honesty
            // gate, not a default. Unscored items keep the legacy behavior
            // (trust columns untouched, verification_status 'unknown').
            $trustExtraCols = '';
            $trustExtraPlaceholders = '';
            $trustExtraParams = [];
            $insertVerifStatus = 'unknown';
            $itemTrustScore = isset($item['trust_score']) ? (int)$item['trust_score'] : 0;
            if ($trustCols && $itemTrustScore > 0) {
                $trustExtraCols = ', trust_score, trust_breakdown';
                $trustExtraPlaceholders = ', ?, ?';
                $trustExtraParams = [$itemTrustScore, json_encode($item['trust_breakdown'] ?? [])];
                // Item B: the union ENUM('unverified','evidence_backed',
                // 'dns_confirmed','cross_source_matched','gold_standard',
                // 'unknown','valid','invalid','risky') accepts every value
                // TrustScorer can emit; see migrations/2026-09-24-itemb-enum-align.sql.
                $insertVerifStatus = $item['verification_status'] ?? 'unverified';
            }
            $targetPersona = !empty($leadPersona) ? $leadPersona : null;
            $insertStmt = $db->prepare("INSERT INTO leads (company_name, contact_name, email, website, source, campaign_id, notes, status, target_persona, email_source, source_url, is_role_based, consent_status, verification_status{$trustExtraCols}) VALUES (?, ?, ?, ?, 'Harvested', ?, ?, 'New', ?, ?, ?, ?, 'unknown', ?{$trustExtraPlaceholders})");
            try {
                $insertStmt->execute(array_merge([
                    $companyName,
                    $contactName,
                    $primaryEmail,
                    $url,
                    $campaignId,
                    $notes,
                    $targetPersona,
                    $emailSource,
                    $url,
                    $isRoleBased,
                    $insertVerifStatus,
                ], $trustExtraParams));
                $stagedCount++;
            } catch (\Throwable $e) {
                // Duplicate-key race (the pre-check above is not atomic):
                // count as a duplicate, keep harvesting. Anything else is
                // re-thrown so real failures stay visible.
                $msg = $e->getMessage();
                $code = (int)$e->getCode();
                if ($code === 1062 || stripos($msg, 'duplicate') !== false) {
                    $duplicatesCount++;
                    continue;
                }
                throw $e;
            }
        }

        return [
            'staged' => $stagedCount,
            'duplicates' => $duplicatesCount
        ];
    }
}
