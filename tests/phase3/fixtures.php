<?php
/**
 * Phase 3 test fixtures.
 *
 * Heuristic fixtures are derived from the Python keyword cases in
 * smarketer-pro/src/agents/reply_classifier.py::_heuristic_fallback
 * (same keyword sets, same check order), plus new `not_now` and
 * `hostile` cases for the plan's two extra routing intents.
 *
 * Each fixture: [subject, body, expected_intent, expected_needs_human, expected_urgency]
 */
declare(strict_types=1);

$HEURISTIC_FIXTURES = [
    // --- Ported verbatim from the Python keyword cases ---
    ['Re: your pitch', 'Please unsubscribe me from your list.', 'unsubscribe', false, 1],
    ['hello', 'Please remove me from your list', 'unsubscribe', false, 1],
    ['hello', 'I want to opt out of these emails', 'unsubscribe', false, 1],
    ['hello', 'Do not contact me again', 'unsubscribe', false, 1],
    ['Undelivered Mail Returned to Sender', 'mailer-daemon: delivery failure to recipient', 'bounce', false, 1],
    ['hello', 'This message was undeliverable', 'bounce', false, 1],
    ['Out of office', 'I am on vacation this week. This is an autoreply.', 'out_of_office', false, 1],
    ['hello', 'ooo — back Thursday', 'out_of_office', false, 1],
    ['Re: proposal', 'Not interested, thanks.', 'objection', true, 4],
    ['hello', 'no thanks', 'objection', true, 4],
    ['hello', 'Please stop emailing me', 'objection', true, 4],
    ["Re: intro", "Sounds good — let's talk next week. Send pricing.", 'positive', true, 8],
    ['hello', 'Interested! Can we schedule a demo?', 'positive', true, 8],
    ['hello', 'I would like to call you about this', 'positive', true, 8],
    ['Re: referral', 'Talk to my colleague Dana at dana@example.com', 'referral', true, 6],
    ['hello', "I'm cc'ing my boss on this", 'referral', true, 6],
    ['hello', 'contact my assistant to set something up', 'referral', true, 6],
    ['hello', 'thanks, have a nice day', 'other', true, 3],
    // --- New not_now cases (plan routing intent; no Python definition) ---
    ['Re: follow up', 'Can we reconnect next quarter?', 'not_now', true, 2],
    ['hello', 'Not right now — check back in a few months.', 'not_now', true, 2],
    ['hello', 'Bad time, too busy. Follow up later please.', 'not_now', true, 2],
    ['hello', 'Circle back to me next quarter when budgets reset.', 'not_now', true, 2],
    // --- New hostile cases (plan routing intent; no Python definition) ---
    ['Re:', 'Screw you, stop emailing me!', 'hostile', true, 7],
    ['hello', 'I will sue you if you email me again.', 'hostile', true, 7],
    ['hello', 'This is harassment. You have been reported.', 'hostile', true, 7],
    ['hello', 'You are an idiot, shut up and go away', 'hostile', true, 7],
    ['hello', 'I am taking legal action over these emails', 'hostile', true, 7],
];

/**
 * Routing fixtures: [verdict_fields..., expected_action, expected_routed_to_human].
 * All verdicts below use needs_human=false + confidence=1.0 so guardrail 2
 * (low-confidence / needs-human → human review) does not fire, letting each
 * intent's own routing branch be verified.
 *
 * Fields per fixture: intent, urgency, expected_action, expected_human
 */
$ROUTING_FIXTURES = [
    ['positive',     8, 'human_follow_up', true],
    ['objection',    4, 'human_review',    true],
    ['referral',     6, 'human_review',    true],
    ['other',        3, 'human_review',    true],
    ['not_now',      2, 'nurtured',        false],
    ['bounce',       1, 'auto_handled',    false],
    ['out_of_office',1, 'auto_handled',    false],
    // unsubscribe + hostile need Compliance::suppress() → live DB; see test_router.php
    ['unsubscribe',  1, 'suppressed',      false],
    ['hostile',      7, 'archived',        false],
];
