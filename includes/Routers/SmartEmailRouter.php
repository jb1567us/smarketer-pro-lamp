<?php

declare(strict_types=1);

namespace App\Routers;

use App\Database;

class SmartEmailRouter {
    private $pdo;
    private $providers = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->providers = ['resend', 'brevo', 'sendgrid', 'mailjet']; 
    }

    public function send($to, $subject, $body) {
        $attempts = [];
        $rotator = new SmartRotationManager($this->pdo);

        foreach ($this->providers as $providerName) {
            // 1. Get raw API keys (can be comma-separated list of keys!)
            $rawKeys = Database::getSetting("{$providerName}_api_key");

            // 2. Check if the provider is fully rate-limited
            if ($rotator->isServiceRateLimited($providerName, $rawKeys)) {
                $attempts[$providerName] = "Daily Limit Reached";
                continue;
            }

            // 3. Select active key
            $apiKey = $rotator->selectActiveKey($providerName, $rawKeys);
            if (!$apiKey) {
                $attempts[$providerName] = "API Key Missing";
                continue;
            }

            // 4. Attempt Send
            echo "  [SmartRouter] Attempting delivery via $providerName...\n";
            $result = $this->attemptDelivery($providerName, $apiKey, $to, $subject, $body);

            // 5. Log Result & Key Usage
            if ($result['success']) {
                $rotator->logCall($providerName, $apiKey, 'success');
                $this->logEvent($to, $providerName, $result);
                echo "  [SmartRouter] Success via $providerName.\n";
                return true;
            } else {
                $rotator->logCall($providerName, $apiKey, 'failed', $result['error'] ?? 'Unknown Error');
                $this->logEvent($to, $providerName, $result);
                $attempts[$providerName] = $result['error'] ?? 'Unknown Error';
                echo "  [SmartRouter] Failed: " . ($result['error'] ?? 'Unknown Error') . "\n";
            }
        }

        return false;
    }

    private function logEvent($to, $provider, $result) {
        $status = $result['success'] ? 'sent' : 'failed';
        $meta = json_encode(['error' => $result['error'] ?? null]);
        
        $stmt = $this->pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$to, $provider, $status, $meta, time()]);
    }

    private function attemptDelivery($provider, $apiKey, $to, $subject, $body) {
        // Mock Implementation for the Port
        // In production, each case would utilize the respective API SDK or SMTP
        
        if (!$apiKey) {
            return ['success' => false, 'error' => 'API Key Missing'];
        }

        // Simulate API latency
        usleep(200000); 

        // Simulate random failure (10% chance)
        if (rand(1, 100) <= 10) {
            return ['success' => false, 'error' => 'Simulated Network Error 500'];
        }

        // Simulate success
        return ['success' => true];
    }
}
