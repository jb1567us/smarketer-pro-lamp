<?php

declare(strict_types=1);

namespace App;

/**
 * Diagnostics — self-service health checks + support deflection.
 *
 * Runs the six checks a buyer needs to answer "is my setup broken, and if
 * so, whose fault is it?" and renders each as pass/warn/fail with a
 * plain-language explanation and an exact fix step. Written for a
 * non-technical cPanel buyer, not an engineer.
 *
 * Every check method never throws: a check that cannot run reports 'fail'
 * with the exception message as its detail, so one broken probe can never
 * take down the whole diagnostics page.
 *
 * Network access is injectable ($httpHandler / $dnsLookup / $smtpProbe) so
 * the logic is unit-testable offline. The default handlers use curl /
 * dns_get_record / fsockopen with short timeouts.
 *
 * SECURITY: the provider connectivity checks verify credentials with
 * read-only "who am I" style endpoints or an SMTP login handshake. They
 * NEVER issue a send (no MAIL FROM / RCPT TO / DATA, no POST to any
 * /send endpoint), so they cannot deliver an email or spend money.
 */
class Diagnostics
{
    /** Single place the app version lives. Bump on release. */
    public const APP_VERSION = '1.0.0';

    public const MIN_PHP = '7.4.0';

    /** Installer-required extensions (install.php pre-flight checks). */
    public const REQUIRED_EXTENSIONS = ['mysqli', 'curl', 'json', 'session', 'mbstring', 'openssl'];

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    /** @var callable|null fn(string $url, string[] $headers): array{code:int,body:string} */
    private static $httpHandler = null;

    /** @var callable|null fn(string $domain): array (DnsAuth::checkDomain report) */
    private static $dnsLookup = null;

    /**
     * @var callable|null fn(string $host, int $port, string $enc, string $user, string $pass): array{ok:bool,detail:string}
     */
    private static $smtpProbe = null;

    public static function setHttpHandler(?callable $fn): void
    {
        self::$httpHandler = $fn;
    }

    public static function setDnsLookup(?callable $fn): void
    {
        self::$dnsLookup = $fn;
    }

    public static function setSmtpProbe(?callable $fn): void
    {
        self::$smtpProbe = $fn;
    }

    // ------------------------------------------------------------------
    // Check envelope
    // ------------------------------------------------------------------

