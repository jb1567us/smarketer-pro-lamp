<?php
namespace App;

/**
 * ExtractionEngine Class
 * Handles high-fidelity data extraction from HTML and snippets.
 * Ported from stable Python toolsets for consistent lead quality.
 * 
 * Enhanced with:
 * - Tech stack detection (from b2b_outreach_tool/extractor.py)
 * - Phone number extraction
 * - Domain blocklist (from b2b_outreach_tool/config.yaml)
 * - Deep crawl link discovery
 */
class ExtractionEngine {
    
    // Garbage email username patterns — blocks noise
    private static $garbage_patterns = [
        'sentry', 'wix', 'example', 'domain', 'test', 'png', 'jpg', 'jpeg',
        'webpack', 'godaddy', 'react-dom', 'core@', 'lodash', 'parsley',
        'iconify', 'slick-carousel', '@1', '@2', '@3', '@4', 'component',
        'plugin', 'myemail@', 'johnsmith', 'noreply', 'no-reply', 'admin',
        'abuse', 'privacy', 'postmaster', 'webmaster', 'hostmaster',
        'info@example', 'name@example', 'user@example'
    ];

    // Domain blocklist — ported from b2b_outreach_tool config.yaml
    private static $blocked_domains = [
        // Social platforms
        'facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'linkedin.com',
        'youtube.com', 'vimeo.com', 'tiktok.com', 'pinterest.com', 'reddit.com',
        'quora.com', 'medium.com', 'substack.com', 'beehiiv.com',
        // E-commerce aggregators
        'amazon.com', 'shopify.com', 'myshopify.com', 'apple.com', 'itunes.apple.com',
        // SEO/Review platforms
        'semrush.com', 'ahrefs.com', 'moz.com', 'clutch.co', 'upcity.com',
        'g2.com', 'trustpilot.com', 'yelp.com', 'tripadvisor.com',
        // Directories
        'yellowpages.com', 'typeform.com',
        // News / media
        'cnn.com', 'bbc.com', 'nytimes.com', 'forbes.com', 'bloomberg.com',
        'reuters.com', 'businessinsider.com', 'techcrunch.com', 'wired.com',
        'entrepreneur.com', 'inc.com', 'fastcompany.com', 'hbr.org',
        // Search engines
        'google.com', 'bing.com', 'yahoo.com', 'duckduckgo.com',
        // Government/Education
        'wikipedia.org', 'statista.com',
    ];

    // Keywords that indicate a subpage likely has contact info
    private static $deep_crawl_keywords = [
        'contact', 'about', 'team', 'staff', 'leadership', 'location',
        'our-team', 'meet-the-team', 'get-in-touch', 'reach-us'
    ];

    /**
     * Clean and validate emails, handling common obfuscation
     */
    public static function extractEmails($html) {
        // Handle common [at] and [dot] obfuscation
        $cleanHtml = str_ireplace(
            [' [at] ', ' (at) ', ' [dot] ', ' (dot) ', '{at}', '{dot}'],
            ['@', '@', '.', '.', '@', '.'],
            $html
        );
        
        $pattern = '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i';
        preg_match_all($pattern, $cleanHtml, $matches);
        
        $emails = array_unique($matches[0] ?? []);
        return array_values(array_filter($emails, [self::class, 'isNotGarbage']));
    }

    /**
     * Filter out noise emails
     */
    public static function isNotGarbage($email) {
        $email = strtolower($email);
        foreach (self::$garbage_patterns as $pattern) {
            if (strpos($email, $pattern) !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extract phone numbers from HTML content
     */
    public static function extractPhones($html) {
        // Strip HTML tags to get clean text
        $text = strip_tags($html);
        
        $patterns = [
            '/\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/',          // (512) 555-1234 or 512-555-1234
            '/\+?1?[-.\s]?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', // +1 (512) 555-1234
            '/tel:([+\d\-\(\)\s]+)/',                              // tel: links
        ];
        
        $phones = [];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[0] as $phone) {
                    $cleaned = preg_replace('/[^\d+]/', '', $phone);
                    // Must be at least 10 digits
                    if (strlen($cleaned) >= 10 && strlen($cleaned) <= 15) {
                        $phones[] = $cleaned;
                    }
                }
            }
        }
        
        return array_unique($phones);
    }

