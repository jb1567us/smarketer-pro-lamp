<?php
$host = '127.0.0.1';
$port = '33306';
$username = 'root';
$password = '';
$dbname = 'lookoverhere_wp947';

$settings = [
    'operational_mode' => 'Safety',
    // AI Providers
    'gemini_api_key' => '',
    'openai_api_key' => '',
    'anthropic_api_key' => '',
    'groq_api_key' => '',
    'mistral_api_key' => '',
    'openrouter_api_key' => '',
    // Search & Scraping
    'tavily_api_key' => '',
    'exa_api_key' => '',
    'firecrawl_api_key' => '',
    'searxng_url' => 'http://localhost:8080/search',
    'apify_api_token' => '',
    'scraper_priority' => 'firecrawl,exa,tavily,searxng',
    // Proxy
    'bright_data_proxy_url' => '',
    'proxy_rotation_enabled' => 'false',
    // Email / SMTP
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => '587',
    'smtp_user' => '',
    'smtp_pass' => '',
    'smtp_encryption' => 'tls',
    // Branding
    'command_center_name' => 'Revenue Intelligence Pro'
];

try {
    $pdo = new \PDO("mysql:host={$host};port={$port};dbname={$dbname}", $username, $password);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

    foreach ($settings as $key => $value) {
        $stmt->execute([$key, $value]);
    }

    echo "Mission Control settings seeded successfully.\n";

} catch (\Exception $e) {
    echo "Seeding failed: " . $e->getMessage();
}
