<?php

declare(strict_types=1);

namespace App;

/**
 * Honest pre-send notice (Fix 1: provider-first sending).
 *
 * Shown at campaign launch / resume. It confirms WHICH provider account and
 * sender address will send, and states plainly what this software does NOT
 * control. Pure function — no database, no network — so it is unit-testable.
 */
class SendNotice
{
    /**
     * Human labels for provider keys. HTTP API providers first (the
     * recommended path), shared-host SMTP paths last.
     */
    public const PROVIDER_LABELS = [
        'sendgrid'    => 'SendGrid (your SendGrid account)',
        'resend'      => 'Resend (your Resend account)',
        'amazon_ses'  => 'Amazon SES (your AWS account)',
        'brevo'       => 'Brevo (your Brevo account)',
        'mailgun'     => 'Mailgun (your Mailgun account)',
        'mailjet'     => 'Mailjet (your Mailjet account)',
        'postmark'    => 'Postmark (your Postmark account)',
        'mailersend'  => 'MailerSend (your MailerSend account)',
        'zoho'        => 'ZeptoMail (your Zoho account)',
        'netcore'     => 'Pepipost (your Netcore account)',
        'mailtrap'    => 'Mailtrap (testing sandbox — no real delivery)',
        'smtp'        => 'Shared-host SMTP (advanced — not recommended)',
        'custom_smtp' => 'Custom SMTP',
        'sendpulse'   => 'SendPulse SMTP (your SendPulse account)',
        'zoho_smtp'   => 'Zoho SMTP (your Zoho account)',
        'netcore_smtp' => 'Netcore SMTP (your Netcore account)',
    ];

    /** Providers whose mail leaves the buyer's own account over HTTPS APIs. */
    public const API_PROVIDERS = [
        'sendgrid', 'resend', 'amazon_ses', 'brevo', 'mailgun', 'mailjet',
        'postmark', 'mailersend', 'zoho', 'netcore', 'mailtrap',
    ];

    /** Providers whose mail leaves the shared host over SMTP. */
    public const SMTP_PROVIDERS = [
        'smtp', 'custom_smtp', 'sendpulse', 'zoho_smtp', 'netcore_smtp',
    ];

    /**
     * Build the notice payload.
     *
     * @param string $provider      active_email_provider setting value
     * @param string $senderEmail   email_sender setting value
     * @param bool   $configured    whether the provider has credentials stored
     * @return array{provider:string,provider_label:string,sender_email:string,configured:bool,heading:string,body:string}
     */
    public static function build(string $provider, string $senderEmail, bool $configured): array
    {
        $provider = strtolower(trim($provider));
        $label = self::PROVIDER_LABELS[$provider] ?? "Unknown provider ({$provider})";

        if (in_array($provider, self::SMTP_PROVIDERS, true)) {
            $channel = 'Mail leaves from your shared host\'s IP. Shared-hosting IP ' .
                'reputation is outside our control — expect worse deliverability ' .
                'than a dedicated sending provider.';
        } elseif ($provider === 'mailtrap') {
            $channel = 'Mailtrap is a testing sandbox: messages are captured, never delivered to real inboxes.';
        } else {
            $channel = 'Messages are handed to your provider\'s API on your account, under your domain\'s reputation.';
        }

        $fromLine = $senderEmail !== ''
            ? "From: {$senderEmail}"
            : 'From: (no sender address configured — sending will fail)';

        $body = "This campaign will send via {$label}.\n" .
            "{$fromLine}\n\n" .
            "{$channel}\n\n" .
            'Whether recipients see these messages depends on your provider, ' .
            'your account reputation, your list quality, and your DNS setup ' .
            '(SPF/DKIM/DMARC) — not on this software. We make no ' .
            'inbox-placement or deliverability promises.';

        return [
            'provider'       => $provider,
            'provider_label' => $label,
            'sender_email'   => $senderEmail,
            'configured'     => $configured,
            'heading'        => 'Before this campaign sends',
            'body'           => $body,
        ];
    }

    /** Provider keys ordered for UI: API providers first, SMTP paths last. */
    public static function uiOrder(): array
    {
        return array_merge(self::API_PROVIDERS, self::SMTP_PROVIDERS);
    }
}
