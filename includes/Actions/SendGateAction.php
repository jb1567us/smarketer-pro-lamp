<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jev\DecisionTier;
use App\Jev\JevProvider;
use App\Throttles;
use App\DbThrottleStore;
use App\EmailSender;

/**
 * SendGateAction — Phase 4 decision point: final go/no-go before a queued
 * send (suppression, verification, CASL, quota, plus a JEV confidence check).
 *
 * Layering (hard ordering — compliance gates are checked FIRST and a JEV
 * verdict can never approve a send that a compliance gate blocks):
 *
 *   1. Rule-based compliance gates (no JEV, no LLM, fail-closed):
 *      lead exists + valid email -> not a harvester placeholder address ->
 *      not on the suppression list -> sender identity configured ->
 *      CASL country gate -> email verification cache check ->
 *      unsubscribe-header guarantee -> throttle/quota caps.
 *      Any failure short-circuits: JEV is never consulted for a blocked send.
 *   2. JEV (batched: safe_to_send [Noul] + risk [Score]) — consulted only
 *      when every gate passes. Fail-closed: in live mode a missing JEV
 *      verdict (error, timeout, low confidence) BLOCKS the send.
 *
 * Modes (same contract as every other JEV decision point):
 *   off    — rule gates run; their verdict is returned. JEV never called.
 *   shadow — rule gates run and their verdict is returned; JEV also runs and
 *            the pair (jev_value vs rules_value) is logged to the shadow log
 *            by DecisionTier::decide. Zero behavior change.
 *   live   — rule gates run first; on any blocker the send is denied. With
 *            all gates clear, JEV must give a usable verdict: JEV denies,
 *            errors, times out, or falls below jev_min_confidence -> DENIED.
 *            The reviewer can never silently approve.
 *
 * The verdict source is always marked: 'rules' (compliance gates, or JEV
 * unavailable/off) vs 'jev' (live JEV verdict). Rule-based results carry
 * confidence 0.0, per the Phase 4 contract for JEV-off fallbacks.
 *
 * This action does NOT duplicate the EmailSender::send() choke point —
 * Compliance::requireCompliantSend() still throws there as the backstop
 * (single audited CASL decision per send attempt). The gate is the
 * pre-flight checkpoint so queued sends fail fast with buyer-actionable
 * reasons instead of dying mid-dispatch.
 */
class SendGateAction extends AbstractAction
{
    public const DECISION = 'send_gate.final_decision';

    /** Hard timeout for the batched gate call (plan: <=8s). */
    public const TIMEOUT_S = 8;

    /**
     * Lead statuses that hard-block the send gate. 'Needs Review' leads are
     * awaiting human qualification and must never be mailed; Converted /
     * Unqualified are terminal. (The sequence path additionally enforces
     * SequenceManager::SENDABLE_LEAD_STATUSES before this gate is reached.)
     */
    public const BLOCKED_STATUSES = ['Converted', 'Unqualified', 'Needs Review'];

