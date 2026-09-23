<?php

declare(strict_types=1);

namespace App\Verification;

class DNSChecker
{
    /**
     * Check if a domain is receptive to emails.
     *
     * Strategy:
     * 1. Check for MX records (standard email routers).
     * 2. If no MX, check for A records (RFC 5321 fallback).
     * 3. If neither, domain is not receptive.
     *
     * @param string $domain The target domain to check (e.g., "example.com").
     * @param float $timeout The timeout in seconds (not directly supported by dns_get_record, but used for API signature compatibility).
     * @return array Result array with 'is_receptive', 'has_mx', 'mx_records', 'has_a', and 'error'.
     */
    public static function checkReceptivity(string $domain, float $timeout = 5.0): array
    {
        $domain = trim(strtolower($domain));
        
        // Basic safety validation
        if (empty($domain) || !filter_var("http://" . $domain, FILTER_VALIDATE_URL)) {
            return [
                'is_receptive' => false,
                'has_mx'       => false,
                'mx_records'   => [],
                'has_a'        => false,
                'error'        => 'invalid_domain'
            ];
        }

        $mxRecords = [];
        $hasMX = false;
        
        // 1. Query MX records
        try {
            $hosts = [];
            $weights = [];
            if (getmxrr($domain, $hosts, $weights)) {
                $hasMX = true;
                foreach ($hosts as $i => $host) {
                    $mxRecords[] = $host;
                }
            }
        } catch (\Exception $e) {
            error_log("[DNSChecker] MX lookup error for {$domain}: " . $e->getMessage());
        }

        // Fallback or double-check via dns_get_record if empty
        if (empty($mxRecords)) {
            try {
                $records = @dns_get_record($domain, DNS_MX);
                if (is_array($records) && count($records) > 0) {
                    $hasMX = true;
                    foreach ($records as $record) {
                        if (isset($record['target'])) {
                            $mxRecords[] = $record['target'];
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("[DNSChecker] dns_get_record MX lookup error for {$domain}: " . $e->getMessage());
            }
        }

        // 2. Fallback to A record (RFC 5321 compliance)
        $hasA = false;
        $ip = '';
        if (!$hasMX) {
            try {
                $ip = @gethostbyname($domain);
                if ($ip !== $domain && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $hasA = true;
                }
            } catch (\Exception $e) {
                error_log("[DNSChecker] A record lookup error for {$domain}: " . $e->getMessage());
            }
        }

        $isReceptive = $hasMX || $hasA;

        return [
            'is_receptive' => $isReceptive,
            'has_mx'       => $hasMX,
            'mx_records'   => array_unique($mxRecords),
            'has_a'        => $hasA,
            'error'        => $isReceptive ? null : 'nxdomain'
        ];
    }
}
