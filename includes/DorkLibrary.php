<?php
namespace App;

/**
 * DorkLibrary Class
 * Centralized repository for high-fidelity Google Dorks and search patterns.
 * Ported from stable B2B engines to ensure high-intent lead discovery.
 * 
 * Enhanced with persona-based presets and location expansion from b2b_email_stable.
 */
class DorkLibrary {
    
    /**
     * Core Dork Templates — parameterized with {keyword} and {location}
     * These are Google-optimized by default.
     */
    private static $templates = [
        // Social intent
        'linkedin_owner'    => 'site:linkedin.com/in "{keyword}" "owner" "{location}"',
        'linkedin_founder'  => 'site:linkedin.com/in "{keyword}" "founder" "{location}"',
        'linkedin_ceo'      => 'site:linkedin.com/in "{keyword}" "CEO" OR "Managing Director" "{location}"',
        'linkedin_director' => 'site:linkedin.com/in "{keyword}" "Director" OR "VP" "{location}"',
        'facebook_profile'  => 'site:facebook.com "{keyword}" "{location}"',
        'x_profile'         => 'site:x.com OR site:twitter.com "{keyword}" "{location}"',
        'instagram_profile' => 'site:instagram.com "{keyword}" "{location}"',
        'tiktok_profile'    => 'site:tiktok.com "@{keyword}" "{location}"',

        // Contact page mining
        'contact_page'     => '"{keyword}" "{location}" "contact us" -directory -yelp -yellowpages',
        'about_page'       => '"{keyword}" "{location}" "about us" "founded" -directory',

        // Email harvesting
        'email_hunt'       => '"{keyword}" "{location}" "@gmail.com" OR "@outlook.com" OR "@yahoo.com"',
        'email_domain'     => '"{keyword}" "{location}" "email" "contact" -directory -yelp',

        // Business signals
        'service_audit'    => '"{keyword}" "{location}" "services" "pricing" -coupon -review',
        'b2b_marker'       => '"{keyword}" "{location}" "partners" OR "vendors" OR "suppliers"',
        'hiring_intent'    => '"{keyword}" "{location}" "we are hiring" OR "careers" OR "join our team"',
        'growth_signal'    => '"{keyword}" "{location}" "new location" OR "expanding" OR "grand opening"',

        // Review/reputation
        'review_presence'  => '"{keyword}" "{location}" site:google.com/maps OR site:yelp.com',

        // Tech stack intel
        'uses_wordpress'   => 'inurl:wp-content "{keyword}" "{location}"',
        'uses_shopify'     => 'site:myshopify.com "{keyword}" "{location}"',
    ];

    /**
     * DuckDuckGo-Optimized Templates
     * DDG does not reliably support nested OR, complex site: chains, or long queries.
     * Each dork is a single-intent, compact query designed for DDG HTML scraping.
     */
    private static $ddgTemplates = [
        // Social intent — one per role, no OR chains
        'linkedin_owner'    => 'linkedin.com/in {keyword} owner {location}',
        'linkedin_founder'  => 'linkedin.com/in {keyword} founder {location}',
        'linkedin_ceo'      => 'linkedin.com/in {keyword} CEO {location}',
        'linkedin_director' => 'linkedin.com/in {keyword} director {location}',
        'facebook_profile'  => 'facebook.com {keyword} {location}',
        'x_profile'         => 'x.com {keyword} {location}',
        'instagram_profile' => 'instagram.com {keyword} {location}',
        'tiktok_profile'    => 'tiktok.com {keyword} {location}',

        // Contact page mining
        'contact_page'     => '{keyword} {location} "contact us" -yelp -yellowpages',
        'about_page'       => '{keyword} {location} "about us" founded',

        // Email harvesting — split per domain to avoid OR
        'email_gmail'      => '{keyword} {location} @gmail.com contact',
        'email_domain'     => '{keyword} {location} email contact -directory',

        // Business signals — one intent per query
        'service_audit'    => '{keyword} {location} services pricing',
        'b2b_marker'       => '{keyword} {location} partners vendors',
        'hiring_intent'    => '{keyword} {location} "we are hiring" careers',
        'growth_signal'    => '{keyword} {location} expanding "new location"',

        // Tech stack intel
        'uses_wordpress'   => '{keyword} {location} wp-content',
        'uses_shopify'     => '{keyword} {location} myshopify.com',
    ];

