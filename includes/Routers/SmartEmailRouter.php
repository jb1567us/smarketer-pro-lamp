<?php

declare(strict_types=1);

namespace App\Routers;

use App\Database;

class SmartEmailRouter {
    private $pdo;
    private $providers = [];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        // Rotation list covering all 14+ supported HTTP and SMTP providers
        $this->providers = [
            'resend', 'brevo', 'sendgrid', 'mailgun', 'mailjet', 
            'postmark', 'mailersend', 'mailtrap', 'zoho', 'netcore',
            'smtp', 'custom_smtp', 'sendpulse', 'amazon_ses', 'zoho_smtp', 'netcore_smtp'
        ]; 
    }

    public function send($to, $subject, $body, ?int $campaignId = null) {
        // Throttle gate (item 4): global per-minute + per-provider daily caps.
        // The per-campaign cap is enforced by the queue worker, where campaign
        // context exists. All caps default to unlimited/disabled, so this is a
        // no-op unless the admin configures them. Denials return false (never
        // throw) and are logged like the other guard refusals below.
        try {
            $throttles = new \App\Throttles(new \App\DbThrottleStore($this->pdo));
            $provider = \App\Database::getSetting('active_email_provider', 'smtp');
            $decision = $throttles->checkSend(
                null,
                $provider === 'smart_rotation' ? null : (string)$provider
            );
            if (!$decision->allowed) {
                $this->logEvent($to, 'guard', ['success' => false, 'error' => 'Throttled: ' . $decision->detail], $campaignId);
                echo "  [SmartRouter] Throttled: {$decision->detail}\n";
                return false;
            }
        } catch (\Throwable $e) {
            // Throttle infrastructure must never break sending; log and continue.
            error_log('[SmartRouter] throttle check failed (allowing send): ' . $e->getMessage());
        }

        // Fail fast on fabricated harvester placeholder addresses: no provider
        // loop, no wasted API calls, no reputation damage.
        if (is_string($to) && \App\EmailSender::isPlaceholderAddress($to)) {
            $this->logEvent($to, 'guard', ['success' => false, 'error' => 'Placeholder address refused'], $campaignId);
            echo "  [SmartRouter] Refused: placeholder recipient address.\n";
            return false;
        }

        $attempts = [];
        $rotator = new SmartRotationManager($this->pdo);

        foreach ($this->providers as $providerName) {
            // 1. Get raw API keys (can be comma-separated list of keys!)
            $rawKeys = Database::getSetting("{$providerName}_api_key");
            
            // SMTP-specific fallback: try loading smtp_pass if API key isn't specified
            if (empty($rawKeys) && in_array($providerName, ['smtp', 'custom_smtp', 'sendpulse', 'amazon_ses', 'zoho_smtp', 'netcore_smtp'])) {
                $rawKeys = Database::getSetting("{$providerName}_smtp_pass") ?: Database::getSetting('smtp_pass');
            }

            if (empty($rawKeys)) {
                $attempts[$providerName] = "Credentials Missing";
                continue;
            }

            // 2. Check if the provider is fully rate-limited
            if ($rotator->isServiceRateLimited($providerName, $rawKeys)) {
                $attempts[$providerName] = "Daily Limit Reached";
                continue;
            }

            // 3. Select active key
            $apiKey = $rotator->selectActiveKey($providerName, $rawKeys);
            if (!$apiKey) {
                $attempts[$providerName] = "Active Key Missing";
                continue;
            }

            // 4. Attempt Send
            echo "  [SmartRouter] Attempting delivery via $providerName...\n";
            $result = $this->attemptDelivery($providerName, $apiKey, $to, $subject, $body);

            // 5. Log Result & Key Usage
            if ($result['success']) {
                $rotator->logCall($providerName, $apiKey, 'success');
                $this->logEvent($to, $providerName, $result, $campaignId);
                echo "  [SmartRouter] Success via $providerName.\n";
                return true;
            } else {
                $rotator->logCall($providerName, $apiKey, 'failed', $result['error'] ?? 'Unknown Error');
                $this->logEvent($to, $providerName, $result, $campaignId);
                $attempts[$providerName] = $result['error'] ?? 'Unknown Error';
                echo "  [SmartRouter] Failed: " . ($result['error'] ?? 'Unknown Error') . "\n";
            }
        }

        return false;
    }

    private function logEvent($to, $provider, $result, ?int $campaignId = null) {
        $status = $result['success'] ? 'sent' : 'failed';
        $meta = json_encode(['error' => $result['error'] ?? null]);

        // campaign_id attribution (item 10): written when the compliance DDL
        // has added email_logs.campaign_id; skipped gracefully before that.
        if ($campaignId !== null && $campaignId > 0 && $this->emailLogsHasCampaignId()) {
            $stmt = $this->pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, campaign_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$to, $provider, $campaignId, $status, $meta, time()]);
            return;
        }
        $stmt = $this->pdo->prepare("INSERT INTO email_logs (lead_email, provider_id, status, metadata_json, timestamp) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$to, $provider, $status, $meta, time()]);
    }

    /** Cached probe: does email_logs.campaign_id exist yet? */
    private ?bool $emailLogsCampaignId = null;
    private function emailLogsHasCampaignId(): bool
    {
        if ($this->emailLogsCampaignId !== null) {
            return $this->emailLogsCampaignId;
        }
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS " .
                "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_logs' AND COLUMN_NAME = 'campaign_id' LIMIT 1"
            );
            $stmt->execute();
            return $this->emailLogsCampaignId = (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            return $this->emailLogsCampaignId = false;
        }
    }

    private function attemptDelivery($provider, $apiKey, $to, $subject, $body) {
        if (!$apiKey) {
            return ['success' => false, 'error' => 'API Key Missing'];
        }

        $senderEmail = \App\Database::getSetting('email_sender') ?: 'onboarding@resend.dev';
        $domain = \App\Database::getSetting('smtp_host'); // Used for Mailgun domain

        try {
            $success = \App\EmailSender::send($to, $subject, $body, $provider, $apiKey, $senderEmail, $domain);
            return ['success' => $success];
        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
