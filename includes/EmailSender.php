<?php

declare(strict_types=1);

namespace App;

use Exception;

class EmailSender
{
    /**
     * Fabricated harvester placeholder addresses (pending_<hash>@placeholder.com)
     * must never receive real sends -- delivery attempts to them only burn
     * sender reputation via bounces and spam-trap hits.
     */
    public static function isPlaceholderAddress(string $email): bool
    {
        return (bool) preg_match('/@placeholder\.com$/i', trim($email));
    }

    /**
     * SES region selector (Item 3).
     *
     * AWS SES is region-scoped: SMTP credentials are issued per region and
     * the SMTP endpoint hostname embeds the region
     * (email-smtp.<region>.amazonaws.com). Curated from AWS's documented
     * SES SMTP regions (see the official SMTP-credential generator's
     * SMTP_REGIONS list in the SES developer guide); GovCloud regions are
     * excluded -- unusable by the cPanel shared-hosting buyers of this app.
     */
    public const SES_DEFAULT_REGION = 'us-east-1';

    /**
     * Allowlisted SES SMTP regions: [code => human label].
     * us-east-1 first = default; preserves pre-Item-3 behavior.
     */
    public static function sesRegions(): array
    {
        return [
            'us-east-1'      => 'US East (N. Virginia)',
            'us-east-2'      => 'US East (Ohio)',
            'us-west-2'      => 'US West (Oregon)',
            'eu-west-1'      => 'Europe (Ireland)',
            'eu-west-2'      => 'Europe (London)',
            'eu-central-1'   => 'Europe (Frankfurt)',
            'eu-north-1'     => 'Europe (Stockholm)',
            'eu-south-1'     => 'Europe (Milan)',
            'ap-south-1'     => 'Asia Pacific (Mumbai)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-southeast-2' => 'Asia Pacific (Sydney)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
            'ap-northeast-2' => 'Asia Pacific (Seoul)',
            'ca-central-1'   => 'Canada (Central)',
            'sa-east-1'      => 'South America (Sao Paulo)',
        ];
    }

    /**
     * Normalize + validate a raw region value against the allowlist.
     * Anything off-list (typos, regions without an SES SMTP endpoint like
     * us-west-1, injection attempts) returns the default, so the sender
     * can never build an invalid endpoint from a corrupt setting.
     */
    public static function normalizeSesRegion($raw): string
    {
        $r = strtolower(trim((string)$raw));
        return isset(self::sesRegions()[$r]) ? $r : self::SES_DEFAULT_REGION;
    }

    public static function isValidSesRegion($raw): bool
    {
        return isset(self::sesRegions()[strtolower(trim((string)$raw))]);
    }

    /**
     * The buyer's configured SES region, validated (setting key: ses_region).
     */
    public static function configuredSesRegion(): string
    {
        return self::normalizeSesRegion(\App\Database::getSetting('ses_region'));
    }

    /**
     * Regional SES SMTP hostname for a region (validated, default on bad input).
     * Pass null to use the buyer's configured region.
     */
    public static function sesSmtpHost(?string $region = null): string
    {
        $r = $region === null ? self::configuredSesRegion() : self::normalizeSesRegion($region);
        return 'email-smtp.' . $r . '.amazonaws.com';
    }

