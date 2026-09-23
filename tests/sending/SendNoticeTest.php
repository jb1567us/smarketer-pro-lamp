<?php
/**
 * Fix 1 — provider-first sending tests.
 *
 * Asserts the honest-sending behavior:
 *  1. \App\SendNotice::build() labels providers honestly, states what this
 *     software does NOT control, and never promises inbox placement or
 *     deliverability outcomes.
 *  2. The Settings UI presents API providers first as "Recommended" and
 *     demotes shared-host SMTP to an "Advanced — not recommended" section
 *     with honest copy.
 *  3. UI copy, docs, and JS contain no inbox-placement / deliverability
 *     promises (negations like "makes no inbox-placement promises" are
 *     allowed; "protect deliverability" as advice is allowed).
 *  4. The campaigns API attaches the pre-send notice on launch/resume.
 *
 * Usage: php tests/sending/SendNoticeTest.php
 * Pure PHP: no database, no network.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
require $repo . '/includes/autoload.php';

$passed = 0;
$failed = 0;

function fix1_ok(bool $cond, string $name): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: {$name}\n";
    } else {
        $failed++;
        echo "  FAIL: {$name}\n";
    }
}

/* ── 1. SendNotice::build() honesty ─────────────────────────────────────── */

$n = \App\SendNotice::build('sendgrid', 'hello@example.com', true);
fix1_ok(strpos($n['body'], 'SendGrid') !== false, 'sendgrid label names the buyer account');
fix1_ok(strpos($n['body'], 'your account reputation') !== false, 'notice cites account reputation');
fix1_ok(strpos($n['body'], 'list quality') !== false, 'notice cites list quality');
fix1_ok(strpos($n['body'], 'DNS') !== false, 'notice cites DNS setup');
fix1_ok(strpos($n['body'], 'not on this software') !== false, 'notice disclaims software responsibility');
fix1_ok(strpos($n['body'], 'no') !== false && strpos($n['body'], 'inbox-placement') !== false,
    'notice explicitly disclaims inbox-placement promises');
fix1_ok($n['configured'] === true, 'configured flag passes through (true)');
fix1_ok($n['sender_email'] === 'hello@example.com', 'sender email passed through');

$nSmtp = \App\SendNotice::build('smtp', 'me@example.com', false);
fix1_ok(strpos($nSmtp['provider_label'], 'not recommended') !== false,
    'smtp provider label carries the not-recommended demotion');
fix1_ok(strpos($nSmtp['body'], "shared host's IP") !== false,
    'smtp notice says mail leaves from the shared host IP');
fix1_ok(strpos($nSmtp['body'], 'outside our control') !== false,
    'smtp notice says IP reputation is outside our control');
fix1_ok(strpos($nSmtp['body'], 'not on this software') !== false,
    'smtp notice also disclaims software responsibility');

$nSes = \App\SendNotice::build('amazon_ses', 'me@example.com', true);
fix1_ok(strpos($nSes['provider_label'], 'your AWS account') !== false, 'amazon_ses label names the buyer account');

$nUnknown = \App\SendNotice::build('mystery_provider', '', false);
fix1_ok(strpos($nUnknown['provider_label'], 'Unknown provider') === 0,
    'unknown provider key degrades to an explicit unknown label, not a claim');
fix1_ok(strpos($nUnknown['body'], 'no sender address configured') !== false,
    'missing sender address is stated plainly');

$nTrap = \App\SendNotice::build('mailtrap', 'me@example.com', true);
fix1_ok(strpos($nTrap['body'], 'never delivered to real inboxes') !== false,
    'mailtrap notice says it is testing-only, never real delivery');

/* ── No outcome promises anywhere in the notice text ───────────────────── */
foreach (['sendgrid', 'resend', 'amazon_ses', 'brevo', 'smtp'] as $p) {
    $b = \App\SendNotice::build($p, 'me@example.com', true)['body'];
    fix1_ok(!preg_match('/guarantee/i', $b), "notice for {$p}: no 'guarantee'");
    fix1_ok(!preg_match('/high (delivery|deliverability)/i', $b), "notice for {$p}: no 'high delivery' claim");
}

/* ── uiOrder(): API providers first, SMTP paths last ────────────────────── */
$order = \App\SendNotice::uiOrder();
fix1_ok(array_slice($order, 0, 3) === ['sendgrid', 'resend', 'amazon_ses'],
    'ui order starts with sendgrid, resend, amazon_ses');
$firstSmtp = null;
foreach ($order as $i => $p) {
    if (in_array($p, \App\SendNotice::SMTP_PROVIDERS, true)) { $firstSmtp = $i; break; }
}
$lastApi = null;
foreach ($order as $i => $p) {
    if (in_array($p, \App\SendNotice::API_PROVIDERS, true)) { $lastApi = $i; }
}
fix1_ok($firstSmtp !== null && $lastApi !== null && $lastApi < $firstSmtp,
    'every API provider sorts before every SMTP provider');
fix1_ok(count(array_unique($order)) === count($order), 'ui order has no duplicate provider keys');