    /**
     * ActionInterface contract. Fail-closed: any exception -> false (no send).
     */
    public function execute(int $leadId): bool
    {
        try {
            $verdict = $this->gate($leadId);
            return $verdict['allowed'];
        } catch (\Throwable $e) {
            error_log('[SendGateAction] execute failed closed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Evaluate the send gate for a lead.
     *
     * @param int   $leadId  The recipient lead.
     * @param array $context Optional: subject, body, provider, campaign_id,
     *                       force_resend.
     *
     * @return array{allowed:bool,blockers:string[],confidence:float,
     *               source:string,latency_ms:int,note?:string}
     *   - allowed:   final go/no-go.
     *   - blockers:  gate failures (empty when allowed).
     *   - source:    'rules' | 'jev'.
     *   - note:      human-readable summary (always present on blocks).
     */
    public function gate(int $leadId, array $context = []): array
    {
        $lead = $this->loadLead($leadId);
        if ($lead === null) {
            return $this->block(['lead-not-found'], 'rules', 0.0,
                "Lead {$leadId} does not exist; fail-closed.");
        }

        $blockers = $this->ruleGates($lead, $context);

        // Hard ordering: compliance blockers short-circuit — JEV is never
        // consulted for a send the gates already refuse.
        if ($blockers !== []) {
            return $this->block($blockers, 'rules', 1.0,
                'Blocked by compliance gates: ' . implode('; ', $blockers));
        }

        // All gates clear: the rules fallback approves (source 'rules',
        // confidence 0.0 per the JEV-off contract). In live mode the JEV
        // verdict must be usable or the send is denied (fail-closed).
        $fallback = fn(): array => [
            'allowed' => true,
            'blockers' => [],
            'confidence' => 0.0,
            'source' => 'rules',
        ];

        $state = $this->buildState($lead, $context);
        $questions = [
            'safe_to_send' => JevProvider::noulQuestion(
                'This send is safe, appropriate, and on-policy: the recipient ' .
                'passed suppression, consent/country, and verification checks; ' .
                'the subject and body are non-deceptive, match the campaign ' .
                'purpose, and carry no risky claims or prohibited content.'
            ),
            'risk' => JevProvider::scoreQuestion(
                'Residual risk of this send (spam complaints, bounces, ' .
                'reputation damage, legal exposure).',
                self::riskCriteria()
            ),
        ];

        $t0 = microtime(true);
        try {
            $raw = DecisionTier::decide(
                self::DECISION,
                $state,
                $questions,
                $fallback,
                fn($mixed) => $this->extractAllowed($mixed),
                fn($jv, $lv) => $jv === $lv,
                self::TIMEOUT_S
            );
        } catch (\Throwable $e) {
            error_log('[SendGateAction] DecisionTier::decide threw: ' . $e->getMessage());
            $raw = $fallback();
        }
        $latencyMs = (int)((microtime(true) - $t0) * 1000);

        $verdict = $this->normalize($raw);
        $verdict['latency_ms'] = $latencyMs;

        // Fail-closed: in live mode a non-JEV result means JEV errored, timed
        // out, or fell below jev_min_confidence (DecisionTier already
        // escalated to the fallback) — with no usable JEV verdict, no send.
        if (DecisionTier::mode() === 'live' && $verdict['source'] !== 'jev') {
            return $this->block(
                ['no-jev-verdict'],
                'rules',
                0.0,
                'Live mode: JEV gave no usable verdict (error/timeout/low confidence); fail-closed.'
            );
        }

        return $verdict;
    }

    // ------------------------------------------------------------------
    // Rule-based compliance gates (layer 1). Each returns a blocker string
    // or null. Checked in choke-point order; every failure is a hard deny.
    // ------------------------------------------------------------------

    /**
     * @return string[] Blocker reasons; empty when every gate passes.
     */
    public function ruleGates(array $lead, array $context = []): array
    {
        $blockers = [];

        $email = strtolower(trim((string)($lead['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['invalid-email'];
        }

        // Terminal / under-review leads are never mailed, whatever else holds.
        if (in_array((string)($lead['status'] ?? ''), self::BLOCKED_STATUSES, true)) {
            $blockers[] = 'blocked-lead-status';
        }

        if (EmailSender::isPlaceholderAddress($email)) {
            $blockers[] = 'placeholder-address';
        }

        if ($this->isSuppressed($email)) {
            $blockers[] = 'suppressed';
        }

        if (!$this->senderIdentityConfigured()) {
            $blockers[] = 'sender-identity-missing';
        }

        $casl = $this->caslBlocker($lead);
        if ($casl !== null) {
            $blockers[] = $casl;
        }

        $ver = $this->verificationBlocker($lead);
        if ($ver !== null) {
            $blockers[] = $ver;
        }

        if (!$this->unsubscribeGuaranteed()) {
            $blockers[] = 'unsubscribe-unconfigured';
        }

        $quota = $this->quotaBlocker($context);
        if ($quota !== null) {
            $blockers[] = $quota;
        }

        return array_values(array_unique($blockers));
    }

    /**
     * Suppression check against suppression_list (email leg + keyed-hash leg),
     * mirroring Compliance::isSuppressed(). Fail-closed: on lookup failure,
     * the address is treated as suppressed.
     */
    private function isSuppressed(string $email): bool
    {
        try {
            $stmt = $this->pdo->prepare("SELECT 1 FROM suppression_list WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                return true;
            }
            // GDPR-erasure leg: keyed hash rows carry no plaintext. Probe for
            // the column; skip the leg when the GDPR DDL was never applied.
            try {
                $probe = $this->pdo->query(
                    "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() " .
                    "AND TABLE_NAME = 'suppression_list' AND COLUMN_NAME = 'email_hash' LIMIT 1"
                );
                if ($probe && $probe->fetch()) {
                    $hash = hash_hmac('sha256', $email, $this->appSecret());
                    $stmt2 = $this->pdo->prepare("SELECT 1 FROM suppression_list WHERE email_hash = ? LIMIT 1");
                    $stmt2->execute([$hash]);
                    return (bool)$stmt2->fetch();
                }
            } catch (\Throwable $e) {
                // Hash leg unavailable — the plaintext leg already passed.
            }
            return false;
        } catch (\Throwable $e) {
            error_log('[SendGateAction] suppression lookup failed closed: ' . $e->getMessage());
            return true;
        }
    }

    /**
     * Sender identity (CAN-SPAM): company legal name + physical postal
     * address must be configured, mirroring requireCompliantSend().
     */
    private function senderIdentityConfigured(): bool
    {
        return trim($this->setting('company_legal_name', '')) !== ''
            && trim($this->setting('physical_address', '')) !== '';
    }

    /**
     * CASL country gate, mirroring Compliance's semantics (keyed on
     * leads.country_code + consent_status, master toggle
     * compliance_casl_ca_block, default ON). Returns a blocker string or null.
     *
     * The choke point (requireCompliantSend -> caslCheckForSend) stays the
     * audited decision point; this pre-check only fails fast.
     */
    private function caslBlocker(array $lead): ?string
    {
        try {
            if ($this->setting('compliance_casl_ca_block', '1') !== '1') {
                return null; // Master toggle off.
            }
            $country = strtoupper(trim((string)($lead['country_code'] ?? '')));
            $consent = strtolower(trim((string)($lead['consent_status'] ?? 'unknown')));
            if ($consent === 'express') {
                return null;
            }
            if ($country === 'CA') {
                return 'casl-ca-no-express-consent';
            }
            if ($country === '') {
                return 'casl-unknown-country-no-express-consent';
            }
            return null;
        } catch (\Throwable $e) {
            error_log('[SendGateAction] CASL check failed closed: ' . $e->getMessage());
            return 'casl-check-failed';
        }
    }

    /**
     * Email-verification cache check. Mirrors Compliance's verification gate
     * semantics against the lead's cached verdict: 'invalid' always blocks,
     * 'risky' blocks when verification_risky_action='block', 'unknown'
     * blocks only when verification_strict='1' (fail-open default). The live
     * provider call stays at the Compliance choke point — this gate only
     * consults the cache, so it adds no network and needs no timeout.
     */
    private function verificationBlocker(array $lead): ?string
    {
        try {
            if ($this->setting('verification_required', '0') !== '1') {
                return null; // Gate disabled.
            }
            if (trim($this->setting('verification_api_key', '')) === '') {
                return null; // No key: the choke point no-ops; so do we.
            }
            $status = strtolower(trim((string)($lead['verification_status'] ?? 'unknown')));
            if ($status === 'invalid') {
                return 'verification-invalid';
            }
            if ($status === 'risky' && $this->setting('verification_risky_action', 'block') === 'block') {
                return 'verification-risky';
            }
            if ($status === 'unknown' && $this->setting('verification_strict', '0') === '1') {
                return 'verification-unknown-strict';
            }
            return null;
        } catch (\Throwable $e) {
            error_log('[SendGateAction] verification check failed closed: ' . $e->getMessage());
            return 'verification-check-failed';
        }
    }

    /**
     * List-Unsubscribe guarantee: EmailSender attaches the header to every
     * send, but the URL requires app_base_url. Without it the send would
     * violate the one-click-unsubscribe promise.
     */
    private function unsubscribeGuaranteed(): bool
    {
        try {
            return trim($this->setting('app_base_url', '')) !== '';
        } catch (\Throwable $e) {
            error_log('[SendGateAction] unsubscribe check failed closed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Quota/throttle caps via the same Throttles store the queue worker
     * uses (per-campaign daily cap, per-provider daily cap, global per
     * minute). A cap hit is a blocker, not an exception.
     */
    private function quotaBlocker(array $context): ?string
    {
        try {
            $campaignId = isset($context['campaign_id']) ? (int)$context['campaign_id'] : null;
            $provider = isset($context['provider']) && $context['provider'] !== ''
                ? (string)$context['provider'] : null;
            if ($campaignId === null && $provider === null) {
                return null; // Nothing to check against.
            }
            $decision = (new Throttles(new DbThrottleStore($this->pdo)))
                ->checkSend($campaignId, $provider);
            if (!$decision->allowed) {
                return 'quota:' . $decision->code;
            }
            return null;
        } catch (\Throwable $e) {
            error_log('[SendGateAction] throttle check failed closed: ' . $e->getMessage());
            return 'quota-check-failed';
        }
    }

    // ------------------------------------------------------------------
    // JEV normalization
    // ------------------------------------------------------------------

    /** Risk spectrum, low end first (JevValidationException-safe: 5 levels). */
    private static function riskCriteria(): array
    {
        return [
            'Negligible: clean recipient, consented, verified, on-topic content.',
            'Low: minor signals — e.g. implied consent only, older verification.',
            'Medium: several caution flags; a human would glance at it.',
            'High: likely to bounce or complain; sending risks reputation.',
            'Unacceptable: do not send — legal or reputation harm probable.',
        ];
    }

    private function buildState(array $lead, array $context): array
    {
        return [
            'lead_email'          => (string)($lead['email'] ?? ''),
            'lead_status'         => (string)($lead['status'] ?? ''),
            'lead_country'        => (string)($lead['country_code'] ?? ''),
            'lead_consent'        => (string)($lead['consent_status'] ?? ''),
            'lead_verification'   => (string)($lead['verification_status'] ?? ''),
            'campaign_id'         => $context['campaign_id'] ?? $lead['campaign_id'] ?? null,
            'provider'            => $context['provider'] ?? '',
            'subject'             => mb_substr((string)($context['subject'] ?? ''), 0, 500),
            'body'                => mb_substr((string)($context['body'] ?? ''), 0, 4000),
            // The gates ran before JEV was consulted; the model sees what
            // was checked so its verdict is informed, not redundant.
            'gates_passed'        => 'suppression, sender identity, CASL, verification cache, unsubscribe, quota',
        ];
    }

    /** Extract the comparable verdict value from either a JEV answer map or a normalized rules verdict. */
    private function extractAllowed($mixed): bool
    {
        if (is_array($mixed) && isset($mixed['safe_to_send']) && is_array($mixed['safe_to_send'])) {
            return ((float)($mixed['safe_to_send']['noul'] ?? 0)) >= 0.5;
        }
        return (bool)($mixed['allowed'] ?? false);
    }

    /**
     * Normalize either raw JEV answers or an already-normalized verdict
     * (off mode / fallback) into the verdict shape. A JEV denial carries a
     * blocker so the reason is visible downstream.
     */
    private function normalize($raw): array
    {
        if (is_array($raw) && array_key_exists('allowed', $raw)
            && !is_array($raw['allowed'] ?? null)) {
            return $raw + ['latency_ms' => 0];
        }
        $noul = is_array($raw['safe_to_send'] ?? null) ? $raw['safe_to_send'] : [];
        $risk = is_array($raw['risk'] ?? null) ? $raw['risk'] : [];
        $allowed = ((float)($noul['noul'] ?? 0)) >= 0.5;
        $riskPct = JevProvider::scoreToPercent((float)($risk['score'] ?? 0), count(self::riskCriteria()));
        return [
            'allowed'    => $allowed,
            'blockers'   => $allowed ? [] : ['jev-denied'],
            'confidence' => (float)($noul['confidence'] ?? 0),
            'source'     => 'jev',
            'risk_pct'   => $riskPct,
            'note'       => $allowed
                ? sprintf('JEV approved (confidence %.2f, risk %.0f/100).', (float)($noul['confidence'] ?? 0), $riskPct)
                : sprintf('JEV denied (confidence %.2f, risk %.0f/100).', (float)($noul['confidence'] ?? 0), $riskPct),
        ];
    }

    private function block(array $blockers, string $source, float $confidence, string $note): array
    {
        return [
            'allowed' => false,
            'blockers' => $blockers,
            'confidence' => $confidence,
            'source' => $source,
            'latency_ms' => 0,
            'note' => $note,
        ];
    }

    // ------------------------------------------------------------------
    // Data access (all through $this->pdo — no Database::getConnection(),
    // so the gate stays testable with a scripted fake)
    // ------------------------------------------------------------------

    private function loadLead(int $leadId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM leads WHERE id = ? LIMIT 1");
        $stmt->execute([$leadId]);
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        return $row === false || $row === null ? null : $row;
    }

    private function setting(string $key, string $default): string
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([$key]);
        $row = $stmt->fetch(\App\PDO::FETCH_ASSOC);
        $val = $row['setting_value'] ?? null;
        return $val === null || $val === '' ? $default : (string)$val;
    }

    private function appSecret(): string
    {
        $file = dirname(__DIR__, 2) . '/config/app_secret.php';
        if (is_file($file)) {
            $s = require $file;
            if (is_string($s) && strlen($s) >= 32) {
                return $s;
            }
        }
        // Test/seed path: a stored setting; absent -> ephemeral (the hash leg
        // then simply never matches a row, which is safe).
        return $this->setting('app_secret', bin2hex(random_bytes(32)));
    }
}