    /**
     * Send an email utilizing outbound HTTPS APIs directly or a direct SMTP socket connection.
     * 
     * @param string $to Recipient Email Address
     * @param string $subject Email Subject
     * @param string $body Email Body (HTML/Text)
     * @param string $provider brevo | sendgrid | mailgun | resend | mailjet | postmark | mailersend | mailtrap | zoho | netcore | smtp | custom_smtp | sendpulse | amazon_ses | zoho_smtp | netcore_smtp
     * @param string $apiKey The API Key / Token / SMTP Password
     * @param string $senderEmail The Authorized SPF/DKIM Verified Sender
     * @param string|null $mailgunDomain Required for Mailgun domain (or falls back to smtp_host / generic domain)
     * @param int|null $campaignId ITEM A: campaign that owns this send; refused sends are counted against it.
     * @return bool
     * @throws Exception
     */
    public static function send(
        string $to,
        string $subject,
        string $body,
        string $provider,
        string $apiKey,
        string $senderEmail,
        ?string $mailgunDomain = null,
        ?int $campaignId = null
    ): bool {
        if (self::isPlaceholderAddress($to)) {
            // ITEM A: fabricated harvester addresses are refused here — count
            // them so the campaign view can say so plainly.
            \App\BlockedCount::record($campaignId, \App\BlockedCount::REASON_PLACEHOLDER);
            throw new Exception("Refusing to send to placeholder address: {$to}");
        }

        // Guardrail choke point: suppression list, mandatory sender identity,
        // and the CASL harvest gate. Throws when a send would put the
        // buyer's provider account at risk.
        Compliance::requireCompliantSend($to, null, $campaignId);

        // Identity footer: legal name + postal address + one-click
        // unsubscribe on every message, all providers. Mail without a real
        // sender identity is what gets flagged as spam.
        $body = Compliance::appendFooter($body, $to);

        if (empty($senderEmail)) {
            throw new Exception("Authorized Sender Address is required so providers can authenticate your mail (SPF/DKIM) — sends without it fail or get flagged as spoofed.");
        }

        $providerLower = strtolower($provider);

        // Routing logic for REST APIs vs raw SMTP Sockets
        return match ($providerLower) {
            'resend' => self::sendResend($to, $subject, $body, $apiKey, $senderEmail),
            'brevo' => self::sendBrevo($to, $subject, $body, $apiKey, $senderEmail),
            'sendgrid' => self::sendSendGrid($to, $subject, $body, $apiKey, $senderEmail),
            'mailgun' => self::sendMailgun($to, $subject, $body, $apiKey, $senderEmail, $mailgunDomain ?? ''),
            'mailjet' => self::sendMailjet($to, $subject, $body, $apiKey, $senderEmail),
            'postmark' => self::sendPostmark($to, $subject, $body, $apiKey, $senderEmail),
            'mailersend' => self::sendMailerSend($to, $subject, $body, $apiKey, $senderEmail),
            'mailtrap' => self::sendMailtrap($to, $subject, $body, $apiKey, $senderEmail),
            'zoho' => self::sendZeptoMail($to, $subject, $body, $apiKey, $senderEmail),
            'netcore' => self::sendPepipost($to, $subject, $body, $apiKey, $senderEmail),
            'smtp', 'custom_smtp', 'sendpulse', 'amazon_ses', 'zoho_smtp', 'netcore_smtp' => self::sendViaSmtpSocket($to, $subject, $body, $providerLower, $apiKey, $senderEmail),
            default => throw new Exception("Unsupported outbound email provider: {$provider}")
        };
    }

    /**
     * Resend API Sender
     */
    private static function sendResend(string $to, string $subject, string $body, string $apiKey, string $senderEmail): bool
    {
        $url = 'https://api.resend.com/emails';
        $payload = [
            'from' => $senderEmail,
            'to' => [$to],
            'subject' => $subject,
            'html' => $body,
            'headers' => self::listUnsubscribeHeaderMap($to, $senderEmail),
        ];

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ];

