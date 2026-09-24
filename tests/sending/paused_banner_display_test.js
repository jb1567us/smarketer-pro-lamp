/**
 * FIX 4 — paused-banner display tests (node).
 *
 * Usage: node tests/sending/paused_banner_display_test.js
 *
 * Extracts the ITEM-FIX4-PAUSED section from assets/js/blocked_counts.js,
 * evaluates it with a stub escapeHtml, and asserts the campaign card
 * fragment: paused banner present only when is_active is false, auto-pause
 * reason shown honestly, and paused holds are not counted as blocked.
 */
'use strict';

const fs = require('fs');
const path = require('path');

const repo = path.resolve(__dirname, '..', '..');
const src = fs.readFileSync(path.join(repo, 'assets/js/blocked_counts.js'), 'utf8');

const startMarker = '/* ITEM-FIX4-PAUSED-START */';
const endMarker = '/* ITEM-FIX4-PAUSED-END */';
const start = src.indexOf(startMarker);
const end = src.indexOf(endMarker);
if (start === -1 || end === -1 || end <= start) {
    console.error('FAIL: ITEM-FIX4-PAUSED markers not found in blocked_counts.js');
    process.exit(1);
}
const section = src.slice(start + startMarker.length, end);

function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
eval(section + '\n;globalThis.__fix4 = { campaignPausedHtml };');
const { campaignPausedHtml } = globalThis.__fix4;

let passed = 0, failed = 0;
function ok(cond, name) {
    if (cond) { passed++; console.log('  PASS: ' + name); }
    else { failed++; console.log('  FAIL: ' + name); }
}

ok(campaignPausedHtml(null) === '', 'null row renders nothing');
ok(campaignPausedHtml({ is_active: 1, status: 'active' }) === '', 'active campaign renders nothing');
ok(campaignPausedHtml({ is_active: true }) === '', 'truthy is_active renders nothing');

const manual = campaignPausedHtml({ is_active: 0, status: 'active' });
ok(manual.includes('queued sends are held'), 'manual pause shows held-sends line');
ok(manual.includes('reactivate'), 'manual pause mentions reactivation');

const auto = campaignPausedHtml({ is_active: 0, status: 'paused', paused_reason: '7-day window: complaint rate 0.4%' });
ok(auto.includes('Auto-paused'), 'auto-pause labeled as auto-pause');
ok(auto.includes('complaint rate 0.4%'), 'auto-pause reason shown');
ok(auto.includes('queued sends are held'), 'auto-pause shows held-sends line');

const xss = campaignPausedHtml({ is_active: 0, status: 'paused', paused_reason: '<script>alert(1)</script>' });
ok(!xss.includes('<script>'), 'paused reason is HTML-escaped');

ok(!manual.toLowerCase().includes('blocked'), 'paused banner does not use blocked-count language');
ok(!manual.toLowerCase().includes('inbox') && !manual.toLowerCase().includes('guarantee'),
    'paused banner makes no deliverability promises');

console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed > 0 ? 1 : 0);
