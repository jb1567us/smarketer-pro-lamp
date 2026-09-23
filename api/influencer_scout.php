<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
$pdo = \App\Database::getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

function parseSocialCandidate($platform, $item) {
    $url = $item['url'];
    $title = $item['title'];
    $content = $item['content'];
    
    $handle = '';
    $name = '';
    
    $platformLower = strtolower($platform);
    if ($platformLower === 'linkedin') {
        if (preg_match('/linkedin\.com\/in\/([^\/?#]+)/i', $url, $matches)) {
            $handle = $matches[1];
        }
        $parts = preg_split('/[\|\-\(]/', $title);
        $name = trim($parts[0]);
    } elseif ($platformLower === 'facebook') {
        if (preg_match('/facebook\.com\/([^\/?#]+)/i', $url, $matches)) {
            $rawHandle = $matches[1];
            if (in_array(strtolower($rawHandle), ['pages', 'groups', 'people', 'share', 'profile.php'])) {
                return null;
            }
            $handle = $rawHandle;
        } else {
            return null;
        }
        $parts = preg_split('/[\|\-\(]/', $title);
        $name = trim($parts[0]);
    } elseif ($platformLower === 'x' || $platformLower === 'twitter') {
        if (preg_match('/(?:x|twitter)\.com\/([^\/?#]+)/i', $url, $matches)) {
            $rawHandle = $matches[1];
            if (in_array(strtolower($rawHandle), ['status', 'hashtag', 'search', 'intent', 'i', 'home', 'explore'])) {
                return null;
            }
            $handle = '@' . $rawHandle;
        } else {
            return null;
        }
        if (preg_match('/^(.*?)\s*\(@/', $title, $titleMatches)) {
            $name = trim($titleMatches[1]);
        } else {
            $parts = preg_split('/[\|\-\/]/', $title);
            $name = trim($parts[0]);
        }
    } elseif ($platformLower === 'instagram') {
        if (preg_match('/instagram\.com\/([^\/?#]+)/i', $url, $matches)) {
            $rawHandle = $matches[1];
            if (in_array(strtolower($rawHandle), ['p', 'reel', 'explore', 'stories'])) {
                return null;
            }
            $handle = '@' . $rawHandle;
        } else {
            return null;
        }
        if (preg_match('/^(.*?)\s*\(@/', $title, $titleMatches)) {
            $name = trim($titleMatches[1]);
        } else {
            $parts = preg_split('/[•\|\-\(]/u', $title);
            $name = trim($parts[0]);
        }
    } elseif ($platformLower === 'tiktok') {
        if (preg_match('/tiktok\.com\/@([^\/?#]+)/i', $url, $matches)) {
            $handle = '@' . $matches[1];
        } else {
            return null;
        }
        if (preg_match('/^(.*?)\s*\(@/', $title, $titleMatches)) {
            $name = trim($titleMatches[1]);
        } else {
            $parts = preg_split('/[\|\-\(]/', $title);
            $name = trim($parts[0]);
        }
    }
    
    if (empty($handle)) {
        return null;
    }
    if (empty($name) || strlen($name) > 100) {
        $name = str_replace('@', '', $handle);
    }
    
    // Clean and decode search text to find realistic follower counts
    $decodedTitle = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $decodedContent = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    $candidates = [];
    
    $regexes = [
        // Structured profile stats ( Instagram, TikTok, X formats with flexible spacers like newlines, spaces, bullets, commas )
        'structured_followers_first' => [
            'pattern' => '/([\d,]+(?:\.\d+)?\s*[KkMm]?)\s*followers[\s,\n\r•|\\-\/]+[\d,]+(?:\.\d+)?\s*[KkMm]?\s*(?:following|posts|photos|videos)/ui',
            'score_bonus' => 500
        ],
        'structured_posts_first' => [
            'pattern' => '/[\d,]+(?:\.\d+)?\s*[KkMm]?\s*(?:posts|photos|videos)[\s,\n\r•|\\-\/]+([\d,]+(?:\.\d+)?\s*[KkMm]?)\s*followers/ui',
            'score_bonus' => 500
        ],
        'see_on_social' => [
            'pattern' => '/([\d,]+(?:\.\d+)?\s*[KkMm]?)\s*followers\s*-\s*See\s*(?:Instagram|TikTok|Twitter|X|LinkedIn|Facebook)/ui',
            'score_bonus' => 400
        ],
        'followers_on_social' => [
            'pattern' => '/([\d,]+(?:\.\d+)?\s*[KkMm]?)\s*followers\s*on\s*(?:Instagram|TikTok|Twitter|X|LinkedIn|Facebook)/ui',
            'score_bonus' => 400
        ],
        // Generic matches
        'generic_followers' => [
            'pattern' => '/([\d,]+(?:\.\d+)?\s*[KkMm]?)\s*followers/ui',
            'score_bonus' => 100
        ],
        'followers_colon' => [
            'pattern' => '/followers:?\s*([\d,]+(?:\.\d+)?\s*[KkMm]?)/ui',
            'score_bonus' => 100
        ],
        'generic_subscribers' => [
            'pattern' => '/([\d,]+(?:\.\d+)?\s*[KkMm]?)\s*subscribers/ui',
            'score_bonus' => 100
        ],
        'subscribers_colon' => [
            'pattern' => '/subscribers:?\s*([\d,]+(?:\.\d+)?\s*[KkMm]?)/ui',
            'score_bonus' => 100
        ]
    ];
    
    $parseValue = function($valStr) {
        $valStr = str_replace(',', '', trim($valStr));
        if (stripos($valStr, 'm') !== false) {
            return (int)((float)str_ireplace('m', '', $valStr) * 1000000);
        } elseif (stripos($valStr, 'k') !== false) {
            return (int)((float)str_ireplace('k', '', $valStr) * 1000);
        }
        return (int)$valStr;
    };
    
    $cleanHandle = str_replace('@', '', $handle);
    
    foreach (['content' => $decodedContent, 'title' => $decodedTitle] as $source => $text) {
        if (empty($text)) continue;
        
        foreach ($regexes as $key => $conf) {
            if (preg_match_all($conf['pattern'], $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[1] as $matchIdx => $matchData) {
                    $valStr = $matchData[0];
                    $offset = $matchData[1];
                    $val = $parseValue($valStr);
                    
                    if ($val <= 0) continue;
                    
                    $score = $conf['score_bonus'];
                    if ($source === 'content') {
                        $score += 200;
                    }
                    
                    $minDistance = 9999;
                    foreach ([$cleanHandle, $name] as $term) {
                        if (empty($term)) continue;
                        $termPos = stripos($text, $term);
                        if ($termPos !== false) {
                            $dist = abs($offset - $termPos);
                            if ($dist < $minDistance) {
                                $minDistance = $dist;
                            }
                        }
                    }
                    
                    $proximityBonus = max(0, 300 - $minDistance);
                    $score += $proximityBonus;
                    
                    $candidates[] = [
                        'val' => $val,
                        'score' => $score,
                        'source' => $source,
                        'rule' => $key
                    ];
                }
            }
        }
    }
    
    $follower_count = 0;
    $best_score = 0;
    if (!empty($candidates)) {
        usort($candidates, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        $follower_count = $candidates[0]['val'];
        $best_score = $candidates[0]['score'];
    }
    
    return [
        'name' => $name,
        'platform' => ucfirst($platformLower),
        'handle' => $handle,
        'url' => $url,
        'follower_count' => $follower_count,
        'parse_score' => $best_score,
        'snippet' => $content
    ];
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM influencers ORDER BY created_at DESC");
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(\App\PDO::FETCH_ASSOC)]);
}
elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Add new influencer (utilizing INSERT IGNORE to prevent key conflicts on duplicates)
    if ($action === 'add') {
        $stmt = $pdo->prepare("INSERT IGNORE INTO influencers (name, platform, handle, url, follower_count, tags) VALUES (?, ?, ?, ?, ?, ?)");
        try {
            $stmt->execute([
                $input['name'], 
                $input['platform'], 
                $input['handle'], 
                $input['url'] ?? '', 
                $input['follower_count'] ?? 0, 
                $input['tags'] ?? ''
            ]);
            $affected = $stmt->rowCount();
            if ($affected === 0) {
                // If it already exists, return success true but with a flag or notice
                echo json_encode(['success' => true, 'duplicate' => true, 'message' => 'Influencer already exists.']);
            } else {
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    // AI Search Scout Discovery
    elseif ($action === 'discover') {
        $niche = $input['niche'] ?? '';
        $location = $input['location'] ?? '';
        $platform = $input['platform'] ?? 'linkedin';
        $customProvider = $input['provider'] ?? '';
        
        if (empty($niche) || empty($location)) {
            echo json_encode(['success' => false, 'error' => 'Niche and Location are required.']);
            exit;
        }
        
        $minFollowers = isset($input['min_followers']) && $input['min_followers'] !== '' ? (int)$input['min_followers'] : 0;
        $maxFollowers = isset($input['max_followers']) && $input['max_followers'] !== '' ? (int)$input['max_followers'] : PHP_INT_MAX;
        $minResults = isset($input['min_results']) && $input['min_results'] !== '' ? (int)$input['min_results'] : 1;
        $maxResults = isset($input['max_results']) && $input['max_results'] !== '' ? (int)$input['max_results'] : 25;
        
        $activeProviderName = 'searxng';
        if (!empty($customProvider)) {
            $activeProviderName = $customProvider;
        } else {
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'active_search_provider'");
            $stmt->execute();
            $row = $stmt->fetch();
            if ($row) {
                $activeProviderName = $row['setting_value'];
            }
        }
        
        $platformLower = strtolower($platform);
        $dorkKey = 'linkedin_owner';
        if ($platformLower === 'facebook') {
            $dorkKey = 'facebook_profile';
        } elseif ($platformLower === 'x' || $platformLower === 'twitter') {
            $dorkKey = 'x_profile';
        } elseif ($platformLower === 'instagram') {
            $dorkKey = 'instagram_profile';
        } elseif ($platformLower === 'tiktok') {
            $dorkKey = 'tiktok_profile';
        } else {
            $dorkKey = 'linkedin_owner';
        }
        
        $dorks = \App\DorkLibrary::generateDorks($niche, $location, null, $activeProviderName);
        $dorkQuery = $dorks[$dorkKey] ?? '';
        if (empty($dorkQuery)) {
            $dorkQuery = "site:{$platformLower}.com \"{$niche}\" \"{$location}\"";
        }
        
        try {
            require_once __DIR__ . '/../includes/SimpleHarvester.php';
            $harvester = new SimpleHarvester($activeProviderName);
            
            $groupedCandidates = [];
            
            // Build the queue of queries to attempt in order
            $queryQueue = [];
            
            // 1. Add primary dork query
            $queryQueue[] = [
                'query' => $dorkQuery,
                'description' => 'Primary Dork'
            ];
            
            // 2. Add metro sub-location expansions
            $subLocations = \App\DorkLibrary::expandLocation($location);
            if (count($subLocations) > 1) {
                // First element in expandLocation is the primary location, so we skip it to avoid duplication
                $extraLocs = array_slice($subLocations, 1);
                foreach ($extraLocs as $subLoc) {
                    $subDorks = \App\DorkLibrary::generateDorks($niche, $subLoc, null, $activeProviderName);
                    $subQuery = $subDorks[$dorkKey] ?? "site:{$platformLower}.com \"{$niche}\" \"{$subLoc}\"";
                    $queryQueue[] = [
                        'query' => $subQuery,
                        'description' => "Location Expansion: {$subLoc}"
                    ];
                }
            }
            
            // 3. Add platform alternative dorks if applicable (e.g. LinkedIn CEOs/Founders)
            if ($platformLower === 'linkedin') {
                $altKeys = ['linkedin_founder', 'linkedin_ceo', 'linkedin_director'];
                // Filter out the primary key if it was already used
                $altKeys = array_diff($altKeys, [$dorkKey]);
                foreach ($altKeys as $altKey) {
                    $altDorks = \App\DorkLibrary::generateDorks($niche, $location, null, $activeProviderName);
                    if (isset($altDorks[$altKey])) {
                        $queryQueue[] = [
                            'query' => $altDorks[$altKey],
                            'description' => "Platform Alternative: {$altKey}"
                        ];
                    }
                }
            }
            
            // 4. Add a relaxed fallback query (without quotes) as a last resort
            $relaxedQuery = "site:{$platformLower}.com {$niche} {$location}";
            if ($dorkQuery !== $relaxedQuery) {
                $queryQueue[] = [
                    'query' => $relaxedQuery,
                    'description' => 'Relaxed Dork Fallback'
                ];
            }
            
            $queriesAttempted = [];
            
            foreach ($queryQueue as $qItem) {
                $q = $qItem['query'];
                $desc = $qItem['description'];
                
                // If it's the Relaxed Dork Fallback, only run it if we don't have at least $minResults qualified candidates yet
                if ($desc === 'Relaxed Dork Fallback') {
                    $qualifiedCount = 0;
                    foreach ($groupedCandidates as $cand) {
                        $fCount = (int)$cand['follower_count'];
                        if ($fCount === 0 || ($fCount >= $minFollowers && $fCount <= $maxFollowers)) {
                            $qualifiedCount++;
                        }
                    }
                    if ($qualifiedCount >= $minResults) {
                        // Skip relaxed fallback since we met the minimum results with high-quality dorks
                        continue;
                    }
                }
                
                // If we've already reached maxResults qualified unique candidates, we can stop querying
                $qualifiedCount = 0;
                foreach ($groupedCandidates as $cand) {
                    $fCount = (int)$cand['follower_count'];
                    if ($fCount === 0 || ($fCount >= $minFollowers && $fCount <= $maxFollowers)) {
                        $qualifiedCount++;
                    }
                }
                if ($qualifiedCount >= $maxResults) {
                    break;
                }
                
                $queriesAttempted[] = "{$desc} ({$q})";
                
                try {
                    // Fetch up to maxResults * 3 raw results for each query to ensure we harvest plenty of candidates
                    $harvestLimit = max(50, $maxResults * 3);
                    $rawResults = $harvester->harvest($q, $harvestLimit);
                    
                    foreach ($rawResults as $item) {
                        $candidate = parseSocialCandidate($platform, $item);
                        if ($candidate && !empty($candidate['handle'])) {
                            $handle = $candidate['handle'];
                            // Group duplicates: select candidate with highest parse_score
                            if (!isset($groupedCandidates[$handle]) || $candidate['parse_score'] > $groupedCandidates[$handle]['parse_score']) {
                                $groupedCandidates[$handle] = $candidate;
                            }
                        }
                    }
                } catch (Exception $ex) {
                    // Log failure of this query, but continue to next query in the queue
                    error_log("[Scout Discover] Query '{$q}' failed: " . $ex->getMessage());
                }
            }
            
            // Final bounds filtering and slicing to respect maxResults
            $finalCandidates = [];
            foreach ($groupedCandidates as $cand) {
                $followers = (int)$cand['follower_count'];
                if ($followers === 0 || ($followers >= $minFollowers && $followers <= $maxFollowers)) {
                    $finalCandidates[] = $cand;
                }
            }
            
            if (count($finalCandidates) > $maxResults) {
                $finalCandidates = array_slice($finalCandidates, 0, $maxResults);
            }
            
            echo json_encode([
                'success' => true, 
                'dork_query' => $dorkQuery,
                'queries_attempted' => $queriesAttempted,
                'provider' => $harvester->getActiveProviderName(),
                'data' => $finalCandidates
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    // Update status
    elseif ($action === 'update_status') {
        $stmt = $pdo->prepare("UPDATE influencers SET status = ? WHERE id = ?");
        $stmt->execute([$input['status'], $input['id']]);
        echo json_encode(['success' => true]);
    }
    // Crawl a profile URL to extract follower count
    elseif ($action === 'crawl_profile') {
        $id       = (int)($input['id'] ?? 0);
        $url      = $input['url'] ?? '';
        $platform = $input['platform'] ?? '';

        if (!isset($input['url']) || empty($url)) {
            echo json_encode(['success' => false, 'error' => 'Missing url.']);
            exit;
        }

        // Fetch the profile page via cURL
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.9',
            ],
        ]);
        $html     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$html || $httpCode < 200 || $httpCode >= 400) {
            echo json_encode(['success' => false, 'error' => "Profile fetch failed (HTTP $httpCode). The platform may block bots."]);
            exit;
        }

        // Strip tags and decode to plain text for regex parsing
        $plainText = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Re-use the same parseSocialCandidate logic via a synthetic item
        $fakeParsedTitle = '';
        // Attempt to pull <title> from the raw HTML for better name/title extraction
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $tMatch)) {
            $fakeParsedTitle = html_entity_decode(strip_tags($tMatch[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $candidate = parseSocialCandidate($platform, [
            'url'     => $url,
            'title'   => $fakeParsedTitle,
            'content' => $plainText,
        ]);

        $newCount = $candidate ? (int)$candidate['follower_count'] : 0;

        if ($newCount > 0) {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE influencers SET follower_count = ? WHERE id = ?");
                $stmt->execute([$newCount, $id]);
            }
            echo json_encode(['success' => true, 'follower_count' => $newCount]);
        } else {
            // Couldn't parse — return partial success so the UI can report it
            echo json_encode(['success' => false, 'error' => 'Could not detect follower count from page content. The profile may require login or is dynamically rendered.']);
        }
    }
    // Update influencer details
    elseif ($action === 'update') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Missing id.']);
            exit;
        }
        
        $name = $input['name'] ?? '';
        $platform = $input['platform'] ?? '';
        $handle = $input['handle'] ?? '';
        $url = $input['url'] ?? '';
        $follower_count = isset($input['follower_count']) ? (int)$input['follower_count'] : 0;
        $tags = $input['tags'] ?? '';
        $notes = $input['notes'] ?? '';
        
        if (empty($name) || empty($platform) || empty($handle)) {
            echo json_encode(['success' => false, 'error' => 'Name, Platform, and Handle are required fields.']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE influencers SET name = ?, platform = ?, handle = ?, url = ?, follower_count = ?, tags = ?, notes = ? WHERE id = ?");
            $stmt->execute([$name, $platform, $handle, $url, $follower_count, $tags, $notes, $id]);
            echo json_encode(['success' => true]);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    // Delete influencer
    elseif ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'Missing id.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM influencers WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    }
}