        return self::executeCurl($url, $headers, json_encode($payload));
    }

    /**
     * List-Unsubscribe headers as a key/value map for provider APIs.
     * Providers whose JSON payload supports a 'headers' object (Resend,
     * SendGrid) inject it directly; the rest go through withListUnsubscribe().
     */
    private static function listUnsubscribeHeaderMap(string $to, string $senderEmail): array
    {
        $map = [];
        foreach (Compliance::listUnsubscribeHeaders($to, $senderEmail) as $h) {
            $parts = explode(':', $h, 2);
            if (count($parts) === 2) {
                $map[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $map;
    }

    /**
     * Inject List-Unsubscribe / List-Unsubscribe-Post into a provider payload
     * using that provider's own custom-headers mechanism. Strictly additive:
     * existing keys are never overwritten, only missing names are added, so
     * calling it twice is a no-op (idempotent).
     *
     * Per-provider mechanism:
     *   resend/sendgrid/brevo/mailtrap/netcore : payload['headers'] object map
     *   mailersend : payload['headers'] = [{name, value}, ...]  (requires Pro/Enterprise plan)
     *   zoho (ZeptoMail) : payload['mime_headers'] object map
     *   postmark : payload['Headers'] = [{Name, Value}, ...]
     *   mailjet : payload['Messages'][0]['Headers'] object map
     *   mailgun : 'h:<Name>' form fields
     *   smtp/* : raw MIME headers are written directly in sendSmtpSocket()
     *            (already present), so the payload path is unused here.
     */
    private static function withListUnsubscribe(array $payload, string $provider, string $to, string $senderEmail): array
    {
        $map = self::listUnsubscribeHeaderMap($to, $senderEmail);
        if ($map === []) {
            return $payload;
        }

        return match (strtolower($provider)) {
            // Top-level 'headers' object map (name => value)
            'brevo', 'resend', 'sendgrid', 'mailtrap', 'netcore' =>
                self::mergeHeaderObject($payload, 'headers', $map),
            // ZeptoMail: 'mime_headers' object map
            'zoho' => self::mergeHeaderObject($payload, 'mime_headers', $map),
            // MailerSend: 'headers' array of {name, value}
            'mailersend' => self::mergeNameValueList($payload, 'headers', $map, 'name', 'value'),
            // Postmark: 'Headers' array of {Name, Value}
            'postmark' => self::mergeNameValueList($payload, 'Headers', $map, 'Name', 'Value'),
            // Mailjet v3.1: Messages[0].Headers object map
            'mailjet' => self::mergeMailjetHeaders($payload, $map),
            // Mailgun v3: custom MIME headers are 'h:<Name>' form fields
            'mailgun' => self::mergeMailgunFields($payload, $map),
            // Raw SMTP socket path (and unknown providers): no payload merge.
            default => $payload,
        };
    }

    /**
     * Merge $map into $payload[$key] where $key holds a name => value object.
     */
    private static function mergeHeaderObject(array $payload, string $key, array $map): array
    {
        $existing = (isset($payload[$key]) && is_array($payload[$key])) ? $payload[$key] : [];
        foreach ($map as $name => $value) {
            if (!array_key_exists($name, $existing)) {
                $existing[$name] = $value;
            }
        }
        $payload[$key] = $existing;
        return $payload;
    }

    /**
     * Merge $map into $payload[$key] where $key holds a list of
     * [$nameKey => name, $valueKey => value] entries.
     */
    private static function mergeNameValueList(array $payload, string $key, array $map, string $nameKey, string $valueKey): array
    {
        $existing = (isset($payload[$key]) && is_array($payload[$key])) ? $payload[$key] : [];
        $have = [];
        foreach ($existing as $entry) {
            if (is_array($entry) && isset($entry[$nameKey]) && is_string($entry[$nameKey])) {
                $have[strtolower($entry[$nameKey])] = true;
            }
        }
        foreach ($map as $name => $value) {
            if (!isset($have[strtolower($name)])) {
                $existing[] = [$nameKey => $name, $valueKey => $value];
            }
        }
        $payload[$key] = $existing;
        return $payload;
    }

    /**
     * Merge $map into the Mailjet v3.1 Messages[0].Headers object.
     */
    private static function mergeMailjetHeaders(array $payload, array $map): array
    {
        if (!isset($payload['Messages'][0]) || !is_array($payload['Messages'][0])) {
            return $payload;
        }
        $existing = (isset($payload['Messages'][0]['Headers']) && is_array($payload['Messages'][0]['Headers']))
            ? $payload['Messages'][0]['Headers']
            : [];
        foreach ($map as $name => $value) {
            if (!array_key_exists($name, $existing)) {
                $existing[$name] = $value;
            }
        }
        $payload['Messages'][0]['Headers'] = $existing;
        return $payload;
    }

    /**
     * Merge $map into a Mailgun form payload as 'h:<Name>' fields.
     */
    private static function mergeMailgunFields(array $payload, array $map): array
    {
        foreach ($map as $name => $value) {
            $key = 'h:' . $name;
            if (!array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }
        return $payload;
    }

    /**
     * Brevo API v3 Sender
     */
    private static function sendBrevo(string $to, string $subject, string $body, string $apiKey, string $senderEmail): bool
    {
        $url = 'https://api.brevo.com/v3/smtp/email';
        $payload = [
            'sender' => ['email' => $senderEmail],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'htmlContent' => $body
        ];
        // Brevo v3: 'headers' object in the JSON payload
        $payload = self::withListUnsubscribe($payload, 'brevo', $to, $senderEmail);

        $headers = [
            'api-key: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        return self::executeCurl($url, $headers, json_encode($payload));
    }

    /**
     * SendGrid v3 Mail Send
     */
    private static function sendSendGrid(string $to, string $subject, string $body, string $apiKey, string $senderEmail): bool
    {
        $url = 'https://api.sendgrid.com/v3/mail/send';
        $payload = [
            'personalizations' => [[
                'to' => [['email' => $to]]
            ]],
            'from' => ['email' => $senderEmail],
            'subject' => $subject,
            'content' => [[
                'type' => 'text/html',
                'value' => $body
            ]],
            'headers' => self::listUnsubscribeHeaderMap($to, $senderEmail),
        ];

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ];

        return self::executeCurl($url, $headers, json_encode($payload));
    }

    /**
     * Mailgun v3 Message Sending
     */
    private static function sendMailgun(string $to, string $subject, string $body, string $apiKey, string $senderEmail, string $domain): bool
    {
        if (empty($domain)) {
            // Attempt to resolve domain from settings
            $domain = \App\Database::getSetting('mailgun_domain') ?: \App\Database::getSetting('smtp_host');
            if (empty($domain)) {
                throw new Exception("Mailgun Domain setting is required for Mailgun delivery");
            }
        }

        $url = "https://api.mailgun.net/v3/" . rawurlencode($domain) . "/messages";
        $payload = [
            'from' => $senderEmail,
            'to' => $to,
            'subject' => $subject,
            'html' => $body
        ];
        // Mailgun v3: custom MIME headers are 'h:<Name>' form fields
        $payload = self::withListUnsubscribe($payload, 'mailgun', $to, $senderEmail);

        $headers = [
            'Authorization: Basic ' . base64_encode('api:' . $apiKey)
        ];

        return self::executeCurl($url, $headers, http_build_query($payload), true);
    }

    /**
     * Mailjet v3.1 Send API
     */
    private static function sendMailjet(string $to, string $subject, string $body, string $apiKey, string $senderEmail): bool
    {
        $url = 'https://api.mailjet.com/v3.1/send';
        
        $auth = $apiKey;
        if (strpos($apiKey, ':') !== false) {
            $auth = base64_encode($apiKey);
        } else {
            $auth = base64_encode($apiKey . ':');
        }

        $payload = [
            'Messages' => [[
                'From' => ['Email' => $senderEmail],
                'To' => [['Email' => $to]],
                'Subject' => $subject,
                'HTMLPart' => $body
            ]]
        ];
        // Mailjet v3.1: Messages[0].Headers object
        $payload = self::withListUnsubscribe($payload, 'mailjet', $to, $senderEmail);

        $headers = [
            'Authorization: Basic ' . $auth,
            'Content-Type: application/json'
        ];

        return self::executeCurl($url, $headers, json_encode($payload));
    }

    /**
     * Postmark REST API Delivery
     */
    private static function sendPostmark(string $to, string $subject, string $body, string $apiKey, string $senderEmail): bool
    {
        $url = 'https://api.postmarkapp.com/email';
        $payload = [
            'From' => $senderEmail,
            'To' => $to,
            'Subject' => $subject,
            'HtmlBody' => $body,
            'MessageStream' => 'outbound'
        ];
        // Postmark: 'Headers' array of {Name, Value}
        $payload = self::withListUnsubscribe($payload, 'postmark', $to, $senderEmail);

        $headers = [
            'X-Postmark-Server-Token: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        return self::executeCurl($url, $headers, json_encode($payload));
    }

    /**
     * MailerSend REST API Delivery
     */
    private static function sendMailerSend(string $to, string $subject, string $body, string $apiKey, string $senderEmail): bool
    {
        $url = 'https://api.mailersend.com/v1/email';
        $payload = [
            'from' => [
                'email' => $senderEmail,
                'name' => 'Smarketer Pro'
            ],
            'to' => [
                ['email' => $to]
            ],
            'subject' => $subject,
            'html' => $body
        ];
        // MailerSend: 'headers' array of {name, value}
        $payload = self::withListUnsubscribe($payload, 'mailersend', $to, $senderEmail);

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ];

        return self::executeCurl($url, $headers, json_encode($payload));
    }

    /**
     * Mailtrap Transactional REST API Delivery
     */
    private static function sendMailtrap(string $to, string $subject, string $body, string $apiKey, string $senderEmail): bool
    {
        $url = 'https://send.api.mailtrap.io/api/send';
        $payload = [
            'from' => [
                'email' => $senderEmail,
                'name' => 'Smarketer Pro'
            ],
            'to' => [
                ['email' => $to]
            ],
            'subject' => $subject,
            'html' => $body
        ];
        // Mailtrap: 'headers' object in the payload
        $payload = self::withListUnsubscribe($payload, 'mailtrap', $to, $senderEmail);

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ];

        return self::executeCurl($url, $headers, json_encode($payload));
    }

    /**
     * Zoho ZeptoMail REST API Delivery
     */
    private static function sendZeptoMail(string $to, string $subject, string $body, string $apiKey, string $senderEmail): bool
    {
        $url = 'https://api.zeptomail.com/v1.1/email';
        
        // Match Python implementation signature
        $payload = [
            'from' => [
                'address' => $senderEmail,
                'name' => 'Smarketer Pro'
            ],
            'to' => [
                [
                    'email_address' => [
                        'address' => $to
                    ]
                ]
            ],
            'subject' => $subject,
            'htmlbody' => $body
        ];
        // ZeptoMail: 'mime_headers' object (name => value) in the payload
        $payload = self::withListUnsubscribe($payload, 'zoho', $to, $senderEmail);

        $headers = [
            'Authorization: ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        return self::executeCurl($url, $headers, json_encode($payload));
    }

    /**
     * Pepipost (Netcore) REST API Delivery
     */
    private static function sendPepipost(string $to, string $subject, string $body, string $apiKey, string $senderEmail): bool
    {
        $url = 'https://api.pepipost.com/v5.1/mail/send';
        $payload = [
            'from' => [
                'email' => $senderEmail,
                'name' => 'Smarketer Pro'
            ],
            'subject' => $subject,
            'content' => [
                [
                    'type' => 'html',
                    'value' => $body
                ]
            ],
            'personalizations' => [
                [
                    'to' => [['email' => $to]]
                ]
            ]
        ];
        // Pepipost v5.1: 'headers' object in the payload
        $payload = self::withListUnsubscribe($payload, 'netcore', $to, $senderEmail);

        $headers = [
            'api_key: ' . $apiKey,
            'content-type: application/json'
        ];

        return self::executeCurl($url, $headers, json_encode($payload));
    }

    /**
     * Direct Outbound SMTP Socket Connection
     */
    private static function sendViaSmtpSocket(
        string $to,
        string $subject,
        string $body,
        string $provider,
        string $apiKey,
        string $senderEmail
    ): bool {
        // Load credentials for this specific provider, or fallback to global settings
        $host = \App\Database::getSetting($provider . '_smtp_host');
        $port = \App\Database::getSetting($provider . '_smtp_port');
        $user = \App\Database::getSetting($provider . '_smtp_user');
        $pass = \App\Database::getSetting($provider . '_smtp_pass');
        $enc = \App\Database::getSetting($provider . '_smtp_encryption');

        // Provider specific fallbacks
        if ($provider === 'sendpulse') {
            $host = $host ?: 'smtp-pulse.com';
            $port = $port ?: '587';
            $enc = $enc ?: 'tls';
        } elseif ($provider === 'amazon_ses') {
            // Item 3: region-selected endpoint. An explicit amazon_ses_smtp_host
            // still wins; otherwise the host is built from the validated
            // ses_region setting (default us-east-1 = pre-Item-3 behavior).
            $host = $host ?: self::sesSmtpHost();
            $port = $port ?: '587';
            $enc = $enc ?: 'tls';
        }

        // Global settings fallback if provider-specific settings are not configured
        $host = $host ?: \App\Database::getSetting('smtp_host');
        $port = $port ?: \App\Database::getSetting('smtp_port') ?: '587';
        $user = $user ?: \App\Database::getSetting('smtp_user');
        $pass = $pass ?: \App\Database::getSetting('smtp_pass');
        $enc = $enc ?: \App\Database::getSetting('smtp_encryption') ?: 'tls';

        if (empty($pass) && !empty($apiKey)) {
            $pass = $apiKey;
        }
        if (empty($user) && !empty($apiKey)) {
            $user = $apiKey;
        }

        if (empty($host) || empty($user) || empty($pass)) {
            throw new Exception("Incomplete Outbound SMTP Credentials for: {$provider}");
        }

        return self::sendSmtpSocket($to, $subject, $body, $host, (int)$port, $user, $pass, $enc, $senderEmail);
    }

    /**
     * Pure PHP Socket SMTP State Machine (Bypasses Local Sendmail/Postfix/mail())
     */
    private static function sendSmtpSocket(
        string $to,
        string $subject,
        string $body,
        string $host,
        int $port,
        string $username,
        string $password,
        string $encryption,
        string $senderEmail
    ): bool {
        $encryption = strtolower(trim($encryption));
        $socketHost = ($encryption === 'ssl' ? 'ssl://' : '') . $host;

        $socket = @fsockopen($socketHost, $port, $errno, $errstr, 15);
        if (!$socket) {
            throw new Exception("Failed to open direct SMTP socket connection to {$host}:{$port} ({$errno} - {$errstr})");
        }

        try {
            // Welcome Greeting (220)
            $response = self::readSocketResponse($socket);
            if (strpos($response, '220') !== 0) {
                throw new Exception("Direct SMTP connection welcome failed: " . trim($response));
            }

            // EHLO Handshake
            fwrite($socket, "EHLO SmarketerPro\r\n");
            $response = self::readSocketResponse($socket);
            if (strpos($response, '250') !== 0) {
                throw new Exception("EHLO SmarketerPro failed: " . trim($response));
            }

            // STARTTLS Upgrade
            if ($encryption === 'tls') {
                fwrite($socket, "STARTTLS\r\n");
                $response = self::readSocketResponse($socket);
                if (strpos($response, '220') !== 0) {
                    throw new Exception("STARTTLS command failed: " . trim($response));
                }

                $cryptoRes = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$cryptoRes) {
                    throw new Exception("STARTTLS stream encryption failed to initialize.");
                }

                // Send EHLO again on the newly encrypted channel
                fwrite($socket, "EHLO SmarketerPro\r\n");
                $response = self::readSocketResponse($socket);
                if (strpos($response, '250') !== 0) {
                    throw new Exception("EHLO upgraded channel handshake failed: " . trim($response));
                }
            }

            // AUTH LOGIN
            if (!empty($username)) {
                fwrite($socket, "AUTH LOGIN\r\n");
                $response = self::readSocketResponse($socket);
                if (strpos($response, '334') !== 0) {
                    throw new Exception("SMTP AUTH LOGIN initialization failed: " . trim($response));
                }

                fwrite($socket, base64_encode($username) . "\r\n");
                $response = self::readSocketResponse($socket);
                if (strpos($response, '334') !== 0) {
                    throw new Exception("SMTP AUTH Username rejected: " . trim($response));
                }

                fwrite($socket, base64_encode($password) . "\r\n");
                $response = self::readSocketResponse($socket);
                if (strpos($response, '235') !== 0) {
                    throw new Exception("SMTP AUTH Password rejected: " . trim($response));
                }
            }

            // MAIL FROM
            fwrite($socket, "MAIL FROM:<{$senderEmail}>\r\n");
            $response = self::readSocketResponse($socket);
            if (strpos($response, '250') !== 0) {
                throw new Exception("SMTP MAIL FROM failed: " . trim($response));
            }

            // RCPT TO
            fwrite($socket, "RCPT TO:<{$to}>\r\n");
            $response = self::readSocketResponse($socket);
            if (strpos($response, '250') !== 0) {
                throw new Exception("SMTP RCPT TO failed: " . trim($response));
            }

            // DATA
            fwrite($socket, "DATA\r\n");
            $response = self::readSocketResponse($socket);
            if (strpos($response, '354') !== 0) {
                throw new Exception("SMTP DATA init failed: " . trim($response));
            }

            // Headers & Body Stream
            $boundary = md5(uniqid((string)time(), true));
            $headers = [
                "MIME-Version: 1.0",
                "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
                "From: <{$senderEmail}>",
                "To: <{$to}>",
                "Subject: {$subject}",
                "Date: " . date('r'),
                "X-Mailer: PHP/SmarketerProOutboundSocket"
            ];
            // One-click unsubscribe headers (RFC 2369 / RFC 8058) — Gmail/Yahoo
            // penalize bulk mail without them, so missing headers cost you inbox placement.
            foreach (Compliance::listUnsubscribeHeaders($to, $senderEmail) as $h) {
                $headers[] = $h;
            }

            $message = implode("\r\n", $headers) . "\r\n\r\n";
            $message .= "--{$boundary}\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $body . "\r\n\r\n";
            $message .= "--{$boundary}--\r\n";
            $message .= ".\r\n";

            fwrite($socket, $message);
            $response = self::readSocketResponse($socket);
            if (strpos($response, '250') !== 0) {
                throw new Exception("SMTP transmission failed: " . trim($response));
            }

            // QUIT
            fwrite($socket, "QUIT\r\n");
            self::readSocketResponse($socket);

            fclose($socket);
            return true;

        } catch (Exception $e) {
            @fclose($socket);
            throw $e;
        }
    }

    /**
     * Reads multi-line socket responses until a termination code line is parsed.
     */
    private static function readSocketResponse($socket): string
    {
        $response = "";
        while ($line = fgets($socket, 1024)) {
            $response .= $line;
            // The 4th character of an SMTP response is a space ' ' for the final line, or '-' for intermediate lines
            if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }

    /**
     * Executes outbound HTTPS cURL transaction
     */
    private static function executeCurl(string $url, array $headers, $payload, bool $isForm = false): bool
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("HTTP Outbound Connection Failure: " . $error);
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        throw new Exception("HTTP Error Response [{$httpCode}]: " . trim(strip_tags($response)));
    }
}
