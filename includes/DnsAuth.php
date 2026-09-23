<?php

declare(strict_types=1);

namespace App;

/**
 * DnsAuth — SPF / DKIM / DMARC preflight for the sender domain.
 *
 * Sending from a domain with none of SPF, DKIM, or DMARC published is the
 * fastest way to land every campaign in spam (or get rejected outright).
 * This class answers one question, cheaply: "is the sender's domain
 * authenticated for email?"
 *
 * Design:
 *  - RECORD PARSING IS PURE. parseSpf(), parseDkim(), parseDmarc() and
 *    summarize() take plain string arrays and never touch the network, so
 *    they are unit-testable offline (see tests/compliance/DnsAuthTest.php).
 *  - Network goes only through checkDomain(), which uses PHP's
 *    dns_get_record() (TXT lookups) — no external HTTP services, no API keys.
 *  - checkDomainCached() layers a small DB cache (domain_auth_cache) over
 *    checkDomain() so a campaign start does not pay for ~12 DNS lookups
 *    every time. It degrades to a live lookup when the cache table is
 *    missing (migration not yet applied).
 *
 * Status semantics:
 *  - SPF:   'pass' record found | 'fail' none found | 'error' bad domain.
 *  - DKIM:  'pass' >=1 selector found | 'warn' none found | 'fail' bad domain.
 *           Absence of DKIM alone is a WARNING, not a failure — many small
 *           senders publish SPF+DMARC only; blocking them would be wrong.
 *  - DMARC: 'pass' record with p= policy found | 'fail' none/unparseable |
 *           'error' bad domain.
 *  - summary: 'pass' all present | 'warn' at least one absent |
 *             'fail' all three absent (or the domain input itself is invalid).
 *
 * Note on dns_get_record(): a lookup failure (timeout, SERVFAIL) is
 * indistinguishable from "no records" through this API, so both surface as
 * 'fail'/'warn'. The 'error' status is reserved for invalid domain input.
 */
class DnsAuth
{
    /**
     * DKIM selectors probed at <selector>._domainkey.<domain>. Covers the
     * common defaults of major providers (Google, Microsoft 365
     * selector1/selector2, Mailchimp k1, generic s1/s2/default/mail/dkim).
     * 'everlytick' is this product's own historical selector.
     */
    public const DKIM_SELECTORS = [
        'default', 'google', 'k1', 's1', 's2',
        'selector1', 'selector2', 'mail', 'dkim', 'everlytick',
    ];

    // ------------------------------------------------------------------
    // Pure record parsing (no network — safe for offline tests)
    // ------------------------------------------------------------------

    /**
     * Return the first TXT record that is an SPF record, else null.
     * A record counts as SPF when it starts with the 'v=spf1' version tag
     * (case-insensitive). When several TXT records exist, the first SPF one
     * wins — deterministic by input order.
     *
     * @param string[] $txtRecords Raw TXT strings for the domain.
     */
    public static function parseSpf(array $txtRecords): ?string
    {
        foreach ($txtRecords as $txt) {
            if (!is_string($txt)) {
                continue;
            }
            if (preg_match('/^\s*v=spf1(?![a-z0-9])/i', $txt) === 1) {
                return $txt;
            }
        }
        return null;
    }

