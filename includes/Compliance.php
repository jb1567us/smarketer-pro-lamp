<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\OutreachException;

/**
 * Compliance — CAN-SPAM / CASL / GDPR sending guardrails.
 *
 * Single choke point: EmailSender::send() calls Compliance::requireCompliantSend()
 * before any provider path, so no email can leave the system without:
 *   1. Suppression-list check (opt-outs, bounces, complaints are never mailed).
 *   2. Mandatory sender identity (legal name + physical postal address) — the
 *      footer is appended by the caller via Compliance::footer().
 *   3. CASL harvest gate: Canadian addresses with unknown consent are refused
 *      unless the buyer explicitly disabled the block in settings (audit trail).
 *
 * The buyer remains the data controller and is liable for their own sending
 * practices; this class makes non-compliant sending difficult and deliberate,
 * not one click.
 */
class Compliance
{
    /**
     * Per-install secret for unsubscribe tokens. Written by install.php to
     * config/app_secret.php; falls back to a settings-table value created
     * once and reused (so tokens survive across requests).
     */
    public static function appSecret(): string
    {
        $file = dirname(__DIR__) . '/config/app_secret.php';
        if (is_file($file)) {
            $s = require $file;
            if (is_string($s) && strlen($s) >= 32) {
                return $s;
            }
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'app_secret'");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && is_string($row['setting_value']) && strlen($row['setting_value']) >= 32) {
            return $row['setting_value'];
        }
        $secret = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('app_secret', ?) " .
            "ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $stmt->execute([$secret]);
        return $secret;
    }

    /** Signed, unguessable token for a recipient's one-click unsubscribe. */
    public static function unsubscribeToken(string $email): string
    {
        $email = strtolower(trim($email));
        $sig = hash_hmac('sha256', $email, self::appSecret());
        return rtrim(strtr(base64_encode($email), '+/', '-_'), '=') . '.' . $sig;
    }

    /** Verify a token and return the email it was minted for, or null. */
    public static function verifyUnsubscribeToken(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $email = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if (!is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $email = strtolower(trim($email));
        $expected = hash_hmac('sha256', $email, self::appSecret());
        return hash_equals($expected, $parts[1]) ? $email : null;
    }

    /** Public unsubscribe URL for a recipient (null when app URL unknown). */
    public static function unsubscribeUrl(string $email): ?string
    {
        $base = trim((string)(Database::getSetting('app_base_url', '') ?? ''));
        if ($base === '') {
            return null;
        }
        return rtrim($base, '/') . '/unsubscribe.php?token=' . urlencode(self::unsubscribeToken($email));
    }

    public static function isSuppressed(string $email): bool
    {
        $email = strtolower(trim($email));
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("SELECT 1 FROM suppression_list WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            return (bool)$stmt->fetch();
        } catch (\Throwable $e) {
            // Fail closed: if we cannot check suppression, do not send.
            error_log('[Compliance] suppression check failed: ' . $e->getMessage());
            return true;
        }
    }

    public static function suppress(string $email, string $reason = 'unsubscribe', ?string $source = null): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            "INSERT INTO suppression_list (email, reason, source) VALUES (?, ?, ?) " .
            "ON DUPLICATE KEY UPDATE reason = VALUES(reason), source = VALUES(source)"
        );
        $stmt->execute([$email, $reason, $source]);
    }

    /** Heuristic Canadian-address detection for the CASL harvest gate. */
    public static function isCanadianAddress(string $email): bool
    {
        return (bool)preg_match('/\.ca$/i', trim($email));
    }

    /**
     * Enforce every pre-send compliance rule. Throws on any violation —
     * callers must not catch-and-continue past this.
     */
    public static function requireCompliantSend(string $to, ?array $lead = null): void
    {
        $to = trim($to);

        if (self::isSuppressed($to)) {
            throw new OutreachException(
                "Refusing to send: {$to} is on the suppression list (opt-out, bounce, or complaint)."
            );
        }

        $legalName = trim((string)(Database::getSetting('company_legal_name', '') ?? ''));
        $postal = trim((string)(Database::getSetting('physical_address', '') ?? ''));
        if ($legalName === '' || $postal === '') {
            throw new OutreachException(
                'Refusing to send: sender identity is not configured. Set "Company legal name" and ' .
                '"Physical postal address" in System Settings (required by CAN-SPAM for every commercial email).'
            );
        }

        // CASL harvest gate: harvested/unknown-consent addresses that look
        // Canadian cannot be mailed without express consent. The buyer can
        // disable the block in settings — that toggle IS the audit trail.
        $blockCa = Database::getSetting('compliance_casl_ca_block', '1') === '1';
        if ($blockCa && self::isCanadianAddress($to)) {
            if ($lead === null) {
                try {
                    $pdo = Database::getConnection();
                    $stmt = $pdo->prepare("SELECT consent_status FROM leads WHERE email = ? LIMIT 1");
                    $stmt->execute([strtolower($to)]);
                    $lead = $stmt->fetch() ?: [];
                } catch (\Throwable $e) {
                    $lead = [];
                }
            }
            $consent = $lead['consent_status'] ?? 'unknown';
            if ($consent !== 'express') {
                throw new OutreachException(
                    "Refusing to send to {$to}: Canada's CASL prohibits commercial email to harvested " .
                    "addresses without express consent. Record express consent for this lead, or disable " .
                    "'Block unconsented Canadian sends' in System Settings (you assume the legal risk)."
                );
            }
        }
    }

    /**
     * Mandatory identity footer, appended to every outgoing message.
     * Returns [textFooter, htmlFooter]; the caller picks by body type.
     */
    public static function footer(string $to): array
    {
        $legalName = trim((string)(Database::getSetting('company_legal_name', '') ?? ''));
        $postal = trim((string)(Database::getSetting('physical_address', '') ?? ''));
        $unsub = self::unsubscribeUrl($to);

        $optOutText = $unsub
            ? "To stop receiving these emails, unsubscribe here: {$unsub}"
            : "To stop receiving these emails, reply with \"unsubscribe\".";
        $text = "\n\n--\n{$legalName}\n{$postal}\n\n{$optOutText}\n";

        $optOutHtml = $unsub
            ? 'To stop receiving these emails, <a href="' . htmlspecialchars($unsub) . '">unsubscribe here</a>.'
            : 'To stop receiving these emails, reply with "unsubscribe".';
        $html = '<br><br>--<br>' . htmlspecialchars($legalName) . '<br>' .
            nl2br(htmlspecialchars($postal)) . '<br><br>' . $optOutHtml;

        return [$text, $html];
    }

    /** Append the compliance footer to a body, detecting HTML vs text. */
    public static function appendFooter(string $body, string $to): string
    {
        [$text, $html] = self::footer($to);
        $isHtml = stripos($body, '<html') !== false || stripos($body, '<p') !== false
            || stripos($body, '<br') !== false || stripos($body, '<div') !== false;
        return $body . ($isHtml ? $html : $text);
    }

    /** List-Unsubscribe headers for raw-SMTP and provider APIs that accept them. */
    public static function listUnsubscribeHeaders(string $to, string $senderEmail): array
    {
        $headers = [];
        $url = self::unsubscribeUrl($to);
        if ($url !== null) {
            $headers[] = 'List-Unsubscribe: <' . $url . '>';
            $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
        } else {
            $headers[] = 'List-Unsubscribe: <mailto:' . $senderEmail . '?subject=unsubscribe>';
        }
        return $headers;
    }
}
