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
     * Send an email utilizing outbound HTTPS APIs directly or a direct SMTP socket connection.
     * 
     * @param string $to Recipient Email Address
     * @param string $subject Email Subject
     * @param string $body Email Body (HTML/Text)
     * @param string $provider brevo | sendgrid | mailgun | resend | mailjet | postmark | mailersend | mailtrap | zoho | netcore | smtp | custom_smtp | sendpulse | amazon_ses | zoho_smtp | netcore_smtp
     * @param string $apiKey The API Key / Token / SMTP Password
     * @param string $senderEmail The Authorized SPF/DKIM Verified Sender
     * @param string|null $mailgunDomain Required for Mailgun domain (or falls back to smtp_host / generic domain)
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
        ?string $mailgunDomain = null
    ): bool {
        if (self::isPlaceholderAddress($to)) {
            throw new Exception("Refusing to send to placeholder address: {$to}");
        }

        // Compliance choke point: suppression list, mandatory sender identity,
        // and the CASL harvest gate. Throws on any violation.
        Compliance::requireCompliantSend($to);

        // Mandatory identity footer (CAN-SPAM): legal name + postal address
        // + one-click unsubscribe on every message, all providers.
        $body = Compliance::appendFooter($body, $to);

        if (empty($senderEmail)) {
            throw new Exception("Authorized Sender Address is required to satisfy SPF/DKIM compliance");
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
     * Providers not listed here (Brevo, Mailjet, Postmark, MailerSend,
     * Mailtrap, ZeptoMail, Pepipost) still get the mandatory footer +
     * suppression enforcement in send(); their native unsubscribe tooling
     * is documented in the sending-stack guide.
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
            $host = $host ?: 'email-smtp.us-east-1.amazonaws.com';
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
            // One-click unsubscribe headers (RFC 2369 / RFC 8058) — required
            // for bulk mail at Gmail/Yahoo and expected by CAN-SPAM practice.
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