    /**
     * Return the selectors (in probe order) whose TXT records contain a
     * DKIM version tag ('v=DKIM1', case-insensitive).
     *
     * @param array<string, string[]|null> $recordsBySelector selector => TXT
     *        strings for <selector>._domainkey.<domain> (null = no records).
     * @return string[]
     */
    public static function parseDkim(array $recordsBySelector): array
    {
        $found = [];
        foreach (self::DKIM_SELECTORS as $selector) {
            if (!array_key_exists($selector, $recordsBySelector)) {
                continue;
            }
            $txts = $recordsBySelector[$selector];
            if (!is_array($txts)) {
                continue;
            }
            foreach ($txts as $txt) {
                if (is_string($txt) && preg_match('/(?:^|;)\s*v=DKIM1(?![a-z0-9])/i', $txt) === 1) {
                    $found[] = $selector;
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * Return the DMARC record and its p= policy (none|quarantine|reject).
     * A record without a parseable p= tag is still returned, but the policy
     * is null (treated as 'fail' — a policy-less DMARC record does nothing).
     *
     * @param string[] $txtRecords Raw TXT strings for _dmarc.<domain>.
     * @return array{record: ?string, policy: ?string}
     */
    public static function parseDmarc(array $txtRecords): array
    {
        foreach ($txtRecords as $txt) {
            if (!is_string($txt)) {
                continue;
            }
            if (preg_match('/(?:^|;)\s*v=DMARC1(?![a-z0-9])/i', $txt) === 1) {
                $policy = null;
                if (preg_match('/(?:^|;)\s*p\s*=\s*(none|quarantine|reject)/i', $txt, $m) === 1) {
                    $policy = strtolower($m[1]);
                }
                return ['record' => $txt, 'policy' => $policy];
            }
        }
        return ['record' => null, 'policy' => null];
    }

    /**
     * Summary from the three mechanism statuses.
     * 'pass'  — all three present.
     * 'warn'  — at least one present, at least one absent.
     * 'fail'  — all three absent, or the domain input was invalid ('error').
     */
    public static function summarize(string $spfStatus, string $dkimStatus, string $dmarcStatus): string
    {
        $spfOk   = $spfStatus === 'pass';
        $dkimOk  = $dkimStatus === 'pass';
        $dmarcOk = $dmarcStatus === 'pass';
        if ($spfOk && $dkimOk && $dmarcOk) {
            return 'pass';
        }
        if (!$spfOk && !$dkimOk && !$dmarcOk) {
            return 'fail';
        }
        return 'warn';
    }

    /**
     * Human-readable "what's missing" lines for a report, naming the exact
     * records the admin must publish. Used in block messages and UI toasts.
     *
     * @param array<string,mixed> $report A checkDomain()/checkDomainCached() report.
     * @return string[]
     */
    public static function missingDescriptions(array $report): array
    {
        $domain = (string)($report['domain'] ?? '');
        $missing = [];

        $spfStatus = (string)(is_array($report['spf'] ?? null) ? $report['spf']['status'] : 'fail');
        $dkimStatus = (string)(is_array($report['dkim'] ?? null) ? $report['dkim']['status'] : 'fail');
        $dmarcStatus = (string)(is_array($report['dmarc'] ?? null) ? $report['dmarc']['status'] : 'fail');

        if ($spfStatus !== 'pass') {
            $missing[] = $spfStatus === 'error'
                ? "SPF: could not check '{$domain}' (invalid domain)."
                : "SPF: no TXT record starting with 'v=spf1' on {$domain}.";
        }
        if ($dkimStatus !== 'pass') {
            $missing[] = "DKIM: no TXT record containing 'v=DKIM1' at <selector>._domainkey.{$domain} "
                . '(probed selectors: ' . implode(', ', self::DKIM_SELECTORS) . ').';
        }
        if ($dmarcStatus !== 'pass') {
            $missing[] = $dmarcStatus === 'error'
                ? "DMARC: could not check _dmarc.{$domain} (invalid domain)."
                : "DMARC: no TXT record containing 'v=DMARC1' at _dmarc.{$domain}.";
        }
        return $missing;
    }

    /**
     * Conservative hostname validation (ASCII labels; punycode xn-- domains
     * pass). Trailing dots are tolerated.
     */
    public static function isValidDomain(string $domain): bool
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }
        foreach (explode('.', $domain) as $label) {
            $len = strlen($label);
            if ($len < 1 || $len > 63) {
                return false;
            }
            if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * Extract the sender domain from an email address ("News@Example.COM "
     * -> "example.com"). Returns null when there is no usable domain.
     */
    public static function senderDomain(string $email): ?string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return null;
        }
        $domain = strtolower(rtrim(trim(substr($email, $at + 1)), '.'));
        return self::isValidDomain($domain) ? $domain : null;
    }

    // ------------------------------------------------------------------
    // Network: raw DNS TXT lookups only
    // ------------------------------------------------------------------

    /**
     * Raw TXT strings for a DNS name via dns_get_record(). Returns [] on
     * any failure (NXDOMAIN, timeout, SERVFAIL) — see class docblock.
     *
     * @return string[]
     */
    public static function fetchTxt(string $name): array
    {
        $out = [];
        $records = @dns_get_record($name, DNS_TXT);
        if (!is_array($records)) {
            return $out;
        }
        foreach ($records as $r) {
            if (isset($r['txt']) && is_string($r['txt'])) {
                $out[] = $r['txt'];
            }
        }
        return $out;
    }

    /**
     * Full preflight report for a domain. Pure-DNS; no external services.
     *
     * @return array{domain: string,
     *   spf: array{status: string, record: ?string},
     *   dkim: array{status: string, selectors_found: string[]},
     *   dmarc: array{status: string, record: ?string, policy: ?string},
     *   summary: string, missing: string[], checked_at: string,
     *   ...} plus 'error' on invalid input.
     */
    public static function checkDomain(string $domain): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $checkedAt = date('c');

        if (!self::isValidDomain($domain)) {
            $err = "Invalid domain '{$domain}' — expected a hostname like 'example.com'.";
            return [
                'domain' => $domain,
                'spf'    => ['status' => 'error', 'record' => null],
                'dkim'   => ['status' => 'fail', 'selectors_found' => []],
                'dmarc'  => ['status' => 'error', 'record' => null, 'policy' => null],
                'summary' => 'fail',
                'error'   => $err,
                'missing' => ['Sender domain is not a valid hostname — no DNS checks were possible.'],
                'checked_at' => $checkedAt,
            ];
        }

        $spfRecord = self::parseSpf(self::fetchTxt($domain));
        $spfStatus = $spfRecord !== null ? 'pass' : 'fail';

        $bySelector = [];
        foreach (self::DKIM_SELECTORS as $selector) {
            $bySelector[$selector] = self::fetchTxt($selector . '._domainkey.' . $domain);
        }
        $dkimFound = self::parseDkim($bySelector);
        $dkimStatus = count($dkimFound) > 0 ? 'pass' : 'warn';

        $dmarc = self::parseDmarc(self::fetchTxt('_dmarc.' . $domain));
        $dmarcStatus = ($dmarc['record'] !== null && $dmarc['policy'] !== null) ? 'pass' : 'fail';

        $report = [
            'domain' => $domain,
            'spf'    => ['status' => $spfStatus, 'record' => $spfRecord],
            'dkim'   => ['status' => $dkimStatus, 'selectors_found' => $dkimFound],
            'dmarc'  => ['status' => $dmarcStatus, 'record' => $dmarc['record'], 'policy' => $dmarc['policy']],
            'summary' => self::summarize($spfStatus, $dkimStatus, $dmarcStatus),
            'checked_at' => $checkedAt,
        ];
        $report['missing'] = self::missingDescriptions($report);
        return $report;
    }

    // ------------------------------------------------------------------
    // Cache (best-effort; degrades when the table is missing)
    // ------------------------------------------------------------------

    /**
     * checkDomain() with a DB-backed result cache.
     *
     * Setting 'dns_preflight_cache_hours' (default '24') controls TTL; 0 or
     * negative disables caching. When the domain_auth_cache table is missing
     * (migration not yet applied) or any DB error occurs, this silently
     * falls back to a live lookup — the campaign flow must never break
     * because a cache table is absent.
     */
    public static function checkDomainCached(string $domain): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        $hours = (int)(Database::getSetting('dns_preflight_cache_hours', '24') ?? '24');

        if ($hours > 0) {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare('SELECT result_json, checked_at FROM domain_auth_cache WHERE domain = ?');
                $stmt->execute([$domain]);
                $row = $stmt->fetch();
                if ($row && isset($row['result_json'])) {
                    $age = time() - (int)strtotime((string)$row['checked_at']);
                    if ($age >= 0 && $age < $hours * 3600) {
                        $cached = json_decode((string)$row['result_json'], true);
                        if (is_array($cached)) {
                            $cached['cached'] = true;
                            return $cached;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Table missing or DB hiccup: fall through to a live lookup.
            }
        }

        $report = self::checkDomain($domain);
        $report['cached'] = false;

        if ($hours > 0) {
            try {
                $pdo = Database::getConnection();
                $stmt = $pdo->prepare(
                    'INSERT INTO domain_auth_cache (domain, result_json, checked_at) VALUES (?, ?, NOW()) ' .
                    'ON DUPLICATE KEY UPDATE result_json = VALUES(result_json), checked_at = NOW()'
                );
                $stmt->execute([$domain, json_encode($report)]);
            } catch (\Throwable $e) {
                // Cache is best-effort; the live report is still returned.
            }
        }
        return $report;
    }

    // ------------------------------------------------------------------
    // Per-campaign override flag (degrades when the column is missing)
    // ------------------------------------------------------------------

    /**
     * Read the per-campaign dns_preflight_override flag. Returns false when
     * the column does not exist yet (pre-migration) instead of fataling.
     */
    public static function campaignOverride(\PDO $pdo, int $campaignId): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT dns_preflight_override FROM campaigns WHERE id = ?');
            $stmt->execute([$campaignId]);
            $row = $stmt->fetch();
            return $row ? (bool)$row['dns_preflight_override'] : false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Set/clear the per-campaign override flag. No-op when the column does
     * not exist yet — the in-memory gate decision still applies for the
     * current request, it just won't persist until the migration runs.
     */
    public static function setCampaignOverride(\PDO $pdo, int $campaignId, bool $enabled): void
    {
        try {
            $stmt = $pdo->prepare('UPDATE campaigns SET dns_preflight_override = ? WHERE id = ?');
            $stmt->execute([$enabled ? 1 : 0, $campaignId]);
        } catch (\Throwable $e) {
            // Column not yet migrated: override cannot persist yet.
        }
    }

    // ------------------------------------------------------------------
    // The gate: should this campaign be allowed to start?
    // ------------------------------------------------------------------

    /**
     * Preflight gate for a campaign start (activation).
     *
     * Block-vs-warn judgment:
     *  - summary 'pass' or 'warn' -> ALLOWED. A warn (e.g. SPF+DMARC present
     *    but no DKIM) is allowed because blocking on a single missing
     *    mechanism would stop legitimate small senders; the report is
     *    returned so the UI can surface it prominently.
     *  - summary 'fail' (NO SPF, NO DKIM, NO DMARC at all) -> BLOCKED,
     *    because sending from a fully unauthenticated domain is near-certain
     *    spam-foldering/rejection and burns the provider account's
     *    reputation. The block message names exactly what is missing and how
     *    to override.
     *  - The override is PER-CAMPAIGN (campaigns.dns_preflight_override),
     *    not a global kill-switch: the admin must deliberately acknowledge
     *    the risk for that specific campaign. A global
     *    dns_preflight_allow_fail setting was rejected because one flip
     *    would silently disable the gate for every campaign.
     *
     * @return array{allowed: bool, override_granted: bool,
     *   block_message: ?string, report: array<string,mixed>}
     */
    public static function campaignStartGate(\PDO $pdo, int $campaignId, bool $overrideRequested): array
    {
        $senderEmail = Database::getSetting('email_sender', '') ?? '';
        $domain = self::senderDomain($senderEmail);

        if ($domain === null) {
            // Nothing verifiable: the queue falls back to the provider's
            // default sender (e.g. onboarding@resend.dev), whose DNS is not
            // the buyer's to fix. Don't block on someone else's domain.
            return [
                'allowed' => true,
                'override_granted' => false,
                'block_message' => null,
                'report' => [
                    'domain' => null,
                    'sender_email' => $senderEmail !== '' ? $senderEmail : null,
                    'skipped' => true,
                    'reason' => 'No sender email configured (settings: email_sender) — nothing to preflight.',
                    'checked_at' => date('c'),
                ],
            ];
        }

        $report = self::checkDomainCached($domain);
        $report['sender_email'] = $senderEmail;

        $overrideActive = self::campaignOverride($pdo, $campaignId);
        $overrideGranted = false;
        $summary = (string)($report['summary'] ?? 'fail');

        if ($summary === 'fail' && !$overrideActive && !$overrideRequested) {
            $missing = implode("\n- ", $report['missing'] ?? ['(unknown)']);
            $blockMessage =
                "Campaign start blocked: sender domain '{$domain}' has no SPF, DKIM, or DMARC records. " .
                "Sending from a fully unauthenticated domain will very likely be spam-filtered or rejected, " .
                "and it burns the sending provider's reputation.\n\nMissing:\n- {$missing}\n\n" .
                'Fix the DNS records above, or acknowledge the risk explicitly by retrying the start with ' .
                'override_dns_preflight=true (this records a per-campaign override).';
            $report['override'] = false;
            return [
                'allowed' => false,
                'override_granted' => false,
                'block_message' => $blockMessage,
                'report' => $report,
            ];
        }

        if ($summary === 'fail' && $overrideRequested && !$overrideActive) {
            $overrideGranted = true;
        }
        $report['override'] = $overrideActive || $overrideGranted;

        return [
            'allowed' => true,
            'override_granted' => $overrideGranted,
            'block_message' => null,
            'report' => $report,
        ];
    }
}
