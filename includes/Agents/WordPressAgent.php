<?php
namespace App\Agents;

class WordPressAgent {
    private $pdo;
    private $pythonPath = "python3"; // Or path to venv python
    private $installerPath = __DIR__ . "/../../tools/wp-installer/install.py";

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Publishes content via WordPress REST API
     */
    public function publishPost($siteUrl, $username, $appPassword, $data) {
        $baseUrl = rtrim($siteUrl, '/') . '/wp-json/wp/v2/posts';
        
        $auth = base64_encode("$username:$appPassword");
        
        $headers = [
            "Authorization: Basic $auth",
            "Content-Type: application/json"
        ];

        $payload = json_encode([
            'title' => $data['title'] ?? 'Untitled',
            'content' => $data['content'] ?? '',
            'status' => $data['status'] ?? 'draft',
            'categories' => $data['categories'] ?? []
        ]);

        $ch = curl_init($baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'data' => json_decode($response, true)];
        }
        
        return ['success' => false, 'error' => "HTTP $httpCode: $response"];
    }
}
