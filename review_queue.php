<?php
/**
 * review_queue.php — human review queue for borderline leads.
 *
 * Leads whose weighted ICP fit score landed in the 50-75 band sit in
 * leads.status = 'Needs Review'. A reviewer approves (-> 'Qualified',
 * sequence-eligible) or disqualifies (-> 'Unqualified', never mailed) each
 * lead. Every decision goes through api/review.php (CSRF-protected) and is
 * recorded in review_decisions with who/when, transactionally with the
 * status change.
 *
 * Authentication: \App\Auth::requirePageAuth() (redirects to login when not
 * authenticated). All state changes are fetch() POSTs carrying the
 * X-CSRF-Token header.
 */
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();

$esc = function (mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};

$csrf = \App\Auth::csrfToken();
$dimKeys = \App\Icp\IcpProfile::DIMENSIONS;
$dimLabels = \App\Icp\IcpProfile::DIMENSION_LABELS;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Queue — Smarketer Pro</title>
    <meta name="csrf-token" content="<?= $esc($csrf) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0b0f1a; }
        .dim-bar { height: 6px; border-radius: 9999px; background: #1e293b; overflow: hidden; }
        .dim-bar > div { height: 100%; border-radius: 9999px; }
    </style>
</head>
<body class="bg-[#0b0f1a] text-slate-200 font-sans">
<div class="max-w-6xl mx-auto px-4 py-8">
    <header class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-3xl font-bold bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">🧐 Review Queue</h1>
            <p class="text-slate-400 text-sm mt-1">Borderline leads (fit 50–75) awaiting a human decision. Approve → Qualified · Disqualify → Unqualified. Every decision is audited.</p>
        </div>
        <a href="index.php" class="text-sm text-blue-400 hover:text-blue-300">← Back to dashboard</a>
    </header>

    <div id="alert" class="hidden rounded-xl p-4 mb-6 text-sm"></div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-6">
        <button id="tab-queue" class="px-4 py-2 rounded-lg text-sm font-semibold bg-blue-600 text-white">Needs review <span id="queue-count" class="ml-1 text-xs opacity-80"></span></button>
        <button id="tab-decided" class="px-4 py-2 rounded-lg text-sm font-semibold bg-slate-800 text-slate-300 hover:bg-slate-700">Decided</button>
    </div>

    <!-- Queue -->
    <div id="panel-queue">
        <div class="bg-slate-800 rounded-xl shadow-lg border border-slate-700 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-700">
                        <th class="px-4 py-3 font-semibold">Lead</th>
                        <th class="px-4 py-3 font-semibold w-24">Fit</th>
                        <th class="px-4 py-3 font-semibold">Dimensions (x/10)</th>
                        <th class="px-4 py-3 font-semibold w-52">Decision</th>
                    </tr>
                </thead>
                <tbody id="queue-body">
                    <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Loading…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="flex justify-between items-center mt-4">
            <p id="queue-page-info" class="text-xs text-slate-500"></p>
            <div class="flex gap-2">
                <button id="queue-prev" class="px-3 py-1 rounded bg-slate-800 text-slate-300 text-sm hover:bg-slate-700">← Prev</button>
                <button id="queue-next" class="px-3 py-1 rounded bg-slate-800 text-slate-300 text-sm hover:bg-slate-700">Next →</button>
            </div>
        </div>
    </div>

    <!-- Decided history -->
    <div id="panel-decided" class="hidden">
        <div class="bg-slate-800 rounded-xl shadow-lg border border-slate-700 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-700">
                        <th class="px-4 py-3 font-semibold">Lead</th>
                        <th class="px-4 py-3 font-semibold">Decision</th>
                        <th class="px-4 py-3 font-semibold">Decided by</th>
                        <th class="px-4 py-3 font-semibold">When</th>
                        <th class="px-4 py-3 font-semibold">Fit</th>
                    </tr>
                </thead>
                <tbody id="decided-body">
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const dimKeys = <?= json_encode(array_values($dimKeys)) ?>;
    const dimLabels = <?= json_encode($dimLabels) ?>;
    const dimLabel = (k) => dimLabels[k] || k;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const PAGE_SIZE = 20;
    let queueOffset = 0, queueTotal = 0;

    const alertBox = document.getElementById('alert');
    function showAlert(msg, ok) {
        alertBox.classList.remove('hidden');
        alertBox.className = 'rounded-xl p-4 mb-6 text-sm ' + (ok
            ? 'bg-green-900/40 border border-green-700 text-green-200'
            : 'bg-red-900/40 border border-red-700 text-red-200');
        alertBox.textContent = msg;
    }
    function hideAlert() { alertBox.classList.add('hidden'); }

    async function apiGet(action, params) {
        const q = new URLSearchParams(Object.assign({ action }, params));
        const r = await fetch('api/review.php?' + q.toString(), { credentials: 'same-origin' });
        if (r.status === 401) { window.location = 'login.php?next=' + encodeURIComponent('review_queue.php'); return null; }
        return r.json();
    }

    function dimCell(dims) {
        if (!dims || Object.keys(dims).length === 0) {
            return '<span class="text-slate-500 text-xs">legacy scoring — no breakdown</span>';
        }
        return '<div class="grid grid-cols-2 gap-x-4 gap-y-1 min-w-[220px]">' + dimKeys.map(k => {
            const v = dims[k];
            if (v == null) return '';
            const pct = Math.round(v * 10);
            const color = v >= 7 ? '#22c55e' : (v >= 5 ? '#eab308' : '#ef4444');
            return '<div><div class="flex justify-between text-xs"><span class="text-slate-400">' + esc(dimLabel(k)) +
                '</span><span class="font-semibold text-slate-200">' + v + '/10</span></div>' +
                '<div class="dim-bar"><div style="width:' + pct + '%;background:' + color + '"></div></div></div>';
        }).join('') + '</div>';
    }

    function fitCell(score) {
        score = score || 0;
        const color = score >= 65 ? '#22c55e' : (score >= 55 ? '#eab308' : '#f97316');
        return '<div class="text-lg font-bold" style="color:' + color + '">' + score + '</div>' +
            '<div class="text-xs text-slate-500">/100</div>';
    }

    async function loadQueue() {
        hideAlert();
        const body = document.getElementById('queue-body');
        body.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Loading…</td></tr>';
        const data = await apiGet('list', { filter: 'queue', limit: PAGE_SIZE, offset: queueOffset });
        if (!data) return;
        if (!data.success) { showAlert('Failed to load queue: ' + (data.error || 'unknown error'), false); return; }
        queueTotal = data.total || 0;
        document.getElementById('queue-count').textContent = '(' + queueTotal + ')';
        document.getElementById('queue-page-info').textContent =
            queueTotal === 0 ? 'No leads awaiting review.' :
            ('Showing ' + (queueOffset + 1) + '–' + Math.min(queueOffset + PAGE_SIZE, queueTotal) + ' of ' + queueTotal);
        if (!data.rows || data.rows.length === 0) {
            body.innerHTML = '<tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">Queue is clear — no leads awaiting review. 🎉</td></tr>';
            return;
        }
        body.innerHTML = data.rows.map(row =>
            '<tr class="border-b border-slate-700/60 hover:bg-slate-700/20">' +
            '<td class="px-4 py-3"><div class="font-semibold text-white">' + esc(row.company_name) + '</div>' +
            '<div class="text-xs text-slate-400">' + esc(row.contact_name || '') + (row.email ? ' · ' + esc(row.email) : '') + '</div>' +
            (row.website ? '<div class="text-xs text-slate-500">' + esc(row.website) + '</div>' : '') + '</td>' +
            '<td class="px-4 py-3">' + fitCell(row.lead_score) + '</td>' +
            '<td class="px-4 py-3">' + dimCell(row.dimensions) + '</td>' +
            '<td class="px-4 py-3"><div class="flex gap-2">' +
            '<button data-id="' + row.id + '" data-dec="approve" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-green-600 hover:bg-green-500 text-white">✓ Approve</button>' +
            '<button data-id="' + row.id + '" data-dec="disqualify" class="px-3 py-1.5 rounded-lg text-xs font-bold bg-red-600 hover:bg-red-500 text-white">✕ Disqualify</button>' +
            '</div></td></tr>'
        ).join('');
        body.querySelectorAll('button[data-id]').forEach(btn => {
            btn.addEventListener('click', () => decide(btn.getAttribute('data-id'), btn.getAttribute('data-dec'), btn));
        });
    }

    async function decide(leadId, action, btn) {
        hideAlert();
        const verb = action === 'approve' ? 'approve (→ Qualified)' : 'disqualify (→ Unqualified)';
        if (!confirm('Confirm: ' + verb + ' lead #' + leadId + '?')) return;
        btn.disabled = true;
        try {
            const r = await fetch('api/review.php?action=' + action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ lead_id: parseInt(leadId, 10) })
            });
            const data = await r.json();
            if (data.success) {
                showAlert(data.already_decided
                    ? 'Already recorded — no duplicate written.'
                    : 'Lead #' + leadId + ' ' + (action === 'approve' ? 'approved → Qualified.' : 'disqualified → Unqualified.'), true);
                if (queueOffset >= queueTotal - 1 && queueOffset > 0) queueOffset = Math.max(0, queueOffset - PAGE_SIZE);
                await loadQueue();
            } else {
                showAlert('Decision failed: ' + (data.error || 'unknown error'), false);
                btn.disabled = false;
            }
        } catch (e) {
            showAlert('Decision failed: ' + e.message, false);
            btn.disabled = false;
        }
    }

    async function loadDecided() {
        const body = document.getElementById('decided-body');
        body.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Loading…</td></tr>';
        const data = await apiGet('list', { filter: 'decided', limit: 50, offset: 0 });
        if (!data) return;
        if (!data.success || !data.rows || data.rows.length === 0) {
            body.innerHTML = '<tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No decisions recorded yet.</td></tr>';
            return;
        }
        body.innerHTML = data.rows.map(row => {
            const approved = row.decision === 'approved';
            const badge = approved
                ? '<span class="px-2 py-0.5 rounded-full text-xs font-bold bg-green-900/60 text-green-300 border border-green-700">approved → Qualified</span>'
                : '<span class="px-2 py-0.5 rounded-full text-xs font-bold bg-red-900/60 text-red-300 border border-red-700">disqualified → Unqualified</span>';
            return '<tr class="border-b border-slate-700/60">' +
                '<td class="px-4 py-3"><div class="font-semibold text-white">' + esc(row.company_name) + '</div>' +
                '<div class="text-xs text-slate-400">' + esc(row.contact_name || '') + ' · lead #' + row.lead_id + '</div></td>' +
                '<td class="px-4 py-3">' + badge + '</td>' +
                '<td class="px-4 py-3 text-slate-300">' + esc(row.decided_by) + '</td>' +
                '<td class="px-4 py-3 text-slate-400 text-xs">' + esc(row.decided_at) + '</td>' +
                '<td class="px-4 py-3 text-slate-300">' + (row.fit_score_snapshot != null ? esc(row.fit_score_snapshot) + '/100' : '—') + '</td></tr>';
        }).join('');
    }

    // Tabs
    const tabQueue = document.getElementById('tab-queue');
    const tabDecided = document.getElementById('tab-decided');
    const panelQueue = document.getElementById('panel-queue');
    const panelDecided = document.getElementById('panel-decided');
    const activeCls = ['bg-blue-600', 'text-white'];
    const idleCls = ['bg-slate-800', 'text-slate-300'];
    function setTab(which) {
        const q = which === 'queue';
        panelQueue.classList.toggle('hidden', !q);
        panelDecided.classList.toggle('hidden', q);
        tabQueue.classList.remove(...(q ? idleCls : activeCls)); tabQueue.classList.add(...(q ? activeCls : idleCls));
        tabDecided.classList.remove(...(q ? activeCls : idleCls)); tabDecided.classList.add(...(q ? idleCls : activeCls));
        if (!q) loadDecided();
    }
    tabQueue.addEventListener('click', () => setTab('queue'));
    tabDecided.addEventListener('click', () => setTab('decided'));

    document.getElementById('queue-prev').addEventListener('click', () => {
        queueOffset = Math.max(0, queueOffset - PAGE_SIZE); loadQueue();
    });
    document.getElementById('queue-next').addEventListener('click', () => {
        if (queueOffset + PAGE_SIZE < queueTotal) { queueOffset += PAGE_SIZE; loadQueue(); }
    });

    loadQueue();
})();
</script>
</body>
</html>