    /**
     * Resolve social profiles from HTML
     */
    public static function extractSocials($html) {
        $socials = [];
        $patterns = [
            'facebook'  => '/facebook\.com\/([a-z0-9._-]+)/i',
            'linkedin'  => '/linkedin\.com\/(company|in)\/([a-z0-9._-]+)/i',
            'twitter'   => '/(twitter|x)\.com\/([a-z0-9._-]+)/i',
            'instagram' => '/instagram\.com\/([a-z0-9._-]+)/i',
        ];

        foreach ($patterns as $platform => $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $socials[$platform] = $matches[0];
            }
        }
        return $socials;
    }

    /**
     * Detect tech stack from HTML — ported from b2b_outreach_tool/extractor.py
     */
    public static function detectTechStack($html) {
        $stack = [];
        $lower = strtolower($html);

        // CMS / Platforms
        $cms = [
            'WordPress'   => ['wp-content', 'wp-includes'],
            'Shopify'     => ['cdn.shopify.com', 'shopify.checkout'],
            'Squarespace' => ['static1.squarespace.com'],
            'Wix'         => ['wix.com', 'x-wix-'],
            'Webflow'     => ['webflow'],
            'Drupal'      => ['drupal.org', 'sites/default/files'],
            'Joomla'      => ['/media/jui/', '/components/com_'],
            'HubSpot'     => ['hs-scripts.com', 'hubspot.com'],
        ];
        foreach ($cms as $name => $markers) {
            foreach ($markers as $marker) {
                if (strpos($lower, $marker) !== false) {
                    $stack[] = $name;
                    break;
                }
            }
        }

        // Frameworks / Libs
        $frameworks = [
            'React'     => ['react', 'data-reactid'],
            'Next.js'   => ['__next_data__', '_next/static'],
            'Vue.js'    => ['data-v-', 'vue.js'],
            'jQuery'    => ['jquery'],
            'Bootstrap' => ['bootstrap'],
            'Tailwind'  => ['tailwindcss', 'tailwind'],
        ];
        foreach ($frameworks as $name => $markers) {
            foreach ($markers as $marker) {
                if (strpos($lower, $marker) !== false) {
                    $stack[] = $name;
                    break;
                }
            }
        }

        // Analytics / Ads
        $analytics = [
            'Google Analytics' => ['googletagmanager', 'ua-', 'gtag('],
            'Facebook Pixel'   => ['fbq(', 'facebook-pixel'],
            'Hotjar'           => ['hotjar'],
            'HubSpot Tracking' => ['hs-analytics'],
        ];
        foreach ($analytics as $name => $markers) {
            foreach ($markers as $marker) {
                if (strpos($lower, $marker) !== false) {
                    $stack[] = $name;
                    break;
                }
            }
        }

        return array_unique($stack);
    }

    /**
     * Check if a URL belongs to a blocked domain
     */
    public static function isBlockedDomain($url) {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;
        $host = strtolower(preg_replace('/^www\./', '', $host));

        foreach (self::$blocked_domains as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find internal links that might contain contact info
     */
    public static function findContactLinks($html, $baseUrl) {
        $links = [];
        $baseDomain = parse_url($baseUrl, PHP_URL_HOST);
        
        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $href) {
                // Resolve relative URLs
                if (strpos($href, '//') === false && strpos($href, '/') === 0) {
                    $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';
                    $href = $scheme . '://' . $baseDomain . $href;
                }
                
                $linkHost = parse_url($href, PHP_URL_HOST);
                if ($linkHost && strtolower($linkHost) === strtolower($baseDomain)) {
                    $lowerHref = strtolower($href);
                    foreach (self::$deep_crawl_keywords as $kw) {
                        if (strpos($lowerHref, $kw) !== false) {
                            $links[] = $href;
                            break;
                        }
                    }
                }
            }
        }
        
        return array_unique(array_slice($links, 0, 5)); // Max 5 subpages
    }

    /**
     * Full extraction pipeline for a single URL — fetches and extracts everything
     */
    public static function fullExtract($url) {
        if (self::isBlockedDomain($url)) {
            return ['url' => $url, 'blocked' => true, 'reason' => 'Domain is on blocklist'];
        }

        $html = self::fetchPage($url);
        if (!$html) {
            return ['url' => $url, 'error' => 'Failed to fetch page'];
        }

        $emails = self::extractEmails($html);
        $socials = self::extractSocials($html);
        $phones = self::extractPhones($html);
        $techStack = self::detectTechStack($html);
        
        // Deep crawl contact subpages
        $contactLinks = self::findContactLinks($html, $url);
        foreach ($contactLinks as $subUrl) {
            $subHtml = self::fetchPage($subUrl);
            if ($subHtml) {
                $emails = array_merge($emails, self::extractEmails($subHtml));
                $phones = array_merge($phones, self::extractPhones($subHtml));
            }
        }

        return [
            'url'           => $url,
            'emails'        => array_values(array_unique($emails)),
            'phones'        => array_values(array_unique($phones)),
            'socials'       => $socials,
            'tech_stack'    => $techStack,
            'pages_crawled' => 1 + count($contactLinks),
            'html'          => $html,
        ];
    }

    /**
     * Simple cURL page fetcher with proxy rotation
     */
    private static function fetchPage($url) {
        $pdo = \App\Database::getConnection();
        require_once __DIR__ . '/ProxyManager.php';
        $pm = new \ProxyManager($pdo);
        $proxyUrl = $pm->getProxy();

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        if ($proxyUrl) {
            $cleanProxy = preg_replace('/^https?:\/\//i', '', $proxyUrl);
            $parts = explode(':', $cleanProxy);
            
            if (count($parts) >= 4) {
                $options[CURLOPT_PROXY] = $parts[0] . ':' . $parts[1];
                $options[CURLOPT_PROXYUSERPWD] = $parts[2] . ':' . $parts[3];
            } else {
                $options[CURLOPT_PROXY] = $cleanProxy;
            }
        }

        curl_setopt_array($ch, $options);
        $html = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Report failure to ProxyManager if cURL failed or returned proxy block codes (e.g. 407, 0)
        if ($proxyUrl && ($html === false || $code === 0 || $code === 407)) {
            $pm->reportFailure($proxyUrl);
        }
        
        curl_close($ch);
        
        return ($html && $code === 200) ? $html : null;
    }
}
