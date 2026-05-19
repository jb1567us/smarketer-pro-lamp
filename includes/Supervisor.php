<?php

namespace App;

/**
 * Supervisor Class
 * Inspired by the Python supervisor.py, this class monitors system health
 * and ensures background tasks and integrations are operational.
 */
class Supervisor {
    private $pdo;
    private $results = [];

    public function __construct() {
        $this->pdo = Database::getConnection();
    }

    /**
     * Run all health checks
     */
    public function runFullAudit() {
        try {
            $this->results = [
                'timestamp' => date('Y-m-d H:i:s'),
                'database' => $this->checkDatabase(),
                'proxy' => $this->auditProxyHealth(),
                'storage' => $this->auditDiskSpace(),
                'cleanup' => $this->performCleanup(),
                'env' => $this->checkEnvironment()
            ];
        } catch (\Exception $e) {
            $this->results = [
                'timestamp' => date('Y-m-d H:i:s'),
                'error' => $e->getMessage()
            ];
        }
        return $this->results;
    }

    private function checkDatabase() {
        try {
            $stmt = $this->pdo->query("SELECT 1");
            return $stmt ? "Healthy" : "Query Failed";
        } catch (\Exception $e) {
            return "Error: " . $e->getMessage();
        }
    }

    private function checkSearchConnectivity() {
        $providerResults = [];
        $settings = $this->getSettings();
        
        // Check Gemini (Free)
        if (!empty($settings['gemini_api_key'])) {
            $providerResults['gemini'] = $this->pingUrl("https://generativelanguage.googleapis.com/v1/models?key=" . $settings['gemini_api_key']);
        }

        // Check DDG (No key needed)
        $providerResults['duckduckgo'] = $this->pingUrl("https://duckduckgo.com");

        return $providerResults;
    }

    private function performCleanup() {
        // 1. Clean up stale logs (older than 7 days)
        // Note: Assuming a 'logs' table exists or just file cleanup
        $cleanedFiles = 0;
        $logDir = __DIR__ . '/../logs/';
        if (is_dir($logDir)) {
            foreach (glob($logDir . "*.log") as $file) {
                if (filemtime($file) < time() - (7 * 24 * 60 * 60)) {
                    unlink($file);
                    $cleanedFiles++;
                }
            }
        }
        return "Cleaned $cleanedFiles stale log files.";
    }

    public function auditProxyHealth() {
        $settings = $this->getSettings();
        if (empty($settings['proxy_list'])) {
            return "No proxies configured";
        }
        
        // In a real scenario, we'd test a random sample or a specific healthcheck endpoint
        // For now, we verify we can reach a common target via the system's default route 
        // to ensure the network stack is proxy-ready.
        return $this->pingUrl("https://api.ipify.org");
    }

    public function auditDiskSpace() {
        $free = disk_free_space(".");
        $total = disk_total_space(".");
        $used_percent = round((($total - $free) / $total) * 100, 2);
        
        if ($used_percent > 90) {
            return "Critical: $used_percent% used";
        }
        return "Healthy: $used_percent% used";
    }

    private function checkEnvironment() {
        $required = ['curl', 'mysqli', 'mbstring', 'json'];
        $missing = [];
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        return empty($missing) ? "All extensions loaded" : "Missing: " . implode(', ', $missing);
    }

    private function pingUrl($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 400) ? "Connected ($code)" : "Failed ($code)";
    }

    private function getSettings() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
            return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Exception $e) {
            error_log("Supervisor Settings Error: " . $e->getMessage());
            return [];
        }
    }
}
