/**
 * ITEM A — blocked-send display tests (node).
 *
 * Usage: node tests/sending/blocked_counts_display_test.js
 *
 * Extracts the ITEM-A-BLOCKED section from assets/js/blocked_counts.js,
 * evaluates it with a stub escapeHtml, and asserts the rendered campaign
 * card fragment: "Blocked: N" indicator, per-reason plain-language copy,
 * correct Diagnostics/Settings links, and honest-copy rules (no promises
 * about lead quality or inbox placement).
 */
'use strict';

const fs = require('fs');
const path = require('path');

const repo = path.resolve(__dirname, '..', '..');
const src = fs.readFileSync(path.join(repo, 'assets/js/blocked_counts.js'), 'utf8');

const startMarker = '/* ITEM-A-BLOCKED-START */';
const endMarker = '/* ITEM-A-BLOCKED-END */';
const start = src.indexOf(startMarker);
const end = src.indexOf(endMarker);
if (start === -1 || end === -1 || end <= start) {
    console.error('FAIL: ITEM-A-BLOCKED markers not found in blocked_counts.js');
    process.exit(1);
}
const section = src.slice(start + startMarker.length, end);

// Stub the one browser global the section uses.
function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
// Evaluate in a scope that hands the definitions back (strict-mode eval
// would otherwise keep const/function declarations to itself).
eval(section + '\n;globalThis.__itemA = { BLOCKED_REASONS, campaignBlockedHtml };');
const { BLOCKED_REASONS, campaignBlockedHtml } = globalThis.__itemA;

let passed = 0, failed = 0;
function ok(cond, name) {
    if (cond) { passed++; console.log('  PASS: ' + name); }
    else { failed++; console.log('  FAIL: ' + name); }
}

/* ── empty states ─────────────────────────────────────────────────── */
ok(campaignBlockedHtml({}) === '', 'renders nothing when the row has no counters');
ok(campaignBlockedHtml(null) === '', 'renders nothing for a null row');
ok(campaignBlockedHtml({ blocked_throttle: 0, blocked_suppression: '0' }) === '',
    'renders nothing when all counters are zero');
ok(campaignBlockedHtml({ blocked_throttle: 'abc' }) === '', 'ignores non-numeric counter values');

/* ── populated row ────────────────────────────────────────────────── */
const row = {
    id: 9,
    blocked_invalid_verification: 12,
    blocked_suppression: 3,
    blocked_compliance_pause: 0,
    blocked_throttle: 5,
    blocked_license_revoked: 1,
    blocked_placeholder: 2,
};
const html = campaignBlockedHtml(row);
ok(html.includes('Blocked: 23'), 'shows the "Blocked: N" total (12+3+5+1+2)');
ok(html.includes('never mailed'), 'states plainly the sends never went out');
ok(!html.includes('compliance rules'), 'zero-count reasons are omitted (compliance_pause was 0)');
ok(!html.includes('blocked_compliance_pause'), 'zero-count reason column never leaks into output');
ok(!html.includes('0</span> blocked'), 'zero-count reasons are omitted from the breakdown');

/* ── per-reason copy ──────────────────────────────────────────────── */
const expectedFragments = [
    ['bad addresses', 'invalid_verification'],
    ['opt-outs, bounces, complaints', 'suppression'],
    ['sending limits hit', 'throttle'],
    ['license revoked', 'license_revoked'],
    ['made-up addresses', 'placeholder'],
];
for (const [frag, key] of expectedFragments) {
    ok(html.includes(frag), `reason copy present: ${key} ("${frag}")`);
}

/* ── links ────────────────────────────────────────────────────────── */
ok(html.includes('index.php?tab=diagnostics'), 'links to the Diagnostics page');
ok(html.includes('index.php?tab=settings'), 'links to the Settings page');
ok(html.includes("showTab('diagnostics')"), 'diagnostics link switches tabs in-page');
ok(html.includes("showTab('settings')"), 'settings link switches tabs in-page');

/* ── honest-copy rules: what we never claim ───────────────────────── */
const banned = ['guarantee', 'inbox placement', 'inbox-placement', 'deliverability', '100%', 'spam-proof'];
for (const word of banned) {
    ok(!html.toLowerCase().includes(word), `copy never promises "${word}"`);
}
// "protect your provider account" is allowed: it describes the guardrail's
// intent, not an outcome. "never mailed" is allowed: it is a fact.
const pauseHtml = campaignBlockedHtml({ blocked_compliance_pause: 2 });
ok(pauseHtml.includes('protect your provider account'), 'compliance copy names the guardrail intent honestly');

/* ── registry sanity ──────────────────────────────────────────────── */
ok(BLOCKED_REASONS.length === 6, 'six reasons in the display registry');
const columns = BLOCKED_REASONS.map((r) => r.column);
ok(new Set(columns).size === 6, 'display columns are unique');
ok(columns.every((c) => c.startsWith('blocked_')), 'display columns match the blocked_* schema');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