/* ── 2. Settings UI: provider select order ──────────────────────────────── */
$indexHtml = file_get_contents($repo . '/index.php');
$select = '';
if (preg_match('/<select id="setting-active_email_provider".*?<\/select>/s', $indexHtml, $m)) {
    $select = $m[0];
}
fix1_ok($select !== '', 'provider select exists in index.php');
preg_match_all('/<option value="([^"]+)">([^<]*)<\/option>/', $select, $opts, PREG_SET_ORDER);
$values = array_column($opts, 1);
fix1_ok(count($values) >= 12, 'provider select lists all providers (' . count($values) . ')');
$pos = array_flip($values);
foreach (['sendgrid', 'resend', 'amazon_ses', 'brevo'] as $api) {
    fix1_ok(isset($pos[$api]) && isset($pos['smtp']) && $pos[$api] < $pos['smtp'],
        "UI: {$api} sorts before smtp in the provider selector");
}
fix1_ok(strpos($select, 'Recommended') !== false, 'UI: "Recommended" annotation present');
fix1_ok(strpos($select, 'Advanced — not recommended') !== false,
    'UI: "Advanced — not recommended" optgroup present');
$smtpLabel = '';
foreach ($opts as $o) {
    if ($o[1] === 'smtp') { $smtpLabel = $o[2]; }
}
fix1_ok(strpos($smtpLabel, 'not recommended') !== false,
    'UI: smtp option itself is labeled not recommended');
$sendgridLabel = '';
foreach ($opts as $o) {
    if ($o[1] === 'sendgrid') { $sendgridLabel = $o[2]; }
}
fix1_ok(strpos($sendgridLabel, 'Recommended') !== false, 'UI: sendgrid option carries Recommended');
$sesLabel = '';
foreach ($opts as $o) {
    if ($o[1] === 'amazon_ses') { $sesLabel = $o[2]; }
}
fix1_ok(strpos($sesLabel, 'Recommended') !== false, 'UI: amazon_ses option carries Recommended');

/* ── 3. SMTP demotion copy in the settings UI ───────────────────────────── */
fix1_ok(strpos($indexHtml, 'Advanced — not recommended') !== false,
    'UI: demotion banner text present');
fix1_ok(strpos($indexHtml, 'Shared-hosting IP') !== false &&
        strpos($indexHtml, 'outside our control') !== false,
    'UI: honest shared-host IP reputation copy present');
fix1_ok(strpos($indexHtml, 'This software provides no sending infrastructure') !== false,
    'UI: no-sending-infrastructure disclaimer present under the selector');

/* ── 4. No inbox-placement / deliverability promises in copy ────────────── */
$copyFiles = [
    $repo . '/index.php',
    $repo . '/assets/js/dashboard.js',
    $repo . '/includes/SendNotice.php',
];
foreach (glob($repo . '/docs/*.md') as $d) { $copyFiles[] = $d; }
// Banned: promise/implication patterns. Honest statements (negations like
// "makes no inbox-placement promises", comparatives like "worse
// deliverability", attributions like "deliverability depends on", and advice
// like "protect deliverability") are allowed.
$banned = [
    '/guarantee\w*\s+(inbox|deliver)/i'            => 'guaranteed-delivery phrasing',
    '/high\s+(delivery|deliverability)\s+rates?/i' => 'high-delivery-rate claim',
    '/land in (the |your )?inbox/i'               => 'land-in-the-inbox phrasing',
];
$bad = [];
foreach ($copyFiles as $f) {
    $text = file_get_contents($f);
    foreach ($banned as $re => $what) {
        if (preg_match($re, $text, $gm)) {
            // Negations ("no <x>", "makes no <x> promises") are disclaimers, not promises.
            $before = strtolower(substr($text, 0, strpos($text, $gm[0])));
            if (!preg_match('/\bno\b.{0,12}$/', $before) && !preg_match('/makes no.{0,25}$/', $before)) {
                $bad[] = basename($f) . ': ' . $what;
            }
        }
    }
    // "protects inbox placement" as an outcome claim is banned; the mechanism
    // ("fewer complaints → fewer penalty signals") and the advice form
    // ("protect deliverability") are not.
    if (preg_match_all('/(.{0,20})\bprotects?\b(.{0,40})/i', $text, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $hit) {
            if (preg_match('/inbox\s+placement/i', $hit[2])) {
                $bad[] = basename($f) . ': "protects inbox placement" outcome claim — ' . trim($hit[0]);
            }
        }
    }
}
fix1_ok($bad === [], 'no inbox/deliverability promises in UI, JS, notices, or docs' .
    ($bad ? ' [' . implode(' | ', $bad) . ']' : ''));

/* ── 5. Campaigns API attaches the notice on launch/resume ──────────────── */
$apiSrc = file_get_contents($repo . '/api/campaigns.php');
$noticeAttachments = substr_count($apiSrc, "'send_notice' => campaign_send_notice()")
    + substr_count($apiSrc, "\$resp['send_notice'] = campaign_send_notice();");
fix1_ok($noticeAttachments >= 2,
    'campaigns API attaches send_notice on both toggle-activation and resume');
fix1_ok(strpos($apiSrc, 'function campaign_send_notice()') !== false,
    'campaign_send_notice helper exists in campaigns API');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
