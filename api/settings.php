<?php
/**
 * Settings API.
 *
 * Security model:
 *  - GET  never returns secret values in plaintext. Secrets come back as ""
 *         with a parallel `secrets_set` map so the UI can show "configured".
 *  - POST/PUT only accept allowlisted keys, require a CSRF token, and treat
 *         an empty secret value as "leave unchanged" (the UI posts "" for
 *         secrets it redacted on read, so this prevents accidental wipes).
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/autoload.php';
\App\Auth::requireApiAuth();
$pdo = \App\Database::getConnection();

/** Keys the Settings UI (and internal tooling) is permitted to write. */
const SETTINGS_ALLOWLIST = [
    'operational_mode', 'active_llm_provider', 'ollama_url', 'openrouter_model',
    'gemini_api_key', 'openai_api_key', 'anthropic_api_key', 'groq_api_key',
    'mistral_api_key', 'openrouter_api_key',
    'jev_enabled', 'jev_mode', 'jev_api_key', 'jev_model', 'jev_min_confidence',
    'active_search_provider', 'fallback_search_provider', 'failover_threshold',
    'search_consecutive_failures',
    'scrapingant_api_key', 'scrapingant_api_key_backup',
    'firecrawl_api_key', 'firecrawl_api_key_backup',
    'serper_api_key', 'tavily_api_key', 'exa_api_key', 'searxng_url', 'apify_api_token',
    'scraper_priority', 'bright_data_proxy_url', 'proxy_rotation_enabled',
    'active_email_provider', 'email_sender',
    'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption',
    'resend_api_key', 'brevo_api_key', 'sendgrid_api_key', 'mailgun_api_key',
    'mailgun_domain', 'mailjet_api_key', 'postmark_api_key', 'mailersend_api_key',
    'mailtrap_api_key', 'zoho_api_key', 'netcore_api_key',
    'sendpulse_smtp_pass', 'amazon_ses_smtp_pass', 'zoho_smtp_pass', 'netcore_smtp_pass',
    'proxy_enabled', 'proxy_socks_url', 'proxy_verify_url',
    'wp_site_url', 'wp_username', 'wp_app_password',
    // Compliance (CAN-SPAM / CASL sender identity)
    'company_legal_name', 'physical_address', 'app_base_url',
    'compliance_casl_ca_block', 'compliance_casl_unknown_country',
    // DNS preflight (SPF/DKIM/DMARC check on campaign start): result cache TTL in hours
    'dns_preflight_cache_hours',
    // Compliance item 4: send throttles + complaint/bounce monitor.
    // Per-provider overrides (throttle_provider_daily_cap_<provider>) are
    // matched by prefix — see SETTINGS_DYNAMIC_PREFIXES.
    'throttle_sends_per_minute', 'throttle_provider_daily_cap',
    'throttle_defer_minutes',
    'monitor_complaint_rate_threshold', 'monitor_bounce_rate_threshold',
    'monitor_auto_pause', 'monitor_min_delivered',
    // Soft phone-home license lock. license_key is secret (redacted on read).
    'license_server_url', 'license_key',
    // Email verification (per-send gate + bulk verify). The gate
    // (includes/Compliance.php) reads these keys; default stays OFF
    // ('verification_required' = '0'). verification_api_key is secret by
    // the `_api_key` suffix rule below — redacted on read exactly like the
    // other provider keys.
    'verification_required', 'verification_provider', 'verification_api_key',
    'verification_risky_action', 'verification_strict', 'verification_cache_days',
    'verification_bulk_batch_size', 'verification_bulk_delay_ms',
    'verification_bulk_max_unknown_streak',
];

/**
 * Key prefixes accepted dynamically (exact provider suffix varies, e.g.
 * throttle_provider_daily_cap_sendgrid). Values go through the same scalar
 * validation and upsert path as allowlisted keys.
 */
const SETTINGS_DYNAMIC_PREFIXES = ['throttle_provider_daily_cap_'];

function isAllowedSetting(string $key): bool
{
    if (in_array($key, SETTINGS_ALLOWLIST, true)) {
        return true;
    }
    foreach (SETTINGS_DYNAMIC_PREFIXES as $prefix) {
        if (str_starts_with($key, $prefix) && strlen($key) > strlen($prefix)) {
            return true;
        }
    }
    return false;
}

/** Suffixes (plus explicit keys) treated as secrets: redacted on read. */
const SETTINGS_SECRET_SUFFIXES = ['_api_key', '_api_token', '_pass', '_password', '_secret', '_token'];
const SETTINGS_SECRET_EXPLICIT = ['proxy_socks_url', 'license_key'];

function isSecretSetting(string $key): bool
{
    if (in_array($key, SETTINGS_SECRET_EXPLICIT, true)) {
        return true;
    }
    foreach (SETTINGS_SECRET_SUFFIXES as $suffix) {
        if (substr($key, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    return false;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
        $settings = $stmt->fetchAll(\App\PDO::FETCH_KEY_PAIR);
        $secretsSet = [];
        foreach ($settings as $key => $value) {
            if (isSecretSetting($key)) {
                $secretsSet[$key] = ($value !== null && $value !== '');
                $settings[$key] = '';
            }
        }
        echo json_encode(['success' => true, 'data' => $settings, 'secrets_set' => $secretsSet]);
    } elseif ($method === 'POST' || $method === 'PUT') {
        \App\Auth::requireCsrf();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
            exit;
        }
        unset($data['csrf_token']); // transport-only, never a setting
        $updated = 0;
        $skippedSecrets = 0;
        foreach ($data as $key => $value) {
            if (!is_string($key) || !isAllowedSetting($key)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Unknown setting key: ' . substr((string)$key, 0, 64)]);
                exit;
            }
            if (!is_scalar($value) && $value !== null) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid value for setting: ' . $key]);
                exit;
            }
            $value = $value === null ? '' : (string)$value;
            if (isSecretSetting($key) && $value === '') {
                $skippedSecrets++; // redacted on read -> leave stored secret unchanged
                continue;
            }
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
            $updated++;
        }
        echo json_encode(['success' => true, 'updated' => $updated, 'secrets_unchanged' => $skippedSecrets]);
    } else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    // Never leak internals (which could include secrets-adjacent context).
    error_log('[settings.php] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Settings request failed']);
}
