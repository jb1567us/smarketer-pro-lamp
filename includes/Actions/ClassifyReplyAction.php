<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jev\DecisionTier;
use App\Jev\JevProvider;

/**
 * ClassifyReplyAction — Phase 3 port of the Python ReplyClassifierAgent's
 * JEV-native path (smarketer-pro: src/agents/reply_classifier.py).
 *
 * Classifies an inbound outreach reply with ONE batched JEV call answering
 * intent [Choice], needs_human [Noul], and urgency [Score]. There is no
 * legacy LLM path to preserve — this action is JEV-native, with a rule-based
 * keyword fallback when JEV is off or errors.
 *
 * Modes (same contract as every other JEV decision point):
 *   off    — JEV bypassed; the keyword heuristic runs and is returned.
 *   shadow — JEV runs and its verdict is logged by DecisionTier::decide,
 *            but the heuristic result is returned. Zero behavior change.
 *   live   — the JEV result is returned. Low confidence or any JEV error
 *            fails soft to the heuristic result (never throws).
 *
 * Fail-soft: any JEV error, timeout, or sub-threshold confidence returns
 * the heuristic classification with confidence 0.0 and source 'heuristic'.
 * A failed classification must never crash a sequence or routing step.
 */
class ClassifyReplyAction extends AbstractAction
{
    public const DECISION = 'reply_classifier.classify';

    /** Hard timeout for the batched classification call (plan: <=8s). */
    public const TIMEOUT_S = 8;

    /**
     * Reply intent options = the Python REPLY_INTENTS plus the plan's extra
     * routing intents `not_now` and `hostile`.
     */
    public const INTENTS = [
        'positive',
        'objection',
        'unsubscribe',
        'bounce',
        'out_of_office',
        'referral',
        'not_now',
        'hostile',
        'other',
    ];

    /**
     * TypeSafe requires criteria on choice questions. The first seven are
     * ported verbatim from the Python classifier; `not_now` and `hostile`
     * have no Python definitions and are defined here.
     */
    public const INTENT_CRITERIA = [
        'positive'      => 'Interested; asks for a call, demo, pricing, or more info.',
        'objection'     => 'Pushback on price, timing, fit, or authority — but still engaged.',
        'unsubscribe'   => 'Explicit opt-out or do-not-contact request.',
        'bounce'        => 'Delivery failure / undeliverable notice.',
        'out_of_office' => 'Autoresponder / away message.',
        'referral'      => 'Directs you to a different person.',
        'not_now'       => 'Defers or asks to be contacted later — "not right now", '
            . '"check back next quarter"; no hard refusal, door left open.',
        'hostile'       => 'Angry, abusive, or threatening — insults, profanity, '
            . 'legal threats, or harassment complaints.',
        'other'         => 'None of the above.',
    ];

    /**
     * ActionInterface contract. Classification is stateless and driven via
     * classify(); there is no per-lead side effect to perform, so this is
     * a deliberate no-op returning false.
     */
    public function execute(int $leadId): bool
    {
        return false;
    }

    /**
     * Classify an inbound reply.
     *
     * @param string $subject       Email subject (truncated to 500 chars).
     * @param string $body          Email body (truncated to 4000 chars).
     * @param string $threadContext Optional prior-thread context (truncated to 1000 chars).
     *
     * @return array{intent:string,needs_human:bool,urgency:int,confidence:float,
     *               source:string,latency_ms:int,note?:string}
     *   - intent: one of self::INTENTS
     *   - needs_human: true when a human must read the reply before any automated follow-up
     *   - urgency: 1-10 (10 = hot positive reply asking for a call now, 1 = ignore)
     *   - confidence: 0.0 for heuristic results; JEV verdict confidence otherwise
     *   - source: 'jev' | 'heuristic'
     *   - latency_ms: wall-clock time for the classification
     *   - note: present only on heuristic results, explaining why
     */
    public function classify(string $subject, string $body, string $threadContext = ''): array
    {
        $state = [
            'subject'        => mb_substr($subject, 0, 500),
            'body'           => mb_substr($body, 0, 4000),
            'thread_context' => mb_substr($threadContext, 0, 1000),
        ];

        $questions = [
            'intent' => JevProvider::choiceQuestion(
                'Classify the reply\'s intent.',
                self::INTENTS,
                self::INTENT_CRITERIA
            ),
            'needs_human' => JevProvider::noulQuestion(
                'This reply needs a human to read it before any automated '
                . 'follow-up is sent (positive replies, objections, referrals, '
                . 'hostile or ambiguous replies). False only for clear bounces, '
                . 'out-of-office notices, and unsubscribes that automation handles.'
            ),
            'urgency' => JevProvider::scoreQuestion(
                'How urgently should the sales team act on this reply?',
                self::urgencyCriteria()
            ),
        ];

        // Fail-soft fallback: keyword heuristics only. Runs as the shadow
        // comparator too, so callers always get a usable classification.
        $fallback = fn(): array => $this->heuristicFallback($state['subject'], $state['body']);

        $t0 = microtime(true);
        try {
            $raw = DecisionTier::decide(
                self::DECISION,
                $state,
                $questions,
                $fallback,
                null,
                null,
                self::TIMEOUT_S
            );
        } catch (\Throwable $e) {
            error_log('[ClassifyReplyAction] DecisionTier::decide threw: ' . $e->getMessage());
            $raw = $fallback();
        }
        $latencyMs = (int)((microtime(true) - $t0) * 1000);

        $result = $this->normalizeResult($raw);
        $result['latency_ms'] = $latencyMs;
        return $result;
    }

