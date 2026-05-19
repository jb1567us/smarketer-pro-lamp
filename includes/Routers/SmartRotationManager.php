<?php
namespace App\Routers;

class SmartRotationManager {
    private $db;
    
    // Default free-tier limits per key per day
    private static $freeTierDailyLimits = [
        'tavily' => 30,         // ~1,000 / month
        'exa' => 30,            // ~1,000 / month
        'scrapingant' => 300,    // ~10,000 / month
        'firecrawl' => 15,       // ~500 / month
        'serper' => 80,         // ~2,500 total
        'gemini' => 1500,       // 1,500 requests per day (15 RPM)
        'groq' => 14400,        // 14,400 requests per day
        'brevo' => 300,         // 300 emails per day
        'resend' => 100,        // 100 emails per day
        'sendgrid' => 100,      // 100 emails per day
        'mailjet' => 200,       // 200 emails per day
        'mailersend' => 100,    // 100 emails per day
    ];

    public function __construct($pdo = null) {
        if ($pdo === null) {
            $pdo = \App\Database::getConnection();
        }
        $this->db = $pdo;
    }

    /**
     * Parse a comma-separated key string or fallback array.
     */
    public function getKeys($rawKeys): array {
        if (empty($rawKeys)) return [];
        if (is_array($rawKeys)) return $rawKeys;
        
        $keys = explode(',', $rawKeys);
        return array_values(array_filter(array_map('trim', $keys)));
    }

    /**
     * Get the next available active API key for a given service.
     * Takes into account:
     * 1. Masked key daily usage since midnight UTC against freeTierDailyLimits
     * 2. Consecutive failures or temporal cool-downs
     */
    public function selectActiveKey(string $service, $rawKeys): ?string {
        $keys = $this->getKeys($rawKeys);
        if (empty($keys)) return null;

        $midnight = strtotime('today midnight UTC');

        foreach ($keys as $key) {
            $maskedKey = $this->maskKey($key);

            // Check if key is in active cool-down (e.g. has a failure in the last 15 minutes)
            if ($this->isKeyCoolingDown($service, $maskedKey)) {
                continue;
            }

            // Check if key has exceeded its free tier daily limit
            $usage = $this->getKeyDailyUsage($service, $maskedKey, $midnight);
            $limit = self::$freeTierDailyLimits[$service] ?? 999999;

            if ($usage < $limit) {
                return $key; // Found a working key that is not limited and not in cooldown!
            }
        }

        // Fallback: if ALL keys are rate-limited or in cooldown, return the first key as a hail-mary
        return $keys[0] ?? null;
    }

    /**
     * Logs an API call transaction outcome.
     */
    public function logCall(string $service, string $key, string $status, ?string $errorMessage = null) {
        $maskedKey = $this->maskKey($key);
        $timestamp = time();

        // Remember that App\PDO only supports positional (?) placeholders!
        $stmt = $this->db->prepare("INSERT INTO api_usage_logs (service_name, api_key_masked, status, error_message, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$service, $maskedKey, $status, $errorMessage, $timestamp]);
    }

    /**
     * Check if a specific service is overall rate-limited.
     */
    public function isServiceRateLimited(string $service, $rawKeys): bool {
        $keys = $this->getKeys($rawKeys);
        if (empty($keys)) return true;

        $midnight = strtotime('today midnight UTC');
        $limit = self::$freeTierDailyLimits[$service] ?? 999999;

        foreach ($keys as $key) {
            $maskedKey = $this->maskKey($key);
            $usage = $this->getKeyDailyUsage($service, $maskedKey, $midnight);
            if ($usage < $limit) {
                return false; // At least one key has remaining quota
            }
        }

        return true;
    }

    /**
     * Returns the sum of usage across all keys for a service since midnight.
     */
    public function getServiceTotalDailyUsage(string $service, $rawKeys): int {
        $keys = $this->getKeys($rawKeys);
        if (empty($keys)) return 0;

        $midnight = strtotime('today midnight UTC');
        $total = 0;

        foreach ($keys as $key) {
            $maskedKey = $this->maskKey($key);
            $total += $this->getKeyDailyUsage($service, $maskedKey, $midnight);
        }

        return $total;
    }

    /**
     * Get successful usage for a specific key since midnight.
     */
    private function getKeyDailyUsage(string $service, string $maskedKey, int $sinceTimestamp): int {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM api_usage_logs WHERE service_name = ? AND api_key_masked = ? AND status = 'success' AND timestamp >= ?");
        $stmt->execute([$service, $maskedKey, $sinceTimestamp]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Checks if a key has any failure in the last 15 minutes (900 seconds) without a succeeding success.
     */
    private function isKeyCoolingDown(string $service, string $maskedKey): bool {
        $cooldownWindow = time() - 900;

        // Check if there are any failures in the last 15 minutes
        $stmt = $this->db->prepare("SELECT timestamp FROM api_usage_logs WHERE service_name = ? AND api_key_masked = ? AND status = 'failed' AND timestamp >= ? ORDER BY timestamp DESC LIMIT 1");
        $stmt->execute([$service, $maskedKey, $cooldownWindow]);
        $lastFailure = $stmt->fetchColumn();

        if (!$lastFailure) {
            return false;
        }

        // Check if there has been a success AFTER that failure
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM api_usage_logs WHERE service_name = ? AND api_key_masked = ? AND status = 'success' AND timestamp > ?");
        $stmt->execute([$service, $maskedKey, (int)$lastFailure]);
        $successesAfterFailure = (int)$stmt->fetchColumn();

        return $successesAfterFailure === 0;
    }

    /**
     * Helper to mask sensitive keys for DB logging.
     */
    public function maskKey(string $key): string {
        $key = trim($key);
        if (strlen($key) <= 8) {
            return str_repeat('*', strlen($key));
        }
        return substr($key, 0, 4) . '...' . substr($key, -4);
    }
}
