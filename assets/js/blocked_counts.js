/**
 * ITEM A — blocked-send display for campaign cards.
 *
 * Pure rendering logic: takes a campaign row (as returned by
 * api/campaigns.php, which SELECTs the blocked_* counter columns) and
 * returns the "Blocked: N" HTML. No network, no DOM reads — only
 * escapeHtml(), which dashboard.js provides (a stub is enough for tests).
 *
 * Copy rules (deliberate): plain language, no jargon, no promises about
 * lead quality or inbox placement. We sell the workflow, not outcomes —
 * every line says what happened and what the buyer can do, never what the
 * software guarantees.
 */

/* ITEM-A-BLOCKED-START */
const BLOCKED_REASONS = [
    {
        key: 'invalid_verification',
        column: 'blocked_invalid_verification',
        short: 'bad addresses',
        detail: 'These failed email verification, so they were never mailed. Clean your list or adjust verification in Settings.',
        link: { tab: 'settings', label: 'Settings' }
    },
    {
        key: 'suppression',
        column: 'blocked_suppression',
        short: 'opt-outs, bounces, complaints',
        detail: 'Mailing these risks getting your sending account suspended, so they stay blocked. See Diagnostics for details.',
        link: { tab: 'diagnostics', label: 'Diagnostics' }
    },
    {
        key: 'compliance_pause',
        column: 'blocked_compliance_pause',
        short: 'compliance rules',
        detail: 'Paused to protect your provider account — consent or sender-identity rules refused these sends. See Diagnostics.',
        link: { tab: 'diagnostics', label: 'Diagnostics' }
    },
    {
        key: 'throttle',
        column: 'blocked_throttle',
        short: 'sending limits hit',
        detail: 'These sends were held back by your sending limits and retry on their own. See Diagnostics if the queue looks stuck.',
        link: { tab: 'diagnostics', label: 'Diagnostics' }
    },
    {
        key: 'license_revoked',
        column: 'blocked_license_revoked',
        short: 'license revoked',
        detail: 'Sending is paused until the license key is valid again. Check the License section in Settings.',
        link: { tab: 'settings', label: 'Settings' }
    },
    {
        key: 'placeholder',
        column: 'blocked_placeholder',
        short: 'made-up addresses',
        detail: 'Your list contained fake addresses that were never real mailboxes. Fix the source list — no setting changes this.',
        link: null
    }
];

/**
 * Render the "Blocked: N" indicator for one campaign card.
 * Returns '' when nothing was blocked (or the row predates the migration).
 */
function campaignBlockedHtml(c) {
    if (!c || typeof c !== 'object') return '';
    const rows = [];
    let total = 0;
    for (const r of BLOCKED_REASONS) {
        const n = parseInt(c[r.column], 10);
        if (!isNaN(n) && n > 0) {
            total += n;
            rows.push(r);
        }
    }
    if (total === 0) return '';

    const esc = (typeof escapeHtml === 'function') ? escapeHtml : (s) => String(s);
    const reasonLines = rows.map((r) => {
        const n = parseInt(c[r.column], 10);
        const link = r.link
            ? ` <a href="index.php?tab=${r.link.tab}" onclick="showTab('${r.link.tab}');return false;" class="text-blue-400 hover:underline font-semibold">${esc(r.link.label)} →</a>`
            : '';
        return `<div class="text-[11px] leading-snug text-slate-400"><span class="text-rose-300 font-bold">${n}</span> blocked: ${esc(r.short)} — ${esc(r.detail)}${link}</div>`;
    }).join('');

    return `<div class="rounded-xl border border-rose-500/20 bg-rose-500/5 px-3 py-2.5 space-y-1.5">` +
        `<div class="text-xs font-bold text-rose-300">🚫 Blocked: ${total} <span class="font-normal text-slate-500">send${total === 1 ? '' : 's'} never mailed</span></div>` +
        reasonLines +
        `</div>`;
}
/* ITEM-A-BLOCKED-END */

/* ITEM-FIX4-PAUSED-START */
/**
 * Render a "paused" banner for one campaign card.
 *
 * A paused campaign (manual toggle or compliance auto-pause) HOLDS its
 * queued sends: they are not processed, not counted as blocked, and resume
 * automatically on reactivation. Without this line the card would show
 * sends "not going out" with no reason -- the same UX bug ITEM A fixed.
 * Returns '' when the campaign is active.
 */
function campaignPausedHtml(c) {
    if (!c || typeof c !== 'object') return '';
    if (c.is_active) return '';
    const esc = (typeof escapeHtml === 'function') ? escapeHtml : (s) => String(s);
    let line;
    if (c.status === 'paused' && c.paused_reason) {
        line = `Auto-paused: ${esc(c.paused_reason)} &mdash; queued sends are held and will resume when you fix the issue and reactivate this campaign.`;
    } else {
        line = 'Paused &mdash; queued sends are held and will resume when you reactivate this campaign.';
    }
    return `<div class="rounded-xl border border-amber-500/20 bg-amber-500/5 px-3 py-2.5">` +
        `<div class="text-xs font-bold text-amber-300">&#9208; ${line}</div></div>`;
}
/* ITEM-FIX4-PAUSED-END */
