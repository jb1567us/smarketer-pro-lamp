/**
 * Diagnostics tab: fetches api/diagnostics.php, renders pass/warn/fail
 * cards, fills the paste-ready report textarea, and copies it.
 *
 * Loaded on index.php; guarded so it no-ops if the tab markup is absent.
 */
function diagnosticsBadgeClass(status) {
    return status === 'pass'
        ? 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400'
        : status === 'warn'
            ? 'bg-amber-500/10 border-amber-500/30 text-amber-400'
            : 'bg-rose-500/10 border-rose-500/30 text-rose-400';
}

function diagnosticsBadgeLabel(status) {
    return status === 'pass' ? '✓ PASS' : status === 'warn' ? '⚠ NEEDS ATTENTION' : '✕ FAILING';
}

async function loadDiagnostics() {
    const container = document.getElementById('diagnostics-results');
    const reportEl = document.getElementById('diagnostics-report');
    if (!container) return;
    container.innerHTML = '<div class="p-8 text-center text-slate-500">Running checks…</div>';
    if (reportEl) reportEl.value = '';
    try {
        const res = await fetch('api/diagnostics.php');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Diagnostics failed');
        window._diagnosticsReport = data.report || '';
        if (reportEl) reportEl.value = window._diagnosticsReport;

        const counts = { pass: 0, warn: 0, fail: 0 };
        (data.checks || []).forEach(c => { if (counts[c.status] !== undefined) counts[c.status]++; });

        const summary = document.createElement('div');
        summary.className = 'p-4 rounded-xl border border-white/5 bg-slate-900/40 text-sm text-slate-300 mb-2';
        summary.innerHTML =
            '<strong class="text-slate-100">' + counts.pass + ' passing</strong>' +
            ' · <span class="text-amber-400">' + counts.warn + ' need attention</span>' +
            ' · <span class="text-rose-400">' + counts.fail + ' failing</span>' +
            '<span class="text-slate-500"> — app v' + escapeHtml(data.app_version || '?') +
            ', PHP ' + escapeHtml(data.php_version || '?') + ', checked ' + escapeHtml(data.generated_at || '') + '</span>';
        container.innerHTML = '';
        container.appendChild(summary);

        (data.checks || []).forEach(c => {
            const card = document.createElement('div');
            card.className = 'p-5 rounded-2xl border border-white/5 bg-slate-900/40';
            const showFix = c.status !== 'pass' && c.fix && c.fix !== 'No action needed.';
            card.innerHTML =
                '<div class="flex items-center justify-between gap-3 mb-2">' +
                    '<h4 class="font-bold text-slate-100 text-sm">' + escapeHtml(c.title) + '</h4>' +
                    '<span class="px-2.5 py-1 rounded-full text-[10px] font-bold border ' + diagnosticsBadgeClass(c.status) + '">' +
                        diagnosticsBadgeLabel(c.status) + '</span>' +
                '</div>' +
                '<p class="text-slate-400 text-sm leading-relaxed">' + escapeHtml(c.detail) + '</p>' +
                (showFix
                    ? '<p class="mt-3 text-sm leading-relaxed text-slate-300 bg-blue-500/[0.06] border border-blue-500/20 rounded-xl px-4 py-3">' +
                      '<strong class="text-blue-300">How to fix:</strong> ' + escapeHtml(c.fix) + '</p>'
                    : '');
            container.appendChild(card);
        });
    } catch (e) {
        container.innerHTML = '<div class="p-8 text-center text-rose-400">Could not run diagnostics: ' +
            escapeHtml(e.message) + '</div>';
    }
}

function copyDiagnosticsReport() {
    const reportEl = document.getElementById('diagnostics-report');
    const text = (reportEl && reportEl.value) || window._diagnosticsReport || '';
    if (!text) { alert('Run the checks first — there is no report to copy yet.'); return; }
    const done = () => alert('Report copied. Paste it into your forum post.');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(() => fallbackCopyDiagnostics(text, done));
    } else {
        fallbackCopyDiagnostics(text, done);
    }
}

function fallbackCopyDiagnostics(text, done) {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); }
    catch (e) { alert('Copy failed — select the report text manually and copy it.'); }
    document.body.removeChild(ta);
}