    /**
     * One normalized check result.
     */
    public static function result(string $id, string $status, string $title, string $detail, string $fix): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'title' => $title,
            'detail' => $detail,
            'fix' => $fix,
        ];
    }

    /**
     * Run every check. Returns a flat list of results (providers expand to
     * one result per configured provider).
     */
    public static function runAll(): array
    {
        $checks = [];
        $checks[] = self::guarded('php', fn() => self::checkPhp());
        $checks[] = self::guarded('dns', fn() => self::checkDns());
        foreach (self::guardedList('providers', fn() => self::checkProviders()) as $r) {
            $checks[] = $r;
        }
        $checks[] = self::guarded('cron', fn() => self::checkCron());
        $checks[] = self::guarded('license', fn() => self::checkLicense());
        $checks[] = self::guarded('schema', fn() => self::checkSchema());
        return $checks;
    }

    private static function guarded(string $id, callable $fn): array
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            return self::result($id, self::STATUS_FAIL, ucfirst($id) . ' check crashed',
                'The check itself errored: ' . $e->getMessage(),
                'Paste this report on the community forum — this one is ours to fix.');
        }
    }

    private static function guardedList(string $id, callable $fn): array
    {
        try {
            $out = $fn();
            return is_array($out) ? $out : [];
        } catch (\Throwable $e) {
            return [self::result($id, self::STATUS_FAIL, 'Provider checks crashed',
                'The provider checks errored: ' . $e->getMessage(),
                'Paste this report on the community forum — this one is ours to fix.')];
        }
    }

    // ------------------------------------------------------------------
    // 1. PHP version + extensions
    // ------------------------------------------------------------------

    public static function checkPhp(): array
    {
        $problems = [];
        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            $problems[] = 'PHP ' . PHP_VERSION . ' is below the minimum ' . self::MIN_PHP;
        }
        $missing = [];
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (!extension_loaded($ext)) {
                $missing[] = $ext;
            }
        }
        if ($missing) {
            $problems[] = 'missing PHP extensions: ' . implode(', ', $missing);
        }

        if (!$problems) {
            return self::result('php', self::STATUS_PASS, 'PHP version and extensions',
                'PHP ' . PHP_VERSION . ' with all required extensions (' . implode(', ', self::REQUIRED_EXTENSIONS) . ').',
                'No action needed.');
        }
        return self::result('php', self::STATUS_FAIL, 'PHP version and extensions',
            'Problem: ' . implode('; ', $problems) . '. The app cannot run reliably like this.',
            'In cPanel go to "Select PHP Version", switch to PHP 8.1 or newer, and make sure all listed extensions are ticked. Then re-run these checks.');
    }

    // ------------------------------------------------------------------
    // 2. DNS readiness (SPF / DKIM / DMARC for the sender domain)
    // ------------------------------------------------------------------

    public static function checkDns(): array
    {
        $sender = (string)(Database::getSetting('email_sender') ?? '');
        return self::dnsVerdictForSender($sender);
    }

    /**
     * Pure DNS verdict builder (sender email injected) — unit-testable.
     */
    public static function dnsVerdictForSender(string $sender): array
    {
        $domain = DnsAuth::senderDomain($sender);
        if ($domain === null) {
            return self::result('dns', self::STATUS_WARN, 'Sender domain email authentication',
                'No sender email is configured yet, so there is no domain to check.',
                'In Settings, set your "From" / sender email (e.g. you@yourdomain.com). Then re-run these checks.');
        }

        $lookup = self::$dnsLookup ?? fn(string $d): array => DnsAuth::checkDomainCached($d);
        $report = $lookup($domain);
        $summary = (string)($report['summary'] ?? 'fail');
        $missing = DnsAuth::missingDescriptions(is_array($report) ? $report : []);

        if ($summary === 'pass') {
            return self::result('dns', self::STATUS_PASS, 'Sender domain email authentication',
                "'{$domain}' has SPF, DKIM and DMARC published. Mailbox providers will trust mail from it.",
                'No action needed.');
        }

        $status = $summary === 'warn' ? self::STATUS_WARN : self::STATUS_FAIL;
        $detail = "'{$domain}' is not fully authenticated for email. " . implode(' ', $missing);
        $fix = 'Add the missing DNS records in your domain\'s DNS zone (hosting panel → "Zone Editor"), one TXT record each for SPF/DKIM/DMARC. '
            . 'Your email provider publishes the exact values to copy — DKIM comes from the provider\'s domain-authentication page. '
            . 'DNS changes can take a few hours to show up; re-run these checks afterwards. You can still send meanwhile, but more mail may land in spam.';
        return self::result('dns', $status, 'Sender domain email authentication', $detail, $fix);
    }

    // ------------------------------------------------------------------
    // 3. Provider API connectivity (credential verify only — never sends)
    // ------------------------------------------------------------------

    /**
     * Read-only credential checks per HTTP provider: [verify URL, header builder].
     * Every endpoint is a metadata/"who am I" style GET — none of them can
     * trigger a send or any billable action.
     */
    private static function httpProviderChecks(): array
    {
        return [
            'sendgrid'   => ['SendGrid',  'https://api.sendgrid.com/v3/api_keys', fn(string $k): array => ['Authorization: Bearer ' . $k]],
            'resend'     => ['Resend',    'https://api.resend.com/api_keys',       fn(string $k): array => ['Authorization: Bearer ' . $k]],
            'mailgun'    => ['Mailgun',   'https://api.mailgun.net/v3/domains',    fn(string $k): array => ['Authorization: Basic ' . base64_encode('api:' . $k)]],
            'brevo'      => ['Brevo',     'https://api.brevo.com/v3/account',      fn(string $k): array => ['api-key: ' . $k]],
            'mailjet'    => ['Mailjet',   'https://api.mailjet.com/v3/REST/profile', fn(string $k): array => [
                'Authorization: Basic ' . (strpos($k, ':') !== false ? base64_encode($k) : base64_encode($k . ':')),
            ]],
            'postmark'   => ['Postmark',  'https://api.postmarkapp.com/server',    fn(string $k): array => ['X-Postmark-Server-Token: ' . $k, 'Accept: application/json']],
            'mailersend' => ['MailerSend','https://api.mailersend.com/v1/domains', fn(string $k): array => ['Authorization: Bearer ' . $k]],
            'mailtrap'   => ['Mailtrap',  'https://mailtrap.io/api/v1/inboxes',    fn(string $k): array => ['Api-Token: ' . $k]],
            // ZeptoMail auth scheme mirrors EmailSender::sendZeptoMail ('Authorization: <key>').
            'zoho'       => ['Zoho ZeptoMail', 'https://api.zeptomail.com/v1.1/users', fn(string $k): array => ['Authorization: ' . $k]],
            // Pepipost auth scheme mirrors EmailSender::sendPepipost ('api_key: <key>').
            'netcore'    => ['Netcore (Pepipost)', 'https://api.pepipost.com/v2/domains', fn(string $k): array => ['api_key: ' . $k]],
        ];
    }

    /** Providers that authenticate over raw SMTP in EmailSender. */
    private static function smtpProviders(): array
    {
        return [
            'smtp' => 'SMTP', 'custom_smtp' => 'Custom SMTP', 'amazon_ses' => 'Amazon SES (SMTP)',
            'sendpulse' => 'SendPulse (SMTP)', 'zoho_smtp' => 'Zoho (SMTP)', 'netcore_smtp' => 'Netcore (SMTP)',
        ];
    }

    /**
     * One result per provider that has credentials saved. The active
     * provider is checked first; a missing key on the ACTIVE provider is a
     * fail (nothing can send), on others the provider is simply skipped.
     */
    public static function checkProviders(): array
    {
        $g = fn(string $k, ?string $d = null): ?string => Database::getSetting($k, $d);
        return self::providersVerdict($g);
    }

    /**
     * Pure provider verdict builder — $getSetting is any callable like
     * Database::getSetting, so tests can inject a plain array lookup.
     *
     * @param callable(string, ?string): ?string $getSetting
     */
    public static function providersVerdict(callable $getSetting): array
    {
        $active = strtolower((string)($getSetting('active_email_provider', 'smtp') ?: 'smtp'));
        $ordered = array_unique(array_merge([$active], array_keys(self::httpProviderChecks()), array_keys(self::smtpProviders())));

        $results = [];
        $checkedAny = false;
        foreach ($ordered as $provider) {
            if ($provider === 'smart_rotation') {
                continue; // covered by checking each provider with keys
            }
            $key = trim((string)($getSetting($provider . '_api_key') ?? ''));
            if (isset(self::httpProviderChecks()[$provider])) {
                if ($key === '') {
                    if ($provider === $active) {
                        $results[] = self::result('provider_' . $provider, self::STATUS_FAIL,
                            'Email provider: ' . self::httpProviderChecks()[$provider][0],
                            'This is your active sending provider, but no API key is saved for it. Nothing can be sent.',
                            'In Settings → Email, paste a valid API key for ' . self::httpProviderChecks()[$provider][0] . '.');
                    }
                    continue;
                }
                $checkedAny = true;
                $results[] = self::checkHttpProvider($provider, $key);
            } elseif (isset(self::smtpProviders()[$provider])) {
                $creds = self::smtpCredentials($provider, $getSetting);
                if ($creds['pass'] === '' || $creds['host'] === '') {
                    if ($provider === $active) {
                        $results[] = self::result('provider_' . $provider, self::STATUS_FAIL,
                            'Email provider: ' . self::smtpProviders()[$provider],
                            'This is your active sending provider, but the SMTP login details are incomplete (host or password missing).',
                            'In Settings → Email, fill in the SMTP host, username and password for ' . self::smtpProviders()[$provider] . '.');
                    }
                    continue;
                }
                $checkedAny = true;
                $results[] = self::checkSmtpProvider($provider, $creds);
            }
        }

        if (!$checkedAny && !$results) {
            return [self::result('providers', self::STATUS_WARN, 'Email provider connectivity',
                'No provider credentials are saved at all.',
                'In Settings → Email, choose your provider and save its API key or SMTP login. Then re-run these checks.')];
        }
        return $results;
    }

    private static function checkHttpProvider(string $provider, string $key): array
    {
        [$label, $url, $headerFn] = self::httpProviderChecks()[$provider];
        try {
            $handler = self::$httpHandler ?? [self::class, 'defaultHttpGet'];
            $res = $handler($url, $headerFn($key));
            $code = (int)($res['code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                return self::result('provider_' . $provider, self::STATUS_PASS,
                    'Email provider: ' . $label,
                    "Connected to {$label} and the API key was accepted (HTTP {$code}).",
                    'No action needed.');
            }
            if ($code === 401 || $code === 403) {
                return self::result('provider_' . $provider, self::STATUS_FAIL,
                    'Email provider: ' . $label,
                    "Reached {$label}, but it rejected the API key (HTTP {$code}). Sending through it will fail.",
                    "The key is wrong, expired, or revoked. Generate a fresh key in your {$label} dashboard and paste it into Settings → Email.");
            }
            return self::result('provider_' . $provider, self::STATUS_WARN,
                'Email provider: ' . $label,
                "Reached {$label} but got an unexpected answer (HTTP {$code}). The key might be fine and the provider might just be having issues.",
                "Wait a few minutes and re-run the checks. If it persists, check the {$label} status page, then regenerate the key in your {$label} dashboard.");
        } catch (\Throwable $e) {
            return self::result('provider_' . $provider, self::STATUS_WARN,
                'Email provider: ' . $label,
                "Could not reach {$label} at all: " . $e->getMessage() . '. Your server may block outbound HTTPS, or the provider may be down.',
                'Make sure your hosting allows outbound HTTPS connections (port 443). Shared hosts sometimes block these — ask your host to allow them.');
        }
    }

    private static function smtpCredentials(string $provider, callable $getSetting): array
    {
        $g = fn(string $k): string => trim((string)($getSetting($k) ?? ''));
        $creds = [
            'host' => $g($provider . '_smtp_host') ?: $g('smtp_host'),
            'port' => (int)($g($provider . '_smtp_port') ?: $g('smtp_port') ?: '587'),
            'user' => $g($provider . '_smtp_user') ?: $g('smtp_user'),
            'pass' => $g($provider . '_smtp_pass') ?: $g('smtp_pass'),
            'enc'  => strtolower($g($provider . '_smtp_encryption') ?: $g('smtp_encryption') ?: 'tls'),
        ];
        if ($provider === 'amazon_ses') {
            // Item 3: region-aware SES check. An explicit amazon_ses_smtp_host
            // still wins; otherwise probe the regional endpoint that
            // EmailSender::sendViaSmtpSocket() will actually use (the old code
            // fell back to the unrelated global smtp_host, or failed with
            // "host missing" on default installs that send fine).
            $region = \App\EmailSender::normalizeSesRegion($g('ses_region'));
            if ($g('amazon_ses_smtp_host') === '') {
                $creds['host'] = \App\EmailSender::sesSmtpHost($region);
            }
            $creds['ses_region'] = $region;
        }
        return $creds;
    }

    private static function checkSmtpProvider(string $provider, array $creds): array
    {
        $label = self::smtpProviders()[$provider];
        // Item 3: name the SES region in results so a buyer can see at a
        // glance which regional endpoint was probed.
        $regionNote = ($provider === 'amazon_ses' && !empty($creds['ses_region']))
            ? " (SES region: {$creds['ses_region']})" : '';
        try {
            $probe = self::$smtpProbe ?? [self::class, 'smtpLoginProbe'];
            $res = $probe($creds['host'], $creds['port'], $creds['enc'], $creds['user'], $creds['pass']);
            if (!empty($res['ok'])) {
                return self::result('provider_' . $provider, self::STATUS_PASS,
                    'Email provider: ' . $label,
                    "Logged in to the SMTP server {$creds['host']}:{$creds['port']} successfully{$regionNote}. No mail was sent.",
                    'No action needed.');
            }
            return self::result('provider_' . $provider, self::STATUS_FAIL,
                'Email provider: ' . $label,
                "Could not log in to {$creds['host']}:{$creds['port']}{$regionNote}: " . ($res['detail'] ?? 'unknown error'),
                $provider === 'amazon_ses'
                    ? 'SES SMTP credentials are issued per region: the region selected in Settings → Email must be the one where you created the credentials in the AWS console. Also double-check the SMTP username, password and encryption (TLS on 587).'
                    : 'Double-check the SMTP host, port, username, password and encryption (SSL/TLS) in Settings → Email. Passwords from your provider often differ from your login password — use the app/SMTP password they give you.');
        } catch (\Throwable $e) {
            return self::result('provider_' . $provider, self::STATUS_WARN,
                'Email provider: ' . $label,
                "Could not reach {$creds['host']}:{$creds['port']}{$regionNote}: " . $e->getMessage(),
                'Make sure your host allows outbound SMTP (ports 25/587/465). Many shared hosts block these — ask your host to open them, or switch to an HTTP API provider (Resend, SendGrid, Brevo), which only needs port 443.');
        }
    }

    /**
     * Default HTTP GET: curl, 8s timeout, no redirects needed for these
     * metadata endpoints.
     *
     * @return array{code:int,body:string}
     */
    public static function defaultHttpGet(string $url, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT => 'SmarketerPro-Diagnostics/' . self::APP_VERSION,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('connection failed: ' . $err);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$body];
    }

    /**
     * SMTP login probe: TCP connect → EHLO → (STARTTLS) → AUTH LOGIN.
     * Stops at 235 Authentication succeeded, then QUITs. Never issues
     * MAIL FROM / RCPT TO / DATA, so nothing can be delivered.
     *
     * @return array{ok:bool,detail:string}
     */
    public static function smtpLoginProbe(string $host, int $port, string $enc, string $user, string $pass): array
    {
        $target = ($enc === 'ssl' ? 'ssl://' : '') . $host;
        $sock = @fsockopen($target, $port, $errno, $errstr, 8);
        if (!$sock) {
            return ['ok' => false, 'detail' => "connection failed ({$errstr})"];
        }
        stream_set_timeout($sock, 8);
        $read = function () use ($sock): string {
            $line = '';
            while (($chunk = fgets($sock, 512)) !== false) {
                $line .= $chunk;
                if (preg_match('/^\d{3} /', $chunk)) {
                    break;
                }
            }
            return $line;
        };
        $code = function (string $line): int { return (int)substr(trim($line), 0, 3); };

        try {
            $greet = $read();
            if ($code($greet) !== 220) {
                return ['ok' => false, 'detail' => 'server did not greet us (got: ' . trim($greet) . ')'];
            }
            fwrite($sock, "EHLO diagnostics\r\n");
            $ehlo = $read();
            if ($code($ehlo) !== 250) {
                return ['ok' => false, 'detail' => 'server refused EHLO (got: ' . trim($ehlo) . ')'];
            }
            if ($enc === 'tls' && stripos($ehlo, 'STARTTLS') !== false) {
                fwrite($sock, "STARTTLS\r\n");
                $tls = $read();
                if ($code($tls) === 220 && !@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return ['ok' => false, 'detail' => 'TLS upgrade failed'];
                }
                fwrite($sock, "EHLO diagnostics\r\n");
                $read();
            }
            fwrite($sock, "AUTH LOGIN\r\n");
            if ($code($read()) !== 334) {
                return ['ok' => false, 'detail' => 'server refused AUTH LOGIN'];
            }
            fwrite($sock, base64_encode($user) . "\r\n");
            if ($code($read()) !== 334) {
                return ['ok' => false, 'detail' => 'server rejected the username'];
            }
            fwrite($sock, base64_encode($pass) . "\r\n");
            $auth = $read();
            if ($code($auth) !== 235) {
                return ['ok' => false, 'detail' => 'login rejected by server (got: ' . trim($auth) . ')'];
            }
            return ['ok' => true, 'detail' => 'authenticated'];
        } finally {
            @fwrite($sock, "QUIT\r\n");
            @fclose($sock);
        }
    }

    // ------------------------------------------------------------------
    // 4. Cron / queue health
    // ------------------------------------------------------------------

    public static function checkCron(): array
    {
        $pdo = Database::getConnection();

        $stats = [
            'last_run' => Database::getSetting('queue_last_run_at'),
            'pending' => self::scalar($pdo, "SELECT COUNT(*) FROM task_queue WHERE status = 'Pending'"),
            'in_progress' => self::scalar($pdo, "SELECT COUNT(*) FROM task_queue WHERE status = 'In Progress'"),
            'stuck' => self::scalar($pdo,
                "SELECT COUNT(*) FROM task_queue WHERE status = 'In Progress' AND processed_at < NOW() - INTERVAL 30 MINUTE"),
            'running_now' => false,
        ];
        try {
            $row = $pdo->query("SELECT pid, locked_at FROM cron_locks WHERE lock_name = 'process_queue'")->fetch();
            $stats['running_now'] = $row && (int)$row['pid'] > 0
                && strtotime((string)$row['locked_at']) > time() - 1800;
        } catch (\Throwable $e) {
            // cron_locks missing (pre-migration) — schema check reports that.
        }
        return self::cronVerdict($stats);
    }

    /**
     * Pure cron verdict builder — unit-testable without a database.
     *
     * @param array{last_run:?string,pending:int,in_progress:int,stuck:int,running_now:bool} $stats
     */
    public static function cronVerdict(array $stats): array
    {
        $lastRun = $stats['last_run'] ?? null;
        $pending = (int)($stats['pending'] ?? 0);
        $inProgress = (int)($stats['in_progress'] ?? 0);
        $stuck = (int)($stats['stuck'] ?? 0);
        $runningNow = !empty($stats['running_now']);

        $detail = 'Last worker run: ' . ($lastRun ?: 'never recorded')
            . ' | queue: ' . $pending . ' waiting, ' . $inProgress . ' in progress'
            . ($runningNow ? ' (a run is active right now)' : '');

        if ($lastRun === null || $lastRun === '') {
            return self::result('cron', self::STATUS_WARN, 'Cron / queue worker',
                $detail . '. The worker has not recorded a run yet — either it has never fired, or you upgraded from an older build.',
                'In cPanel → "Cron Jobs", add a job running every 5 minutes: php ' . self::cronCommandHint() . ' — the exact command is shown by install.php. Wait 10 minutes, then re-run these checks.');
        }

        if ($stuck > 0) {
            return self::result('cron', self::STATUS_FAIL, 'Cron / queue worker',
                $detail . ". {$stuck} task(s) have been 'in progress' for over 30 minutes — the worker likely crashed mid-task.",
                'The queue recovers these automatically on its next run (up to 5 retries). If stuck tasks keep piling up, paste this report on the forum — that is ours to fix.');
        }

        $ageMin = (time() - strtotime((string)$lastRun)) / 60;
        if ($pending > 0 && $ageMin > 15) {
            return self::result('cron', self::STATUS_FAIL, 'Cron / queue worker',
                $detail . '. Tasks are waiting but the worker has not run in over 15 minutes — the cron job is probably not set up or is failing.',
                'In cPanel → "Cron Jobs", make sure a job exists running every 5 minutes: php ' . self::cronCommandHint() . ' Check the cron email/output for PHP errors if the job exists but nothing runs.');
        }

        return self::result('cron', self::STATUS_PASS, 'Cron / queue worker',
            $detail . '. The background worker is running on schedule.',
            'No action needed.');
    }

    private static function cronCommandHint(): string
    {
        return '/home/USERNAME/public_html/b2b_outreach_lamp/cron/process_queue.php';
    }

    // ------------------------------------------------------------------
    // 5. License status
    // ------------------------------------------------------------------

    public static function checkLicense(): array
    {
        return self::licenseVerdict(Licensing::statusForUi());
    }

    /**
     * Pure license verdict builder — unit-testable without a database.
     */
    public static function licenseVerdict(array $ui): array
    {
        $status = (string)($ui['status'] ?? 'unknown');
        $label = (string)($ui['label'] ?? 'Unknown');
        $detailText = (string)($ui['detail'] ?? '');
        $sending = !empty($ui['sending_allowed']) ? 'Sending is allowed.' : 'Outbound sending is currently paused.';

        switch ($status) {
            case 'valid':
                return self::result('license', self::STATUS_PASS, 'License',
                    "Licensed ({$label}). " . ($detailText !== '' ? $detailText . ' ' : '') . $sending,
                    'No action needed.');
            case 'unlicensed':
                return self::result('license', self::STATUS_WARN, 'License',
                    'No license key entered — the app runs fully functional (fail-open mode).',
                    'Paste your key in Settings → License to activate your license.');
            case 'revoked':
                return self::result('license', self::STATUS_FAIL, 'License',
                    "License revoked ({$label}). {$sending}",
                    'This key has been revoked. If you believe this is a mistake, paste this report on the forum with your order details.');
            case 'invalid':
                return self::result('license', self::STATUS_FAIL, 'License',
                    "Key issue ({$label}): {$detailText}",
                    'Re-enter your license key in Settings → License. If the key is correct and it still fails, paste this report on the forum.');
            case 'unreachable':
            case 'unknown':
            default:
                $grace = !empty($ui['grace_expired']) ? 'The offline grace period has expired. ' : 'The app keeps working while it retries. ';
                return self::result('license', self::STATUS_WARN, 'License',
                    "Could not verify with the license server ({$label}). {$grace}{$sending}",
                    'Make sure your server can reach the license server over HTTPS. If the URL in Settings → License looks right, wait and re-run — it retries automatically.');
        }
    }

    // ------------------------------------------------------------------
    // 6. Schema / migration sanity
    // ------------------------------------------------------------------

    /**
     * Tables the app needs + key columns added by the compliance builds.
     * Kept in sync with schema.sql and migrations/2026-09-23-compliance*.sql
     * plus migrations/2026-09-24-blocked-counts.sql (ITEM A).
     */
    public static function expectedSchema(): array
    {
        return [
            'tables' => [
                'leads', 'campaigns', 'templates', 'task_queue', 'cron_locks',
                'settings', 'email_logs', 'suppression_list', 'webhook_events',
                'domain_auth_cache', 'casl_decisions',
            ],
            'columns' => [
                'leads' => ['consent_status', 'verification_status', 'country_code'],
                'suppression_list' => ['email_hash'],
                'email_logs' => ['campaign_id'],
                // ITEM A: blocked-send counters; without them the campaign
                // view silently shows no blocked counts.
                'campaigns' => [
                    'blocked_invalid_verification', 'blocked_suppression',
                    'blocked_compliance_pause', 'blocked_throttle',
                    'blocked_license_revoked', 'blocked_placeholder',
                ],
            ],
        ];
    }

    public static function checkSchema(): array
    {
        $pdo = Database::getConnection();
        $expected = self::expectedSchema();

        $missingTables = [];
        foreach ($expected['tables'] as $table) {
            if (!self::tableExists($pdo, $table)) {
                $missingTables[] = $table;
            }
        }
        $missingColumns = [];
        foreach ($expected['columns'] as $table => $cols) {
            if (in_array($table, $missingTables, true)) {
                continue;
            }
            foreach ($cols as $col) {
                if (!self::columnExists($pdo, $table, $col)) {
                    $missingColumns[] = "{$table}.{$col}";
                }
            }
        }

        if (!$missingTables && !$missingColumns) {
            return self::result('schema', self::STATUS_PASS, 'Database structure',
                'All expected tables and columns are present.',
                'No action needed.');
        }

        $parts = [];
        if ($missingTables) {
            $parts[] = 'missing tables: ' . implode(', ', $missingTables);
        }
        if ($missingColumns) {
            $parts[] = 'missing columns: ' . implode(', ', $missingColumns);
        }
        return self::result('schema', self::STATUS_FAIL, 'Database structure',
            'The database is incomplete — ' . implode('; ', $parts) . '. Some features will break.',
            'In cPanel → phpMyAdmin, select your database and import the SQL files from the app\'s /migrations folder in date order (each is safe to run more than once). '
            . 'If you are not comfortable doing that, paste this report on the forum and ask for the exact import steps.');
    }

    // ------------------------------------------------------------------
    // Report rendering
    // ------------------------------------------------------------------

    /**
     * Paste-ready plain-text report for forum support posts.
     */
    public static function renderTextReport(array $checks): string
    {
        $labels = [self::STATUS_PASS => 'PASS', self::STATUS_WARN => 'WARN', self::STATUS_FAIL => 'FAIL'];
        $lines = [];
        $lines[] = 'Smarketer Pro — Diagnostics Report';
        $lines[] = 'App version: ' . self::APP_VERSION . ' | PHP ' . PHP_VERSION;
        $lines[] = 'Generated: ' . date('Y-m-d H:i:s');
        $lines[] = '';
        foreach ($checks as $c) {
            $tag = $labels[$c['status']] ?? '????';
            $lines[] = "[{$tag}] {$c['title']}";
            $lines[] = '       ' . self::fold($c['detail']);
            if ($c['status'] !== self::STATUS_PASS && trim((string)$c['fix']) !== '') {
                $lines[] = '       Fix: ' . self::fold((string)$c['fix']);
            }
            $lines[] = '';
        }
        $fails = count(array_filter($checks, fn($c) => $c['status'] === self::STATUS_FAIL));
        $warns = count(array_filter($checks, fn($c) => $c['status'] === self::STATUS_WARN));
        $lines[] = "Summary: {$fails} failing, {$warns} warnings out of " . count($checks) . ' checks.';
        return implode("\n", $lines);
    }

    private static function fold(string $text): string
    {
        return str_replace("\n", "\n       ", trim($text));
    }

    // ------------------------------------------------------------------
    // Small DB helpers
    // ------------------------------------------------------------------

    private static function scalar($pdo, string $sql): int
    {
        try {
            $row = $pdo->query($sql)->fetch();
            return (int)($row ? array_values($row)[0] : 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function tableExists($pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table]);
            return (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function columnExists($pdo, string $table, string $column): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            return (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
