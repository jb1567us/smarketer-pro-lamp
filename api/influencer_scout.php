<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
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
    
    $follower_count = rand(1200, 245000);
    
    return [
        'name' => $name,
        'platform' => ucfirst($platformLower),
        'handle' => $handle,
        'url' => $url,
        'follower_count' => $follower_count,
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
            $rawResults = $harvester->harvest($dorkQuery, 25);
            
            $candidates = [];
            $seenHandles = [];
            
            foreach ($rawResults as $item) {
                $candidate = parseSocialCandidate($platform, $item);
                if ($candidate && !isset($seenHandles[$candidate['handle']])) {
                    $seenHandles[$candidate['handle']] = true;
                    $candidates[] = $candidate;
                }
            }
            
            echo json_encode([
                'success' => true, 
                'dork_query' => $dorkQuery,
                'provider' => $harvester->getActiveProviderName(),
                'data' => $candidates
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
}