    /**
     * Normalize either raw JEV answers or an already-normalized heuristic
     * result (off/shadow modes, JEV errors) into one result shape.
     */
    private function normalizeResult($raw): array
    {
        // Heuristic path already normalized (has intent + source, no nested answer dicts).
        if (is_array($raw) && array_key_exists('intent', $raw)
            && !is_array($raw['intent'] ?? null)) {
            return $raw;
        }

        $intent = is_array($raw['intent'] ?? null) ? $raw['intent'] : [];
        $noul   = is_array($raw['needs_human'] ?? null) ? $raw['needs_human'] : [];
        $score  = is_array($raw['urgency'] ?? null) ? $raw['urgency'] : [];

        $choice = $intent['choice'] ?? 'other';
        if (!in_array($choice, self::INTENTS, true)) {
            $choice = 'other';
        }

        // Score question returns a position on the 0..(levels-1) spectrum;
        // urgency is that position + 1, clamped to 1-10.
        $urgency = (int)round((float)($score['score'] ?? 0)) + 1;
        $urgency = max(1, min(10, $urgency));

        return [
            'intent'       => $choice,
            'needs_human'  => ((float)($noul['noul'] ?? 0)) >= 0.5,
            'urgency'      => $urgency,
            // Mirrors the Python port: confidence = min of the answers' confidences.
            'confidence'   => min(
                (float)($intent['confidence'] ?? 1.0),
                (float)($score['confidence'] ?? 1.0)
            ),
            'source'       => 'jev',
        ];
    }

    /**
     * Score criteria for the 1-10 urgency spectrum, low end first.
     *
     * @return string[] 10 ordered level descriptions.
     */
    private static function urgencyCriteria(): array
    {
        return [
            '1 — Ignore: bounce, out-of-office, or automated notice. No action needed.',
            '2 — Very low: unsubscribe or a soft "not now". Automation or deferral handles it.',
            '3 — Low: polite disengagement; a queued touch later is plenty.',
            '4 — Low-medium: mild objection or hesitation worth a gentle follow-up.',
            '5 — Medium: engaged but not urgent; standard follow-up cadence.',
            '6 — Medium-high: active objection, referral, or not-now with specifics — respond soon.',
            '7 — High: clear interest; respond the same business day.',
            '8 — High: positive reply asking for a call or demo — book promptly.',
            '9 — Very high: hot prospect wanting contact right now.',
            '10 — Critical: immediate action — buy signal, escalation risk, or deadline-driven reply.',
        ];
    }

    /**
     * Keyword-heuristic fallback ported from the Python `_heuristic_fallback`,
     * extended with `not_now` and `hostile` keyword sets.
     *
     * @return array{intent:string,needs_human:bool,urgency:int,confidence:float,
     *               source:string,note:string}
     */
    public function heuristicFallback(string $subject, string $body): array
    {
        $text = mb_strtolower($subject . "\n" . $body);

        if ($this->containsAny($text, ['unsubscribe', 'opt out', 'remove me', 'do not contact'])) {
            $intent = 'unsubscribe';
            $needsHuman = false;
            $urgency = 1;
        } elseif ($this->containsAny($text, ['undeliverable', 'delivery failure', 'mailer-daemon'])) {
            $intent = 'bounce';
            $needsHuman = false;
            $urgency = 1;
        } elseif ($this->containsAny($text, ['out of office', 'autoreply', 'on vacation', 'ooo'])) {
            $intent = 'out_of_office';
            $needsHuman = false;
            $urgency = 1;
        } elseif ($this->containsAny($text, [
            'fuck', 'screw you', 'idiot', 'moron', 'dumbass', 'asshole',
            'shut up', 'legal action', 'sue you', 'will sue', 'harassment', 'reported',
        ])) {
            // No Python definition — hostile routing intent from the plan.
            $intent = 'hostile';
            $needsHuman = true;
            $urgency = 7;
        } elseif ($this->containsAny($text, [
            'not right now', 'not now', 'check back', 'circle back', 'reach out later',
            'follow up later', 'in a few months', 'next quarter', 'bad time', 'too busy',
        ])) {
            // No Python definition — not_now routing intent from the plan.
            $intent = 'not_now';
            $needsHuman = true;
            $urgency = 2;
        } elseif ($this->containsAny($text, ['not interested', 'no thanks', 'stop emailing'])) {
            $intent = 'objection';
            $needsHuman = true;
            $urgency = 4;
        } elseif ($this->containsAny($text, ['call', 'demo', 'pricing', 'interested', "let's talk", 'let us talk'])) {
            $intent = 'positive';
            $needsHuman = true;
            $urgency = 8;
        } elseif ($this->containsAny($text, ['talk to', 'contact my', "cc'ing", 'ccing'])) {
            $intent = 'referral';
            $needsHuman = true;
            $urgency = 6;
        } else {
            $intent = 'other';
            $needsHuman = true;
            $urgency = 3;
        }

        return [
            'intent'       => $intent,
            'needs_human'  => $needsHuman,
            'urgency'      => $urgency,
            'confidence'   => 0.0,
            'source'       => 'heuristic',
            'note'         => 'Jev unavailable or off; keyword heuristics only.',
        ];
    }

    /**
     * Case-insensitive substring match against any keyword.
     */
    private function containsAny(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_strpos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
}