    /**
     * Persona Presets — curated dork combinations for specific outreach strategies
     */
    private static $personaPresets = [
        'decision_maker' => [
            'label' => 'Decision Makers (CEO/Founder/Owner)',
            'dorks' => ['linkedin_owner', 'linkedin_founder', 'linkedin_ceo', 'contact_page'],
        ],
        'social_platforms' => [
            'label' => 'Social Channels (LinkedIn/FB/X/IG/TikTok)',
            'dorks' => ['linkedin_founder', 'facebook_profile', 'x_profile', 'instagram_profile', 'tiktok_profile'],
        ],
        'marketing_lead' => [
            'label' => 'Marketing & Sales Leaders',
            'dorks' => ['linkedin_director', 'email_hunt', 'about_page'],
        ],
        'local_service' => [
            'label' => 'Local Service Businesses',
            'dorks' => ['contact_page', 'email_domain', 'service_audit', 'review_presence'],
        ],
        'growth_companies' => [
            'label' => 'Fast-Growing Companies',
            'dorks' => ['hiring_intent', 'growth_signal', 'b2b_marker', 'linkedin_ceo'],
        ],
        'tech_companies' => [
            'label' => 'Tech-Forward Businesses',
            'dorks' => ['uses_wordpress', 'uses_shopify', 'linkedin_founder', 'email_domain'],
        ],
        'full_sweep' => [
            'label' => 'Full Sweep (All Dorks)',
            'dorks' => 'all',
        ],
    ];

    /**
     * Metro area sub-location expansion data
     */
    private static $locationExpansions = [
        'austin' => [
            'Round Rock, TX', 'Pflugerville, TX', 'Cedar Park, TX',
            'West Lake Hills, TX', 'Georgetown, TX', 'San Marcos, TX',
            'Dripping Springs, TX', 'Lakeway, TX', 'Bee Cave, TX',
            'Kyle, TX', 'Buda, TX', 'Leander, TX',
        ],
        'san antonio' => [
            'New Braunfels, TX', 'Boerne, TX', 'Schertz, TX',
            'Cibolo, TX', 'Seguin, TX', 'Universal City, TX',
        ],
        'houston' => [
            'Sugar Land, TX', 'The Woodlands, TX', 'Pearland, TX',
            'Katy, TX', 'League City, TX', 'Missouri City, TX',
            'Pasadena, TX', 'Baytown, TX', 'Spring, TX',
        ],
        'dallas' => [
            'Plano, TX', 'Frisco, TX', 'McKinney, TX', 'Allen, TX',
            'Richardson, TX', 'Garland, TX', 'Irving, TX',
            'Arlington, TX', 'Grand Prairie, TX', 'Denton, TX',
        ],
    ];

    /**
     * Generate a list of dorks for a given keyword and location
     */
    public static function generateDorks(string $keyword, string $location, ?string $persona = null, ?string $provider = null): array {
        // Select template set based on provider
        $isDdg = ($provider === 'ddg');
        $templateSet = $isDdg ? self::$ddgTemplates : self::$templates;
        
        $selectedKeys = array_keys($templateSet);

        if ($persona && isset(self::$personaPresets[$persona])) {
            $preset = self::$personaPresets[$persona];
            if ($preset['dorks'] !== 'all') {
                $selectedKeys = $preset['dorks'];
            }
        }

        $dorks = [];
        foreach ($selectedKeys as $key) {
            if (isset($templateSet[$key])) {
                $dorks[$key] = str_replace(
                    ['{keyword}', '{location}'],
                    [$keyword, $location],
                    $templateSet[$key]
                );
            }
        }
        return $dorks;
    }

    /**
     * Get available persona presets for the UI
     */
    public static function getPersonaPresets(): array {
        $result = [];
        foreach (self::$personaPresets as $key => $preset) {
            $result[$key] = $preset['label'];
        }
        return $result;
    }

    /**
     * Multiplier Expansion: Break a broad location into specific sub-regions
     * for higher result volume.
     */
    public static function expandLocation(string $location): array {
        $expansions = [$location];
        $locationLower = strtolower(trim($location));
        
        // Check for known metro area matches
        foreach (self::$locationExpansions as $metro => $suburbs) {
            if (stripos($locationLower, $metro) !== false) {
                $expansions = array_merge($expansions, $suburbs);
                break;
            }
        }
        
        return $expansions;
    }

    /**
     * Get all template keys for reference
     */
    public static function getTemplateNames(): array {
        return array_keys(self::$templates);
    }
}
