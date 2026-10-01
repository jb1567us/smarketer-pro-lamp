/**
 * Dashboard & API Logic
 */

/* ─────────────────────────────────────────────────────────────
   TOAST NOTIFICATION SYSTEM (replaces alert() everywhere)
───────────────────────────────────────────────────────────── */
function escapeHtml(text) {
    if (!text) return '';
    return text
        .toString()
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function toast(message, type = 'success') {
    const colors = {
        success: 'border-emerald-500 text-emerald-400',
        error:   'border-rose-500 text-rose-400',
        info:    'border-blue-500 text-blue-400',
        warn:    'border-amber-500 text-amber-400'
    };
    const icons = { success: '✅', error: '❌', info: 'ℹ️', warn: '⚠️' };
    const el = document.createElement('div');
    el.className = `fixed bottom-6 right-6 z-[200] glass border-l-4 px-5 py-4 rounded-2xl shadow-2xl flex items-center gap-3 text-sm font-semibold animate-fade-in max-w-sm ${colors[type] || colors.info}`;
    el.innerHTML = `<span>${icons[type] || '📢'}</span><span>${message}</span>`;
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity 0.4s'; setTimeout(() => el.remove(), 400); }, 3500);
}

/* ─────────────────────────────────────────────────────────────
   PAGINATION STATE
───────────────────────────────────────────────────────────── */
window.leadsPage = { offset: 0, limit: 25, total: 0 };

/* ─────────────────────────────────────────────────────────────
   LEAD — Manual Add
───────────────────────────────────────────────────────────── */
function showAddLeadModal() {
    const campaigns = window.allCampaigns || [];
    const options = campaigns.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
    const container = document.getElementById('modal-container');
    container.innerHTML = `
        <div class="glass p-8 rounded-2xl w-full max-w-md">
            <h3 class="text-2xl font-bold mb-6">Add Lead Manually</h3>
            <div class="space-y-4 text-left">
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Company Name <span class="text-rose-400">*</span></label>
                    <input type="text" id="add-company" placeholder="Acme Corp" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Contact Name</label>
                    <input type="text" id="add-contact" placeholder="Jane Smith" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Email Address <span class="text-rose-400">*</span></label>
                    <input type="email" id="add-email" placeholder="jane@acme.com" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Website</label>
                    <input type="url" id="add-website" placeholder="https://acme.com" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Country (ISO 2-letter)</label>
                    <input type="text" id="add-country" maxlength="2" placeholder="e.g. CA, US \u2014 blank = unknown" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition uppercase">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Assign Campaign</label>
                    <select id="add-campaign" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition appearance-none">
                        <option value="">-- No Campaign --</option>
                        ${options}
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Notes</label>
                    <textarea id="add-notes" rows="2" placeholder="Internal notes..." class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition resize-none"></textarea>
                </div>
                <div id="add-lead-error" class="hidden text-xs text-rose-400 font-semibold bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2"></div>
                <div class="flex gap-3 pt-2">
                    <button onclick="submitAddLead()" class="flex-1 bg-blue-600 hover:bg-blue-500 py-2.5 rounded-xl font-bold text-sm transition">Add Lead</button>
                    <button onclick="closeModal()" class="flex-1 bg-slate-800 hover:bg-slate-700 py-2.5 rounded-xl text-sm transition">Cancel</button>
                </div>
            </div>
        </div>`;
    container.classList.remove('hidden');
    document.getElementById('add-company').focus();
}

async function submitAddLead() {
    const company = document.getElementById('add-company').value.trim();
    const email   = document.getElementById('add-email').value.trim();
    const errEl   = document.getElementById('add-lead-error');
    errEl.classList.add('hidden');

    if (!company) { errEl.textContent = 'Company name is required.'; errEl.classList.remove('hidden'); return; }
    if (!email)   { errEl.textContent = 'Email address is required.'; errEl.classList.remove('hidden'); return; }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { errEl.textContent = 'Please enter a valid email address.'; errEl.classList.remove('hidden'); return; }

    try {
        const res = await fetch('api/leads.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                company_name: company,
                contact_name: document.getElementById('add-contact').value.trim(),
                email,
                website:     document.getElementById('add-website').value.trim(),
                country_code: (document.getElementById('add-country').value.trim().toUpperCase() || null),
                campaign_id: document.getElementById('add-campaign').value || null,
                notes:       document.getElementById('add-notes').value.trim(),
                source:      'Manual'
            })
        });
        const result = await res.json();
        if (result.success) {
            closeModal();
            toast('Lead added successfully!');
            fetchLeads();
            fetchStats();
        } else {
            errEl.textContent = result.error || 'Failed to create lead.';
            errEl.classList.remove('hidden');
        }
    } catch (e) {
        errEl.textContent = 'Network error — please try again.';
        errEl.classList.remove('hidden');
    }
}

/* ─────────────────────────────────────────────────────────────
   LEAD — Status Update
───────────────────────────────────────────────────────────── */
async function updateLeadStatus(id, status) {
    try {
        const res = await fetch('api/leads.php?action=update_status', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, status })
        });
        const result = await res.json();
        if (result.success) {
            toast(`Status updated to ${status}`);
            fetchLeads();
            fetchStats();
        } else {
            toast(result.error || 'Failed to update status', 'error');
        }
    } catch (e) {
        toast('Network error', 'error');
    }
}

/* ─────────────────────────────────────────────────────────────
   CAMPAIGN — Edit & Delete
───────────────────────────────────────────────────────────── */
function editCampaign(id) {
    const c = (window.allCampaigns || []).find(x => x.id == id);
    if (!c) return;
    const container = document.getElementById('modal-container');
    container.innerHTML = `
        <div class="glass p-8 rounded-2xl w-full max-w-md">
            <h3 class="text-2xl font-bold mb-6">Edit Campaign</h3>
            <div class="space-y-4 text-left">
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Campaign Name <span class="text-rose-400">*</span></label>
                    <input type="text" id="edit-camp-name" value="${escapeHtml(c.name)}" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Description</label>
                    <textarea id="edit-camp-desc" rows="3" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none resize-none transition">${escapeHtml(c.description || '')}</textarea>
                </div>
                <div id="edit-camp-error" class="hidden text-xs text-rose-400 font-semibold bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2"></div>
                <div class="flex gap-3 pt-2">
                    <button onclick="saveCampaignEdit(${id})" class="flex-1 bg-blue-600 hover:bg-blue-500 py-2.5 rounded-xl font-bold text-sm transition">Save Changes</button>
                    <button onclick="closeModal()" class="flex-1 bg-slate-800 hover:bg-slate-700 py-2.5 rounded-xl text-sm transition">Cancel</button>
                </div>
            </div>
        </div>`;
    container.classList.remove('hidden');
    document.getElementById('edit-camp-name').focus();
}

async function saveCampaignEdit(id) {
    const name = document.getElementById('edit-camp-name').value.trim();
    const errEl = document.getElementById('edit-camp-error');
    errEl.classList.add('hidden');
    if (!name) { errEl.textContent = 'Campaign name is required.'; errEl.classList.remove('hidden'); return; }
    try {
        const res = await fetch('api/campaigns.php?type=campaigns&action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, name, description: document.getElementById('edit-camp-desc').value.trim() })
        });
        const result = await res.json();
        if (result.success) { closeModal(); toast('Campaign updated!'); fetchCampaigns(); fetchCampaignsList(); }
        else { errEl.textContent = result.error || 'Update failed'; errEl.classList.remove('hidden'); }
    } catch (e) { toast('Network error', 'error'); }
}

async function deleteCampaign(id, name) {
    if (!confirm(`Delete campaign "${name}"? This cannot be undone.`)) return;
    try {
        const res = await fetch('api/campaigns.php?type=campaigns&action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await res.json();
        if (result.success) { toast('Campaign deleted'); fetchCampaigns(); fetchCampaignsList(); }
        else toast(result.error || 'Delete failed', 'error');
    } catch (e) { toast('Network error', 'error'); }
}

async function toggleCampaignActive(id, overrideDnsPreflight = false) {
    try {
        const res = await fetch('api/campaigns.php?type=campaigns&action=toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, override_dns_preflight: overrideDnsPreflight })
        });
        const result = await res.json();
        const pf = result.dns_preflight || null;
        if (result.success) {
            toast(result.is_active ? 'Campaign activated' : 'Campaign paused', 'info');
            // Surface a DNS preflight warning prominently (allowed but degraded).
            if (pf && pf.summary === 'warn' && Array.isArray(pf.missing) && pf.missing.length) {
                alert('DNS preflight warning for ' + (pf.domain || 'sender domain') + ':\n- ' +
                    pf.missing.join('\n- ') +
                    '\n\nThe campaign was started, but fix these DNS records to protect deliverability.');
            }
            fetchCampaigns();
        } else if (res.status === 422 && pf && pf.summary === 'fail') {
            // Fully unauthenticated sender domain: the API blocked the start.
            // Offer the explicit per-campaign override (deliberate acknowledgment).
            const msg = (result.error || 'DNS preflight failed') +
                (Array.isArray(pf.missing) && pf.missing.length ? '\n\nMissing:\n- ' + pf.missing.join('\n- ') : '');
            if (confirm(msg + '\n\nStart the campaign anyway and remember this override for the campaign?')) {
                return toggleCampaignActive(id, true);
            }
        } else {
            toast(result.error || 'Toggle failed', 'error');
        }
    } catch (e) { toast('Network error', 'error'); }
}

/* ─────────────────────────────────────────────────────────────
   TEMPLATE — Edit & Delete
───────────────────────────────────────────────────────────── */
function editTemplate(id, campaignId) {
    const t = (window._tplCache || {})[id];
    if (!t) { toast('Step not found', 'error'); return; }
    const subject = t.subject, body = t.body, stepOrder = t.step_order;
    const container = document.getElementById('modal-container');
    container.innerHTML = `
        <div class="glass p-8 rounded-2xl w-full max-w-2xl text-left">
            <h3 class="text-2xl font-bold mb-6">Edit Step ${stepOrder}</h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Subject <span class="text-rose-400">*</span></label>
                    <input type="text" id="edit-tpl-subject" value="${escapeHtml(subject)}" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Body</label>
                    <textarea id="edit-tpl-body" rows="8" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none resize-none transition">${escapeHtml(body)}</textarea>
                </div>
                <div id="edit-tpl-error" class="hidden text-xs text-rose-400 font-semibold bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2"></div>
                <div class="flex gap-3">
                    <button onclick="saveTemplateEdit(${id}, ${stepOrder}, ${campaignId})" class="flex-1 bg-blue-600 hover:bg-blue-500 py-2.5 rounded-xl font-bold text-sm transition">Save Step</button>
                    <button onclick="viewTemplates(${campaignId})" class="flex-1 bg-slate-800 hover:bg-slate-700 py-2.5 rounded-xl text-sm transition">Back</button>
                </div>
            </div>
        </div>`;
    container.classList.remove('hidden');
}

async function saveTemplateEdit(id, stepOrder, campaignId) {
    const subject = document.getElementById('edit-tpl-subject').value.trim();
    const body    = document.getElementById('edit-tpl-body').value.trim();
    const errEl   = document.getElementById('edit-tpl-error');
    errEl.classList.add('hidden');
    if (!subject) { errEl.textContent = 'Subject is required.'; errEl.classList.remove('hidden'); return; }
    try {
        const res = await fetch('api/campaigns.php?type=templates&action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, subject, body, step_order: stepOrder })
        });
        const result = await res.json();
        if (result.success) { toast('Step updated!'); viewTemplates(campaignId); }
        else { errEl.textContent = result.error || 'Update failed'; errEl.classList.remove('hidden'); }
    } catch (e) { toast('Network error', 'error'); }
}

async function deleteTemplate(id, campaignId) {
    if (!confirm('Remove this sequence step?')) return;
    try {
        const res = await fetch('api/campaigns.php?type=templates&action=delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await res.json();
        if (result.success) { toast('Step deleted'); viewTemplates(campaignId); }
        else toast(result.error || 'Delete failed', 'error');
    } catch (e) { toast('Network error', 'error'); }
}

document.addEventListener('DOMContentLoaded', () => {
    fetchLeads();
    fetchStats();
    fetchCampaignsList();
    runSupervisorCheck();
    // Refresh stats and leads periodically
    setInterval(() => {
        fetchLeads();
        fetchStats();
        runSupervisorCheck();
    }, 10000);
});

async function fetchCampaignsList() {
    try {
        const response = await fetch('api/campaigns.php?type=campaigns');
        const result = await response.json();
        if (result.success) {
            window.allCampaigns = result.data;
        }
    } catch (e) {
        console.warn('Failed to load campaigns list');
    }
}

async function runSupervisorCheck() {
    const statusEl = document.getElementById('supervisor-status');
    const checkEl = document.getElementById('supervisor-last-check');
    if (!statusEl) return;

    try {
        const response = await fetch('api/system_audit.php');
        const data = await response.json();

        if (data.database === "Healthy" && (!data.proxy || data.proxy.includes("Connected")) && (!data.storage || data.storage.includes("Healthy"))) {
            statusEl.innerText = "Healthy";
            statusEl.className = "text-xl font-bold text-emerald-400";
        } else {
            statusEl.innerText = "Warning";
            statusEl.className = "text-xl font-bold text-amber-400";
        }
        
        let details = `Last check: ${data.timestamp}`;
        if (data.proxy) details += ` | Proxy: ${data.proxy}`;
        if (data.storage) details += ` | Storage: ${data.storage}`;
        checkEl.innerText = details;
    } catch (e) {
        statusEl.innerText = "Critical";
        statusEl.className = "text-xl font-bold text-rose-500";
        checkEl.innerText = "Supervisor API unreachable";
    }
}

async function runTask(leadId, type, btn) {
    // Phase 0 fix (was critical defect C3): this function was dead code —
    // nothing called it. It now backs the per-row Enrich/Qualify buttons and
    // maps to task types the queue processor actually handles.
    const originalHtml = btn ? btn.innerHTML : null;
    if (btn) { btn.innerHTML = '⌛'; btn.disabled = true; }
    try {
        const response = await fetch('api/trigger_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, task_type: type })
        });
        const result = await response.json();
        if (result.success) {
            toast(`Task ${type} done: ${result.message}`, 'success');
            fetchLeads();
        } else {
            toast('Task failed: ' + result.error, 'error');
        }
    } catch (e) {
        toast('Failed to trigger task', 'error');
    } finally {
        if (btn) { btn.innerHTML = originalHtml; btn.disabled = false; }
    }
}

async function viewLead(id) {
    const drawer = document.getElementById('drawer-container');
    if (!drawer) return;

    // Find lead details from cached list
    const lead = window.allLeads ? window.allLeads.find(l => l.id == id) : null;
    if (!lead) return;

    drawer.innerHTML = `
        <div class="p-6 border-b border-white/10 flex justify-between items-center bg-slate-950/20">
            <div>
                <h3 class="text-xl font-bold text-white">${escapeHtml(lead.company_name)}</h3>
                <p class="text-xs text-slate-400 mt-1">${lead.website ? `<a href="${escapeHtml(lead.website)}" target="_blank" class="hover:underline text-blue-400">🌐 ${escapeHtml(lead.website)}</a>` : 'No website listed'}</p>
            </div>
            <button onclick="closeDrawer()" class="text-slate-400 hover:text-white text-xl p-2">✕</button>
        </div>
        <div class="p-6 border-b border-white/5 bg-slate-900/50 flex gap-4 text-xs font-semibold text-slate-400">
            <div>Score: <span class="${getScoreColor(lead.lead_score)}">${lead.lead_score}</span></div>
            <div class="h-4 w-px bg-white/10"></div>
            <div>Status: <span class="text-white">${lead.status}</span></div>
            <div class="h-4 w-px bg-white/10"></div>
            <div>Email: <span class="text-white">${escapeHtml(lead.email || 'N/A')}</span></div>
        </div>
        <div class="flex-1 overflow-y-auto p-6 space-y-6">
            <div id="drawer-drafts-container">
                <div class="text-center py-8">
                    <span class="text-2xl animate-spin inline-block mb-3">⌛</span>
                    <p class="text-slate-400 text-sm">Loading drafts...</p>
                </div>
            </div>
            <div id="drawer-traces-container">
                <div class="text-center py-12">
                    <span class="text-2xl animate-spin inline-block mb-3">⌛</span>
                    <p class="text-slate-400 text-sm">Loading AI Explainability Traces...</p>
                </div>
            </div>
        </div>
    `;

    loadLeadDrafts(id);

    // Slide in the drawer
    drawer.classList.remove('hidden');
    // Force reflow
    drawer.offsetHeight;
    drawer.style.transform = 'translateX(0)';

    try {
        const response = await fetch(`api/leads.php?action=traces&id=${id}`);
        const result = await response.json();

        const tracesContainer = document.getElementById('drawer-traces-container');
        if (result.success && result.data && result.data.length > 0) {
            tracesContainer.innerHTML = `
                <div class="flex items-center gap-2 mb-4">
                    <span class="text-blue-400">🧬</span>
                    <h4 class="text-xs uppercase font-bold tracking-wider text-slate-400">AI Decision History</h4>
                </div>
                <div class="space-y-4">
                    ${result.data.map(trace => `
                        <div class="glass p-5 rounded-2xl border border-white/5 space-y-3 relative overflow-hidden">
                            <div class="absolute top-0 left-0 w-1 h-full bg-blue-500"></div>
                            <div class="flex justify-between items-start">
                                <div>
                                    <span class="text-xs font-bold text-blue-400 bg-blue-500/10 border border-blue-500/20 px-2 py-0.5 rounded">${escapeHtml(trace.persona)}</span>
                                </div>
                                <span class="text-[10px] text-slate-500 font-mono">${escapeHtml(trace.created_at)}</span>
                            </div>
                            <div>
                                <div class="text-[10px] uppercase font-bold text-slate-500 mb-0.5">Goal</div>
                                <div class="text-xs font-medium text-slate-300">${escapeHtml(trace.goal)}</div>
                            </div>
                            ${trace.reasoning_output ? `
                                <div>
                                    <div class="text-[10px] uppercase font-bold text-slate-500 mb-1">Reasoning Trace</div>
                                    <pre class="bg-slate-950/40 p-3 rounded-lg text-[11px] font-mono text-slate-400 whitespace-pre-wrap max-h-48 overflow-y-auto leading-relaxed border border-white/5">${escapeHtml(trace.reasoning_output)}</pre>
                                </div>
                            ` : ''}
                        </div>
                    `).join('')}
                </div>
            `;
        } else {
            tracesContainer.innerHTML = `
                <div class="text-center py-12 px-4 space-y-4">
                    <div class="text-4xl">🔍</div>
                    <div class="text-sm font-bold text-white">No Explainability Traces Found</div>
                    <p class="text-xs text-slate-500 max-w-xs mx-auto leading-relaxed">No automated agent steps have run for this prospect yet. Start the AI reasoning engine to analyze intent and capture key insights.</p>
                    <button onclick="analyzeLead(${lead.id}, this); closeDrawer();" class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition shadow-lg shadow-blue-600/20">🧬 Analyze Intent Now</button>
                </div>
            `;
        }
    } catch (e) {
        document.getElementById('drawer-traces-container').innerHTML = `
            <div class="text-center py-12 text-rose-400 text-sm">
                ⚠️ Failed to load decision traces.
            </div>
        `;
    }
}

async function loadLeadDrafts(id) {
    const container = document.getElementById('drawer-drafts-container');
    if (!container) return;
    const badge = (status) => {
        const map = {
            approved: 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20',
            needs_human: 'text-rose-400 bg-rose-500/10 border-rose-500/20',
            pending_review: 'text-amber-400 bg-amber-500/10 border-amber-500/20',
        };
        const cls = map[status] || 'text-slate-400 bg-slate-500/10 border-slate-500/20';
        const label = (status || 'unknown').replace(/_/g, ' ');
        return `<span class="text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded border ${cls}">${escapeHtml(label)}</span>`;
    };
    try {
        const response = await fetch(`api/leads.php?action=drafts&id=${id}`);
        const result = await response.json();
        if (result.success && result.data && result.data.length > 0) {
            container.innerHTML = `
                <div class="flex items-center gap-2 mb-4">
                    <span class="text-emerald-400">✉️</span>
                    <h4 class="text-xs uppercase font-bold tracking-wider text-slate-400">Outreach Drafts (${result.data.length})</h4>
                </div>
                <div class="space-y-4">
                    ${result.data.map(d => `
                        <div class="glass p-5 rounded-2xl border border-white/5 space-y-3 relative overflow-hidden">
                            <div class="absolute top-0 left-0 w-1 h-full ${d.status === 'approved' ? 'bg-emerald-500' : d.status === 'needs_human' ? 'bg-rose-500' : 'bg-amber-500'}"></div>
                            <div class="flex justify-between items-start gap-2">
                                <div class="text-sm font-bold text-white">${escapeHtml(d.subject || '(no subject)')}</div>
                                ${badge(d.status)}
                            </div>
                            <div class="text-[10px] text-slate-500 font-mono">v${d.attempts || 1} · ${escapeHtml(d.campaign_name || '')} · ${escapeHtml(d.created_at || '')}</div>
                            <pre class="bg-slate-950/40 p-3 rounded-lg text-[11px] text-slate-300 whitespace-pre-wrap max-h-48 overflow-y-auto leading-relaxed border border-white/5">${escapeHtml(d.body || '')}</pre>
                            ${d.reviewer_notes ? `
                                <div>
                                    <div class="text-[10px] uppercase font-bold text-slate-500 mb-1">Reviewer notes</div>
                                    <div class="text-[11px] text-slate-400 leading-relaxed">${escapeHtml(d.reviewer_notes)}</div>
                                </div>
                            ` : ''}
                        </div>
                    `).join('')}
                </div>
            `;
        } else {
            container.innerHTML = `
                <div class="text-center py-8 px-4">
                    <div class="text-3xl mb-2">✉️</div>
                    <p class="text-xs text-slate-500">No drafts yet for this lead.</p>
                </div>
            `;
        }
    } catch (e) {
        container.innerHTML = `
            <div class="text-center py-8 text-rose-400 text-sm">
                ⚠️ Failed to load drafts.
            </div>
        `;
    }
}

function closeDrawer() {
    const drawer = document.getElementById('drawer-container');
    if (!drawer) return;
    drawer.style.transform = 'translateX(100%)';
    setTimeout(() => {
        drawer.classList.add('hidden');
    }, 300);
}

function showNewCampaignModal() {
    const container = document.getElementById('modal-container');
    container.innerHTML = `
        <div class="glass p-8 rounded-2xl w-full max-w-md">
            <h3 class="text-2xl font-bold mb-4">Create Campaign</h3>
            <input type="text" id="new-campaign-name" placeholder="Campaign Name" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 mb-4">
            <textarea id="new-campaign-desc" placeholder="Description" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 mb-4"></textarea>
            <div class="flex gap-4">
                <button onclick="saveCampaign()" class="flex-1 bg-blue-600 py-2 rounded-lg">Create</button>
                <button onclick="closeModal()" class="flex-1 bg-slate-700 py-2 rounded-lg">Cancel</button>
            </div>
        </div>
    `;
    container.classList.remove('hidden');
}

function closeModal() {
    document.getElementById('modal-container').classList.add('hidden');
}

async function saveCampaign() {
    const name = document.getElementById('new-campaign-name').value.trim();
    const desc = document.getElementById('new-campaign-desc').value.trim();
    if (!name) { toast('Campaign name is required', 'warn'); return; }
    try {
        const res = await fetch('api/campaigns.php?type=campaigns', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name, description: desc })
        });
        const result = await res.json();
        if (result.success) { closeModal(); toast('Campaign created!'); fetchCampaigns(); fetchCampaignsList(); }
        else toast(result.error || 'Failed to save campaign', 'error');
    } catch (e) {
        toast('Network error', 'error');
    }
}

async function fetchLeads() {
    try {
        const p = window.leadsPage;
        const search = document.getElementById('lead-search')?.value.trim() || '';
        let url = `api/leads.php?limit=${p.limit}&offset=${p.offset}`;
        if (search) url += `&search=${encodeURIComponent(search)}`;
        const response = await fetch(url);
        const result = await response.json();
        if (result.success) {
            window.leadsPage.total = result.meta.total;
            updateLeadsTable(result.data, result.meta);
        }
    } catch (error) {
        console.error('Failed to fetch leads:', error);
    }
}

function updateLeadsTable(leads, meta) {
    const tbody = document.getElementById('leads-body');
    if (!tbody) return;
    window.allLeads = leads;

    // Bulk toolbar (inject above table if not present)
    let toolbar = document.getElementById('bulk-toolbar');
    if (!toolbar) {
        const tableWrap = tbody.closest('.overflow-x-auto') || tbody.closest('div');
        if (tableWrap) {
            toolbar = document.createElement('div');
            toolbar.id = 'bulk-toolbar';
            toolbar.className = 'hidden flex items-center gap-3 px-6 py-3 bg-blue-600/10 border border-blue-500/20 rounded-xl mb-3';
            toolbar.innerHTML = `
                <span id="bulk-count" class="text-xs font-bold text-blue-400">0 selected</span>
                <div class="flex-1"></div>
                <select id="bulk-status-select" class="bg-slate-900 border border-white/10 rounded-lg px-3 py-1.5 text-xs text-white outline-none appearance-none">
                    <option value="">Change Status...</option>
                    <option value="New">New</option>
                    <option value="Enriched">Enriched</option>
                    <option value="Contacted">Contacted</option>
                    <option value="Qualified">Qualified</option>
                    <option value="Unqualified">Unqualified</option>
                    <option value="Converted">Converted</option>
                </select>
                <button onclick="bulkStatusChange()" class="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-xs font-bold transition">Apply Status</button>
                <button onclick="bulkPipeline()" class="px-3 py-1.5 rounded-lg bg-violet-600/20 hover:bg-violet-600/40 text-violet-300 text-xs font-bold border border-violet-500/20 transition" title="Queue Enrich → Qualify → Draft for selected leads (runs via cron, in order)">⚡ Pipeline</button>
                <button onclick="bulkDelete()" class="px-3 py-1.5 rounded-lg bg-rose-600/20 hover:bg-rose-600/40 text-rose-400 text-xs font-bold border border-rose-500/20 transition">🗑️ Delete</button>
                <button onclick="clearBulkSelection()" class="px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-slate-400 text-xs font-bold transition">✕ Clear</button>`;
            tableWrap.insertBefore(toolbar, tableWrap.firstChild);
        }
    }

    if (leads.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="px-6 py-10 text-center text-slate-500 italic">No leads found.</td></tr>';
        updatePaginationControls(meta);
        return;
    }
    renderTableRows(leads);
    updatePaginationControls(meta);
}

function renderTableRows(leads) {
    const tbody = document.getElementById('leads-body');
    tbody.innerHTML = '';

    // Update select-all checkbox header state
    const selectAll = document.getElementById('leads-select-all');
    if (selectAll) selectAll.checked = false;

    leads.forEach(lead => {
        const tr = document.createElement('tr');
        tr.className = 'group hover:bg-white/[0.02] transition-colors lead-row';
        tr.dataset.id = lead.id;
        tr.innerHTML = `
            <td class="px-4 py-5">
                <input type="checkbox" class="lead-checkbox w-4 h-4 rounded accent-blue-500 cursor-pointer"
                    data-id="${lead.id}" onchange="onLeadCheckbox()">
            </td>
            <td class="px-6 py-5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center font-bold text-slate-400 border border-white/5 group-hover:border-blue-500/50 transition">
                        ${escapeHtml(lead.company_name.charAt(0))}
                    </div>
                    <div>
                        <div onclick="viewLead(${lead.id})" class="font-bold text-white group-hover:text-blue-400 cursor-pointer transition">${escapeHtml(lead.company_name)}</div>
                        <div class="text-[11px] text-slate-500 font-medium">${escapeHtml(lead.contact_name || 'No Key Contact')}</div>
                        ${lead.campaign_name
                            ? `<div class="text-[9px] text-blue-400 font-bold bg-blue-500/10 border border-blue-500/20 px-1.5 py-0.5 rounded w-fit mt-1">🎯 ${escapeHtml(lead.campaign_name)}</div>`
                            : `<div class="text-[9px] text-slate-500 italic mt-1">No Campaign</div>`}
                    </div>
                </div>
            </td>
            <td class="px-6 py-5">
                <select onchange="updateLeadStatus(${lead.id}, this.value)"
                    class="bg-transparent border border-white/10 rounded-lg px-2 py-1 text-xs font-semibold outline-none cursor-pointer transition hover:border-white/20 ${getStatusClass(lead.status)}"
                    title="Click to change status">
                    ${['New','Enriched','Contacted','Qualified','Unqualified','Converted','Drafted'].map(s =>
                        `<option value="${s}" ${lead.status === s ? 'selected' : ''} class="bg-slate-900 text-white">${s}</option>`
                    ).join('')}
                </select>
            </td>
            <td class="px-6 py-5">
                <div class="flex items-center gap-2">
                    <div class="flex-1 h-1.5 bg-slate-800 rounded-full overflow-hidden w-16">
                        <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-500" style="width: ${Math.min(lead.lead_score, 100)}%"></div>
                    </div>
                    <span class="font-bold text-sm ${getScoreColor(lead.lead_score)}">${lead.lead_score}</span>
                </div>
            </td>
            <td class="px-6 py-5">
                <div class="flex gap-2">
                    <button onclick="analyzeLead(${lead.id}, this)" class="p-2 rounded-lg bg-white/5 hover:bg-blue-600/20 text-slate-400 hover:text-blue-400 transition" title="Analyze Intent">🧬</button>
                    <button onclick="runTask(${lead.id}, 'Enrich', this)" class="p-2 rounded-lg bg-white/5 hover:bg-amber-600/20 text-slate-400 hover:text-amber-400 transition" title="Enrich Lead (research)">🔍</button>
                    <button onclick="runTask(${lead.id}, 'Qualify', this)" class="p-2 rounded-lg bg-white/5 hover:bg-violet-600/20 text-slate-400 hover:text-violet-400 transition" title="Qualify Lead (ICP fit)">✅</button>
                    <button onclick="draftLead(${lead.id}, this)" class="p-2 rounded-lg bg-white/5 hover:bg-emerald-600/20 text-slate-400 hover:text-emerald-400 transition" title="Draft Email">✉️</button>
                </div>
            </td>
            <td class="px-6 py-5">
                <div class="flex gap-2">
                    <button onclick="editLead(${lead.id})" class="p-2 rounded-lg hover:bg-white/10 transition" title="Edit">⚙️</button>
                    <button onclick="deleteLead(${lead.id})" class="p-2 rounded-lg hover:bg-rose-500/10 text-rose-500/50 hover:text-rose-500 transition" title="Delete">🗑️</button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
}

/* ── Bulk Selection Helpers ── */
function onLeadCheckbox() {
    const checked = document.querySelectorAll('.lead-checkbox:checked');
    const toolbar  = document.getElementById('bulk-toolbar');
    const countEl  = document.getElementById('bulk-count');
    if (toolbar) toolbar.classList.toggle('hidden', checked.length === 0);
    if (countEl)  countEl.textContent = `${checked.length} selected`;
    const selectAll = document.getElementById('leads-select-all');
    if (selectAll) {
        const all = document.querySelectorAll('.lead-checkbox');
        selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
        selectAll.checked = checked.length === all.length;
    }
}

function toggleSelectAll(cb) {
    document.querySelectorAll('.lead-checkbox').forEach(el => { el.checked = cb.checked; });
    onLeadCheckbox();
}

function clearBulkSelection() {
    document.querySelectorAll('.lead-checkbox').forEach(el => el.checked = false);
    onLeadCheckbox();
}

function getSelectedIds() {
    return [...document.querySelectorAll('.lead-checkbox:checked')].map(el => parseInt(el.dataset.id));
}

async function bulkPipeline() {
    // Phase 0: one-click Enrich → Qualify → Draft for every selected lead.
    // Queued (deferred) with staggered scheduled_at so the cron worker
    // processes each lead's steps in order. No emails are sent.
    const ids = getSelectedIds();
    if (!ids.length) { toast('No leads selected', 'warn'); return; }
    if (!confirm(`Queue Enrich → Qualify → Draft for ${ids.length} lead(s)? Tasks run via the cron worker in order. No emails will be sent.`)) return;
    try {
        const res = await fetch('api/trigger_task.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_ids: ids, task_type: 'Pipeline' })
        });
        const r = await res.json();
        if (r.success) {
            toast(`Queued ${r.queued} tasks for ${ids.length} lead(s)`, 'success');
            clearBulkSelection();
            fetchLeads();
        } else {
            toast('Pipeline failed: ' + (r.error || 'unknown error'), 'error');
        }
    } catch (e) {
        toast('Failed to queue pipeline', 'error');
    }
}

async function bulkDelete() {
    const ids = getSelectedIds();
    if (!ids.length) return;
    if (!confirm(`Delete ${ids.length} lead(s)? This cannot be undone.`)) return;
    let ok = 0;
    for (const id of ids) {
        try {
            const res = await fetch(`api/leads.php?action=delete&id=${id}`, { method: 'POST' });
            const r = await res.json();
            if (r.success) ok++;
        } catch (e) {}
    }
    toast(`Deleted ${ok} of ${ids.length} leads`, ok === ids.length ? 'success' : 'warn');
    clearBulkSelection();
    fetchLeads();
    fetchStats();
}

async function bulkStatusChange() {
    const ids    = getSelectedIds();
    const status = document.getElementById('bulk-status-select')?.value;
    if (!ids.length) { toast('No leads selected', 'warn'); return; }
    if (!status)     { toast('Choose a status first', 'warn'); return; }
    let ok = 0;
    for (const id of ids) {
        try {
            const res = await fetch('api/leads.php?action=update_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, status })
            });
            const r = await res.json();
            if (r.success) ok++;
        } catch (e) {}
    }
    toast(`Updated ${ok} of ${ids.length} leads to "${status}"`, ok === ids.length ? 'success' : 'warn');
    clearBulkSelection();
    fetchLeads();
    fetchStats();
}

/* ── Pagination ── */
function updatePaginationControls(meta) {
    let pager = document.getElementById('leads-pager');
    if (!pager) {
        const tableWrap = document.getElementById('leads-body')?.closest('div');
        if (!tableWrap) return;
        pager = document.createElement('div');
        pager.id = 'leads-pager';
        tableWrap.parentNode.insertBefore(pager, tableWrap.nextSibling);
    }
    if (!meta) { pager.innerHTML = ''; return; }
    const { offset, limit, total } = meta;
    const page    = Math.floor(offset / limit) + 1;
    const pages   = Math.ceil(total / limit) || 1;
    const hasPrev = offset > 0;
    const hasNext = (offset + limit) < total;
    pager.className = 'flex items-center justify-between mt-4 px-2';
    pager.innerHTML = `
        <span class="text-xs text-slate-500">
            Showing ${offset + 1}–${Math.min(offset + limit, total)} of <strong class="text-slate-300">${total}</strong> leads
        </span>
        <div class="flex items-center gap-2">
            <button onclick="leadsGoPage(${page - 1})" ${!hasPrev ? 'disabled' : ''}
                class="px-3 py-1.5 rounded-lg text-xs font-bold border border-white/10 ${hasPrev ? 'hover:bg-white/10 text-slate-300' : 'text-slate-600 cursor-not-allowed'} transition">← Prev</button>
            <span class="text-xs font-semibold text-slate-400">Page ${page} / ${pages}</span>
            <button onclick="leadsGoPage(${page + 1})" ${!hasNext ? 'disabled' : ''}
                class="px-3 py-1.5 rounded-lg text-xs font-bold border border-white/10 ${hasNext ? 'hover:bg-white/10 text-slate-300' : 'text-slate-600 cursor-not-allowed'} transition">Next →</button>
        </div>`;
}

function leadsGoPage(page) {
    const p = window.leadsPage;
    const newOffset = Math.max(0, (page - 1) * p.limit);
    window.leadsPage.offset = newOffset;
    fetchLeads();
}

function getScoreColor(score) {
    if (score >= 80) return 'text-emerald-400';
    if (score >= 50) return 'text-blue-400';
    if (score >= 30) return 'text-amber-400';
    return 'text-slate-500';
}

async function analyzeLead(id, btn) {
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '⌛';
    btn.disabled = true;
    try {
        const res = await fetch(`api/leads.php?action=analyze&id=${id}`, { method: 'POST' });
        const result = await res.json();
        if (result.success) {
            toast(result.email_found ? `Email extracted: ${result.email_found}` : 'Intent analyzed — no new email found');
            fetchLeads();
            fetchStats();
        } else {
            toast('Analysis failed: ' + (result.error || 'Unknown error'), 'error');
        }
    } catch (e) {
        toast('Failed to connect to analysis agent', 'error');
    } finally {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }
}

async function draftLead(id, btn) {
    const originalHtml = btn.innerHTML;
    btn.innerHTML = '⌛';
    btn.disabled = true;

    try {
        const res = await fetch(`api/leads.php?action=draft&id=${id}`, { method: 'POST' });
        const result = await res.json();
        
        if (result.success) {
            const container = document.getElementById('modal-container');
            container.innerHTML = `
                <div class="glass p-8 rounded-2xl w-full max-w-2xl text-left">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-2xl font-bold">Email Draft Generated</h3>
                        <button onclick="closeModal()" class="text-slate-400 hover:text-white">✕</button>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-slate-500 mb-1">Subject Line</label>
                            <input type="text" id="draft-subject" value="${escapeHtml(result.subject)}" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-white">
                        </div>
                        <div>
                            <label class="block text-[10px] uppercase font-bold text-slate-500 mb-1">Message Body</label>
                            <textarea id="draft-body" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-white h-48">${escapeHtml(result.body)}</textarea>
                        </div>
                        <div class="flex gap-4">
                            <button onclick="sendDraftEmail(${id}, this)" class="flex-1 bg-emerald-600 py-3 rounded-lg font-bold">Send Now</button>
                            <button onclick="closeModal()" class="flex-1 bg-slate-700 py-3 rounded-lg font-bold">Close</button>
                        </div>
                    </div>
                </div>
            `;
            container.classList.remove('hidden');
            fetchLeads();
        } else {
            alert('Drafting failed: ' + result.error);
        }
    } catch (e) {
        alert('Failed to connect to drafting agent');
    } finally {
        btn.innerHTML = originalHtml;
        btn.disabled = false;
    }
}

async function sendDraftEmail(leadId, btn, forceResend = false) {
    const subject = document.getElementById('draft-subject').value;
    const body = document.getElementById('draft-body').value;
    const originalText = btn.innerText;

    btn.disabled = true;
    btn.innerText = "Sending...";

    try {
        const response = await fetch('api/send_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, subject, body, force_resend: forceResend })
        });
        const result = await response.json();

        if (result.success) {
            toast('Email sent! 🚀');
            closeModal();
            fetchLeads();
            fetchStats();
        } else if (response.status === 409 && result.already_sent && !forceResend) {
            // Duplicate-send protection tripped: offer a deliberate resend.
            btn.disabled = false;
            btn.innerText = originalText;
            const sentAt = result.already_sent.sent_at ? new Date(result.already_sent.sent_at).toLocaleString() : 'previously';
            if (confirm(`⚠️ Already emailed this lead (${sentAt} via ${result.already_sent.provider}).\n\nSend again anyway?`)) {
                sendDraftEmail(leadId, btn, true);
            }
        } else {
            toast('Error: ' + (result.error || 'Send failed'), 'error');
        }
    } catch (e) {
        toast('Failed to send email.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerText = originalText;
    }
}

function searchLeads() {
    // Reset to page 1 and let server do the filtering
    window.leadsPage.offset = 0;
    fetchLeads();
}

async function editLead(id) {
    const lead = window.allLeads.find(l => l.id == id);
    if (!lead) return;

    // Build campaigns options
    const campaigns = window.allCampaigns || [];
    const options = campaigns.map(c => `
        <option value="${c.id}" ${lead.campaign_id == c.id ? 'selected' : ''}>${escapeHtml(c.name)}</option>
    `).join('');

    const container = document.getElementById('modal-container');
    container.innerHTML = `
        <div class="glass p-8 rounded-2xl w-full max-w-md">
            <h3 class="text-2xl font-bold mb-4">Edit Lead</h3>
            <div class="space-y-4 text-left">
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Company Name</label>
                    <input type="text" id="edit-company" value="${escapeHtml(lead.company_name)}" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Contact Name</label>
                    <input type="text" id="edit-contact" value="${escapeHtml(lead.contact_name || '')}" placeholder="Contact Name" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Email Address</label>
                    <input type="email" id="edit-email" value="${escapeHtml(lead.email || '')}" placeholder="Email Address" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Country (ISO 2-letter)</label>
                    <input type="text" id="edit-country" maxlength="2" value="${escapeHtml(lead.country_code || '')}" placeholder="e.g. CA, US \u2014 blank = unknown" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 uppercase">
                </div>
                <div>
                    <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Outreach Campaign</label>
                    <select id="edit-campaign" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-white">
                        <option value="">-- No Campaign --</option>
                        ${options}
                    </select>
                </div>
                <div class="flex gap-4 pt-2">
                    <button onclick="saveLeadEdit(${id})" class="flex-1 bg-blue-600 py-2.5 rounded-lg font-bold">Save Changes</button>
                    <button onclick="closeModal()" class="flex-1 bg-slate-700 py-2.5 rounded-lg">Cancel</button>
                </div>
            </div>
        </div>
    `;
    container.classList.remove('hidden');
}

async function saveLeadEdit(id) {
    const data = {
        id,
        company_name: document.getElementById('edit-company').value.trim(),
        contact_name: document.getElementById('edit-contact').value.trim(),
        email:        document.getElementById('edit-email').value.trim(),
        country_code: (document.getElementById('edit-country').value.trim().toUpperCase() || null),
        campaign_id:  document.getElementById('edit-campaign').value || null
    };
    if (!data.company_name) { toast('Company name is required', 'warn'); return; }
    try {
        const res = await fetch('api/leads.php?action=update', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) { closeModal(); toast('Lead saved!'); fetchLeads(); }
        else toast('Error: ' + (result.error || 'Update failed'), 'error');
    } catch (e) {
        toast('Failed to save lead', 'error');
    }
}

async function deleteLead(id) {
    if (!confirm('Delete this lead? This cannot be undone.')) return;
    try {
        const res = await fetch(`api/leads.php?action=delete&id=${id}`, { method: 'POST' });
        const result = await res.json();
        if (result.success) { toast('Lead deleted'); fetchLeads(); fetchStats(); }
        else toast('Error: ' + (result.error || 'Delete failed'), 'error');
    } catch (e) {
        toast('Failed to delete lead', 'error');
    }
}

function showImportLeadsModal() {
    const container = document.getElementById('modal-container');
    
    // Generate campaign options
    const campaignOptions = (window.allCampaigns || []).map(c => `
        <option value="${c.id}">${escapeHtml(c.name)}</option>
    `).join('');

    container.innerHTML = `
        <div class="glass p-8 rounded-2xl w-full max-w-md border border-white/10 shadow-2xl relative">
            <h3 class="text-2xl font-bold mb-2 bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent flex items-center gap-2">
                📥 <span>Import Leads CSV</span>
            </h3>
            <p class="text-slate-400 text-xs mb-6">Select a campaign to automatically assign these imported prospects.</p>
            
            <div class="space-y-4 mb-6">
                <div>
                    <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Select Target Campaign (Optional)</label>
                    <select id="import-campaign-id" class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm appearance-none">
                        <option value="">-- No Campaign (Unassigned) --</option>
                        ${campaignOptions}
                    </select>
                </div>
                
                <div>
                    <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Select CSV File</label>
                    <div class="relative w-full h-32 border-2 border-dashed border-white/10 hover:border-blue-500/50 rounded-2xl flex flex-col items-center justify-center cursor-pointer transition group" onclick="document.getElementById('import-file-input').click()">
                        <span class="text-3xl mb-1 group-hover:scale-110 transition">📄</span>
                        <span id="import-file-name" class="text-xs text-slate-400 font-medium truncate max-w-[200px]">Click to choose file</span>
                        <input type="file" id="import-file-input" class="hidden" accept=".csv" onchange="handleImportFileChange(this)">
                    </div>
                </div>
            </div>
            
            <div class="flex gap-4">
                <button onclick="submitImportLeads()" class="flex-1 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-blue-500/20 text-white transition">Upload & Import</button>
                <button onclick="closeModal()" class="flex-1 bg-slate-800 hover:bg-slate-700 py-2.5 rounded-xl text-sm font-bold text-slate-300 transition">Cancel</button>
            </div>
        </div>
    `;
    container.classList.remove('hidden');
}

function handleImportFileChange(input) {
    const file = input.files[0];
    const nameEl = document.getElementById('import-file-name');
    if (file && nameEl) {
        nameEl.innerText = file.name;
        nameEl.classList.remove('text-slate-400');
        nameEl.classList.add('text-blue-400');
    }
}

async function submitImportLeads() {
    const fileInput = document.getElementById('import-file-input');
    const campaignSelect = document.getElementById('import-campaign-id');
    const file = fileInput ? fileInput.files[0] : null;
    
    if (!file) {
        alert('Please choose a CSV file to upload.');
        return;
    }
    
    const campaignId = campaignSelect ? campaignSelect.value : '';
    const formData = new FormData();
    formData.append('file', file);
    if (campaignId) {
        formData.append('campaign_id', campaignId);
    }
    
    try {
        const response = await fetch('api/import_leads.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        if (result.success) {
            alert(`Successfully imported ${result.count} leads.`);
            closeModal();
            fetchLeads();
            fetchStats();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Failed to upload and import file.');
    }
}

async function fetchStats() {
    try {
        const response = await fetch('api/stats.php');
        const result = await response.json();
        if (result.success) {
            const s = result.data;
            // Update individual tiles
            for (const [key, value] of Object.entries(s)) {
                const el = document.getElementById(`stat-${key}`);
                if (el) el.innerText = value.toLocaleString();
            }

            // Calculate Funnel Percentages
            const total = s.total_leads || 1;
            document.getElementById('conv-qualified').innerText = `${((s.qualified || 0) / total * 100).toFixed(1)}% Efficiency`;
            document.getElementById('conv-contacted').innerText = `${((s.contacted || 0) / total * 100).toFixed(1)}% Outreach`;
            document.getElementById('conv-converted').innerText = `${((s.converted || 0) / total * 100).toFixed(1)}% Win Rate`;

            // Compliance item 4: visible notice for auto-paused campaigns.
            renderPauseBanner(s.paused_campaigns || []);
        }
    } catch (e) {
        console.warn('Failed to fetch stats');
    }
}

/* Compliance item 4: visible dashboard notice for auto-paused campaigns.
 * Called from fetchStats() with api/stats.php's paused_campaigns list. */
function renderPauseBanner(paused) {
    const box = document.getElementById('pause-banner');
    if (!box) return;
    if (!Array.isArray(paused) || paused.length === 0) { box.innerHTML = ''; return; }
    const rows = paused.map(c => `
        <div class="flex items-center justify-between gap-3 py-1.5 border-b border-rose-500/10 last:border-0">
            <div class="min-w-0">
                <span class="font-bold text-rose-300">${escapeHtml(c.name || ('Campaign #' + c.id))}</span>
                <span class="text-rose-400/80 text-xs ml-2">${escapeHtml(c.paused_reason || 'rate threshold breached')}</span>
            </div>
            <button onclick="resumeCampaign(${parseInt(c.id, 10)})"
                class="shrink-0 text-xs font-bold px-3 py-1.5 rounded-lg bg-emerald-500/15 hover:bg-emerald-500/25 text-emerald-300 border border-emerald-500/30 transition">
                Resume
            </button>
        </div>`).join('');
    box.innerHTML = `
        <div class="rounded-2xl border border-rose-500/30 bg-rose-500/10 p-4">
            <div class="flex items-center gap-2 mb-2">
                <span class="text-rose-400 text-lg">⏸️</span>
                <h3 class="text-sm font-bold text-rose-200 uppercase tracking-wider">
                    ${paused.length} campaign${paused.length === 1 ? '' : 's'} auto-paused
                </h3>
            </div>
            <p class="text-xs text-rose-300/80 mb-2">
                Complaint or bounce rate hit the safety threshold. Review the list/reputation issue, then resume manually.
            </p>
            ${rows}
        </div>`;
}

async function resumeCampaign(id) {
    if (!confirm('Resume this campaign? Make sure the underlying complaint/bounce issue is fixed first.')) return;
    try {
        const response = await fetch('api/campaigns.php?type=campaigns&action=resume', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });
        const result = await response.json();
        if (result.success) {
            toast('Campaign resumed', 'success');
            fetchStats();
            if (typeof fetchCampaigns === 'function') fetchCampaigns();
        } else {
            toast('Resume failed: ' + (result.error || 'unknown error'), 'error');
        }
    } catch (e) {
        toast('Resume failed: network error', 'error');
    }
}

async function fetchCampaigns() {
    const tab = document.getElementById('campaigns-tab');
    if (!tab) return;
    try {
        const response = await fetch('api/campaigns.php?type=campaigns');
        const result = await response.json();
        if (result.success) {
            window.allCampaigns = result.data; // keep in sync
            tab.innerHTML = result.data.length ? result.data.map(c => `
                <div class="glass p-6 rounded-2xl border-t-2 ${c.is_active ? 'border-blue-500' : 'border-slate-700'} flex flex-col gap-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1 min-w-0">
                            <h3 class="text-lg font-bold text-white truncate">${escapeHtml(c.name)}</h3>
                            <p class="text-slate-400 text-xs mt-1 line-clamp-2">${escapeHtml(c.description || 'No description')}</p>
                        </div>
                        <button onclick="toggleCampaignActive(${c.id})"
                            class="shrink-0 text-xs px-2.5 py-1 rounded-lg border cursor-pointer transition font-semibold
                            ${c.is_active
                                ? 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400 hover:bg-emerald-500/20'
                                : 'bg-slate-800 border-white/10 text-slate-500 hover:text-white'}"
                            title="Toggle active">${c.is_active ? '● Active' : '○ Paused'}</button>
                    </div>
                    <div class="flex items-center gap-2 mt-auto">
                        <button onclick="viewTemplates(${c.id})" class="flex-1 text-center text-xs font-bold py-2 rounded-xl bg-blue-600/10 hover:bg-blue-600/20 text-blue-400 border border-blue-500/20 transition">📋 Manage Steps</button>
                        <button onclick="editCampaign(${c.id})" class="p-2 rounded-xl bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white transition" title="Edit">⚙️</button>
                        <button onclick="deleteCampaign(${c.id}, '${escapeHtml(c.name).replace(/'/g, "\\'")}')"
                            class="p-2 rounded-xl bg-rose-500/5 hover:bg-rose-500/20 text-rose-500/40 hover:text-rose-400 border border-rose-500/10 transition" title="Delete">🗑️</button>
                    </div>
                </div>
            `).join('') : '<div class="p-20 text-center text-slate-500 col-span-full">No campaigns found. Create your first sequence!</div>';
        }
    } catch (e) {
        tab.innerHTML = '<div class="p-20 text-center text-red-400 col-span-full">Failed to load campaigns.</div>';
    }
}

function showTab(tabName) {
    const tabs = ['leads', 'campaigns', 'settings', 'agent', 'influencer', 'mass'];
    tabs.forEach(t => {
        const el = document.getElementById(t + '-tab');
        const btn = document.getElementById('tab-' + t + '-btn');
        if (el) el.classList.toggle('hidden', t !== tabName);
        if (btn) {
            if (t === tabName) {
                btn.classList.add('active', 'bg-blue-600/10', 'text-blue-400');
                btn.classList.remove('text-slate-400', 'hover:bg-white/5');
            } else {
                btn.classList.remove('active', 'bg-blue-600/10', 'text-blue-400');
                btn.classList.add('text-slate-400', 'hover:bg-white/5');
            }
        }
    });
    if (tabName === 'campaigns') fetchCampaigns();
    if (tabName === 'settings') fetchSettings();
    if (tabName === 'agent' && typeof checkMode === 'function') checkMode();
    if (tabName === 'influencer' && typeof loadInfluencers === 'function') loadInfluencers();
    if (tabName === 'mass' && typeof loadProxySettings === 'function') loadProxySettings();
}

function getStatusClass(status) {
    const classes = {
        'New': 'bg-blue-900/40 text-blue-300',
        'Enriched': 'bg-purple-900/40 text-purple-300',
        'Contacted': 'bg-amber-900/40 text-amber-300',
        'Qualified': 'bg-emerald-900/40 text-emerald-300',
        'Drafted': 'bg-blue-900/40 text-blue-300',
        'Unqualified': 'bg-slate-800 text-slate-400'
    };
    return classes[status] || 'bg-slate-800 text-slate-400';
}

async function viewTemplates(campaignId) {
    const container = document.getElementById('modal-container');
    container.innerHTML = `<div class="glass p-8 rounded-2xl w-full max-w-4xl max-h-[80vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-6">
            <h3 class="text-2xl font-bold">Manage Sequence Steps</h3>
            <button onclick="closeModal()" class="text-slate-400 hover:text-white">✕</button>
        </div>
        <div id="template-list" class="space-y-3 mb-6">
            <div class="text-center py-8 text-slate-500">Loading steps...</div>
        </div>
        <button onclick="addNewStep(${campaignId})" class="w-full border-2 border-dashed border-slate-700 py-4 rounded-xl text-slate-400 hover:border-blue-500 hover:text-blue-400 transition">+ Add Sequence Step</button>
    </div>`;
    container.classList.remove('hidden');
    try {
        const response = await fetch(`api/campaigns.php?type=templates&campaign_id=${campaignId}`);
        const result = await response.json();
        window._tplCache = {};
        (result.data || []).forEach(t => { window._tplCache[t.id] = t; });
        const listEl = document.getElementById('template-list');
        listEl.innerHTML = (result.data || []).length ? (result.data || []).map(t => `
            <div class="bg-slate-900/60 p-5 rounded-xl border border-white/5 hover:border-white/10 transition">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-xs uppercase font-bold text-blue-400 bg-blue-500/10 border border-blue-500/20 px-2 py-0.5 rounded">Step ${t.step_order}</span>
                    <div class="flex gap-2">
                        <button onclick="editTemplate(${t.id}, ${campaignId})"
                            class="p-1.5 rounded-lg hover:bg-white/10 text-slate-400 hover:text-white transition text-sm" title="Edit step">✏️</button>
                        <button onclick="deleteTemplate(${t.id}, ${campaignId})"
                            class="p-1.5 rounded-lg hover:bg-rose-500/10 text-rose-500/40 hover:text-rose-400 transition text-sm" title="Delete step">🗑️</button>
                    </div>
                </div>
                <div class="font-bold text-white mb-1">${escapeHtml(t.subject)}</div>
                <div class="text-xs text-slate-400 line-clamp-2">${escapeHtml(t.body)}</div>
            </div>
        `).join('') : '<div class="text-center py-8 text-slate-500 italic">No steps defined. Add your first sequence step!</div>';
    } catch (e) {
        toast('Failed to load steps', 'error');
    }
}

function addNewStep(campaignId) {
    const container = document.getElementById('modal-container');
    container.innerHTML = `
        <div class="glass p-8 rounded-2xl w-full max-w-2xl text-left">
            <h3 class="text-2xl font-bold mb-6">Add Sequence Step</h3>
            <div class="space-y-4">
                <input type="text" id="tpl-subject" placeholder="Subject Line" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-white">
                <textarea id="tpl-body" placeholder="Email body... Use {{company_name}}, {{contact_name}} variables." class="w-full bg-slate-900 border border-slate-700 rounded-lg px-4 py-2 text-white h-48"></textarea>
                <div class="flex gap-4">
                    <button onclick="saveTemplate(${campaignId})" class="flex-1 bg-blue-600 py-3 rounded-lg font-bold">Save Step</button>
                    <button onclick="viewTemplates(${campaignId})" class="flex-1 bg-slate-700 py-3 rounded-lg font-bold">Back</button>
                </div>
            </div>
        </div>
    `;
}

async function saveTemplate(campaignId) {
    const subject = document.getElementById('tpl-subject').value.trim();
    const body    = document.getElementById('tpl-body').value.trim();
    if (!subject) { toast('Subject is required', 'warn'); return; }
    try {
        const res = await fetch('api/campaigns.php?type=templates', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ campaign_id: campaignId, subject, body })
        });
        const result = await res.json();
        if (result.success) { toast('Step added!'); viewTemplates(campaignId); }
        else toast(result.error || 'Failed to save step', 'error');
    } catch (e) {
        toast('Network error', 'error');
    }
}

window.toggleActiveProviderFields = function() {
    // 1. LLM Providers
    const activeLlm = document.getElementById('setting-active_llm_provider')?.value || 'gemini';
    document.querySelectorAll('.llm-provider-fields').forEach(el => el.classList.add('hidden'));
    const llmGroup = document.getElementById(`provider-group-${activeLlm}`);
    if (llmGroup) llmGroup.classList.remove('hidden');

    // 2. Search Providers
    const activeSearch = document.getElementById('setting-active_search_provider')?.value || 'tavily';
    document.querySelectorAll('.search-provider-fields').forEach(el => el.classList.add('hidden'));
    const searchGroup = document.getElementById(`provider-group-${activeSearch}`);
    if (searchGroup) searchGroup.classList.remove('hidden');

    // 3. Email Providers
    const activeEmail = document.getElementById('setting-active_email_provider')?.value || 'smtp';
    const smtpGroup = document.getElementById('email-group-smtp');
    if (smtpGroup) {
        if (activeEmail === 'smtp' || activeEmail === 'custom_smtp' || activeEmail.endsWith('_smtp')) {
            smtpGroup.classList.remove('hidden');
        } else {
            smtpGroup.classList.add('hidden');
        }
    }
    
    // Also toggle fields for other email APIs
    document.querySelectorAll('.email-provider-fields').forEach(el => el.classList.add('hidden'));
    if (activeEmail !== 'smtp' && activeEmail !== 'custom_smtp' && !activeEmail.endsWith('_smtp')) {
        const emailApiGroup = document.getElementById(`email-group-${activeEmail}`);
        if (emailApiGroup) emailApiGroup.classList.remove('hidden');
    }

    updateSetupProgress();
};

window.focusSettingField = function(fieldId) {
    const el = document.getElementById(fieldId);
    if (el) {
        el.focus();
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Add a temporary subtle flash highlight
        el.classList.add('ring-4', 'ring-blue-500/50');
        setTimeout(() => el.classList.remove('ring-4', 'ring-blue-500/50'), 2000);
    }
};

window.updateSetupProgress = function() {
    // 1. Check active LLM
    const activeLlm = document.getElementById('setting-active_llm_provider')?.value || 'gemini';
    let llmConnected = false;
    if (activeLlm === 'ollama') {
        const url = document.getElementById('setting-ollama_url')?.value || '';
        llmConnected = url.length > 0;
    } else {
        const key = document.getElementById(`setting-${activeLlm}_api_key`)?.value || '';
        llmConnected = key.length > 0;
    }
    const llmCheck = document.getElementById('setup-check-llm');
    if (llmCheck) {
        if (llmConnected) {
            llmCheck.className = 'px-2.5 py-1 rounded bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center gap-1';
            llmCheck.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> AI Brain: Connected';
        } else {
            llmCheck.className = 'px-2.5 py-1 rounded bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center gap-1';
            llmCheck.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> AI Brain: Unset';
        }
    }

    // 2. Check active Search
    const activeSearch = document.getElementById('setting-active_search_provider')?.value || 'tavily';
    let searchConnected = false;
    if (activeSearch === 'searxng') {
        const url = document.getElementById('setting-searxng_url')?.value || '';
        searchConnected = url.length > 0;
    } else if (activeSearch === 'apify') {
        const token = document.getElementById('setting-apify_api_token')?.value || '';
        searchConnected = token.length > 0;
    } else {
        const key = document.getElementById(`setting-${activeSearch}_api_key`)?.value || '';
        searchConnected = key.length > 0;
    }
    const searchCheck = document.getElementById('setup-check-search');
    if (searchCheck) {
        if (searchConnected) {
            searchCheck.className = 'px-2.5 py-1 rounded bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center gap-1';
            searchCheck.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Search Engine: Connected';
        } else {
            searchCheck.className = 'px-2.5 py-1 rounded bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center gap-1';
            searchCheck.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Search Engine: Unset';
        }
    }

    // 3. Check active Email
    const activeEmail = document.getElementById('setting-active_email_provider')?.value || 'smtp';
    let emailConnected = false;
    if (activeEmail === 'smtp' || activeEmail === 'custom_smtp' || activeEmail.endsWith('_smtp')) {
        const host = document.getElementById('setting-smtp_host')?.value || '';
        const user = document.getElementById('setting-smtp_user')?.value || '';
        emailConnected = host.length > 0 && user.length > 0;
    } else {
        const key = document.getElementById(`setting-${activeEmail}_api_key`)?.value || '';
        emailConnected = key.length > 0;
    }
    const emailCheck = document.getElementById('setup-check-email');
    if (emailCheck) {
        if (emailConnected) {
            emailCheck.className = 'px-2.5 py-1 rounded bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 flex items-center gap-1';
            emailCheck.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> Outreach: Connected';
        } else {
            emailCheck.className = 'px-2.5 py-1 rounded bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center gap-1';
            emailCheck.innerHTML = '<span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Outreach: Unset';
        }
    }
};

async function fetchSettings() {
    try {
        const response = await fetch('api/settings.php');
        const result = await response.json();
        if (result.success) {
            for (const [key, value] of Object.entries(result.data)) {
                const el = document.getElementById(`setting-${key}`);
                if (!el) continue;
                if (el.tagName === 'SELECT') {
                    el.value = value;
                } else {
                    el.value = value;
                }

                // Update Safety Indicator
                if (key === 'operational_mode') {
                    const indicator = document.getElementById('safety-indicator');
                    if (value === 'Production') {
                        indicator.innerText = 'Production Mode: Live';
                        indicator.className = 'px-2 py-0.5 rounded text-[10px] uppercase font-bold bg-rose-900/40 text-rose-400 border border-rose-500/30';
                    } else {
                        indicator.innerText = 'Safety Mode: Active';
                        indicator.className = 'px-2 py-0.5 rounded text-[10px] uppercase font-bold bg-amber-900/40 text-amber-400 border border-amber-500/30';
                    }
                }
            }
            
            // Toggle active fields on load
            toggleActiveProviderFields();
            
            // Bind quick update handlers on all key setting elements
            const inputsToCheck = [
                'setting-active_llm_provider', 'setting-active_search_provider', 'setting-active_email_provider',
                'setting-ollama_url', 'setting-gemini_api_key', 'setting-openai_api_key', 'setting-anthropic_api_key',
                'setting-groq_api_key', 'setting-mistral_api_key', 'setting-openrouter_api_key',
                'setting-scrapingant_api_key', 'setting-firecrawl_api_key', 'setting-serper_api_key',
                'setting-tavily_api_key', 'setting-exa_api_key', 'setting-searxng_url', 'setting-apify_api_token',
                'setting-smtp_host', 'setting-smtp_user', 'setting-resend_api_key', 'setting-brevo_api_key',
                'setting-sendgrid_api_key', 'setting-mailgun_api_key', 'setting-mailjet_api_key',
                'setting-postmark_api_key', 'setting-mailersend_api_key', 'setting-mailtrap_api_key',
                'setting-zoho_api_key', 'setting-netcore_api_key',
                'setting-proxy_enabled', 'setting-proxy_socks_url', 'setting-proxy_verify_url'
            ];
            inputsToCheck.forEach(id => {
                const el = document.getElementById(id);
                if (el && !el.dataset.setupBound) {
                    el.dataset.setupBound = 'true';
                    el.addEventListener('input', updateSetupProgress);
                    el.addEventListener('change', updateSetupProgress);
                }
            });

            // Trigger quota monitor dashboard refresh
            fetchQuotaUsage();
        }
    } catch (e) {
        console.warn('Failed to fetch settings');
    }
}

async function saveSettings() {
    const keys = [
        'operational_mode', 'active_llm_provider', 'ollama_url',
        'gemini_api_key', 'openai_api_key', 'anthropic_api_key', 'groq_api_key', 'mistral_api_key', 'openrouter_api_key',
        'jev_enabled', 'jev_mode', 'jev_api_key', 'jev_model', 'jev_min_confidence',
        'active_search_provider', 'fallback_search_provider', 'failover_threshold',
        'scrapingant_api_key', 'scrapingant_api_key_backup', 
        'firecrawl_api_key', 'firecrawl_api_key_backup', 
        'serper_api_key', 'tavily_api_key', 'exa_api_key', 'searxng_url', 'apify_api_token',
        'scraper_priority', 'bright_data_proxy_url', 'proxy_rotation_enabled',
        'active_email_provider', 'email_sender', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption',
        'company_legal_name', 'physical_address', 'app_base_url', 'compliance_casl_ca_block', 'compliance_casl_unknown_country',
        'resend_api_key', 'brevo_api_key', 'sendgrid_api_key', 'mailgun_api_key', 'mailjet_api_key', 
        'postmark_api_key', 'mailersend_api_key', 'mailtrap_api_key', 'zoho_api_key', 'netcore_api_key',
        'sendpulse_smtp_pass', 'amazon_ses_smtp_pass', 'zoho_smtp_pass', 'netcore_smtp_pass',
        'proxy_enabled', 'proxy_socks_url', 'proxy_verify_url',
        'verification_required', 'verification_provider', 'verification_api_key',
        'verification_risky_action', 'verification_strict', 'verification_cache_days'
    ];
    const settings = {};
    keys.forEach(k => {
        const el = document.getElementById(`setting-${k}`);
        if (el) settings[k] = el.value;
    });

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('api/settings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(settings)
        });
        const result = await response.json();
        if (result.success) {
            alert('Settings saved successfully!');
            fetchSettings(); // Refresh UI
        } else {
            alert('Error: ' + result.error);
        }
    } catch (e) {
        alert('Error saving settings');
    }
}

async function fetchQuotaUsage() {
    const container = document.getElementById('quota-dashboard-container');
    if (!container) return;

    try {
        const response = await fetch('api/quota_status.php');
        const result = await response.json();
        if (result.success && result.data) {
            container.innerHTML = result.data.map(item => {
                let statusBadge = '';
                let progressColor = 'bg-blue-500';
                let cardBorder = 'border-white/5';
                let opacity = 'opacity-100';

                if (item.status === 'active') {
                    statusBadge = `<span class="flex items-center gap-1 text-[10px] text-emerald-400 font-bold bg-emerald-500/10 border border-emerald-500/20 px-2 py-0.5 rounded-full"><span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Active</span>`;
                    progressColor = 'bg-gradient-to-r from-emerald-500 to-teal-500';
                } else if (item.status === 'cooldown') {
                    statusBadge = `<span class="flex items-center gap-1 text-[10px] text-amber-400 font-bold bg-amber-500/10 border border-amber-500/20 px-2 py-0.5 rounded-full"><span class="w-1.5 h-1.5 rounded-full bg-amber-400 animate-pulse"></span> Cool-Down</span>`;
                    progressColor = 'bg-gradient-to-r from-amber-500 to-orange-500';
                    cardBorder = 'border-amber-500/20';
                } else if (item.status === 'limited') {
                    statusBadge = `<span class="flex items-center gap-1 text-[10px] text-rose-400 font-bold bg-rose-500/10 border border-rose-500/20 px-2 py-0.5 rounded-full"><span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Rate-Limited</span>`;
                    progressColor = 'bg-gradient-to-r from-rose-500 to-pink-500';
                    cardBorder = 'border-rose-500/20';
                } else {
                    statusBadge = `<span class="flex items-center gap-1 text-[10px] text-slate-500 font-bold bg-white/5 border border-white/5 px-2 py-0.5 rounded-full">Unconfigured</span>`;
                    progressColor = 'bg-slate-700';
                    opacity = 'opacity-40 hover:opacity-75 transition';
                }

                const percentage = Math.min(100, Math.round((item.used / item.limit) * 100)) || 0;

                return `
                    <div class="glass p-4 rounded-2xl border ${cardBorder} space-y-3 bg-slate-900/40 backdrop-blur-xl ${opacity} flex flex-col justify-between">
                        <div>
                            <div class="flex justify-between items-start">
                                <h4 class="text-xs font-bold text-slate-300 truncate pr-2" title="${item.name}">${item.name}</h4>
                                ${statusBadge}
                            </div>
                            <div class="text-[10px] text-slate-500 mt-1 uppercase font-semibold tracking-wider">${item.type} node</div>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex justify-between items-baseline text-[10px] font-mono">
                                <span class="text-slate-400 font-bold">${item.used} <span class="text-[9px] text-slate-600">/ ${item.limit}</span></span>
                                <span class="text-slate-500">${percentage}%</span>
                            </div>
                            <div class="w-full bg-slate-950/60 rounded-full h-1.5 overflow-hidden border border-white/5">
                                <div class="h-full rounded-full ${progressColor} transition-all duration-500" style="width: ${percentage}%"></div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }
    } catch (e) {
        container.innerHTML = `<div class="p-8 text-center text-red-400 text-xs font-semibold col-span-full border border-red-500/20 rounded-2xl bg-red-950/20">Failed to load quota dashboard status metrics. Check DB connection.</div>`;
    }
}

function refreshQuotaDashboard() {
    fetchQuotaUsage();
}

async function runSmtpDiagnostics() {
    const hostEl = document.getElementById('setting-smtp_host');
    const portEl = document.getElementById('setting-smtp_port');
    const userEl = document.getElementById('setting-smtp_user');
    const passEl = document.getElementById('setting-smtp_pass');
    const encEl = document.getElementById('setting-smtp_encryption');

    const host = hostEl ? hostEl.value.trim() : '';
    const port = portEl ? portEl.value.trim() : '587';
    const user = userEl ? userEl.value.trim() : '';
    const pass = passEl ? passEl.value : '';
    const encryption = encEl ? encEl.value : 'tls';

    if (!host) {
        alert('Please enter an SMTP Host first.');
        return;
    }

    const outputContainer = document.getElementById('smtp-diagnostics-output');
    const logsPre = document.getElementById('smtp-diagnostics-logs');
    const summaryDiv = document.getElementById('smtp-diagnostics-summary');

    outputContainer.classList.remove('hidden');
    
    if (summaryDiv) {
        summaryDiv.innerHTML = `
            <div class="animate-pulse flex items-center gap-2 text-slate-400 text-xs w-full">
                <span class="w-2 h-2 rounded-full bg-blue-500 animate-ping"></span>
                <div>
                    <div class="font-bold text-slate-300">Testing SMTP Connection...</div>
                    <div class="text-[10px] text-slate-500 mt-0.5">Attempting port connection and credentials handshake with ${host}:${port} (${encryption})</div>
                </div>
            </div>
        `;
        summaryDiv.className = "mb-3 p-3.5 rounded-xl border border-blue-500/20 bg-blue-500/5 flex items-start gap-3";
    }

    logsPre.innerText = 'Running outbound SMTP connection diagnostics... Please wait...\n';
    logsPre.scrollTop = logsPre.scrollHeight;

    try {
        const response = await fetch('api/smtp_diagnostics.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ host, port, user, pass, encryption })
        });
        const result = await response.json();
        
        if (result.logs) {
            logsPre.innerText = result.logs.join('\n');
        } else {
            logsPre.innerText += `\nError: ${result.error || 'Unknown error occurred.'}`;
        }
        
        if (summaryDiv) {
            if (result.success) {
                summaryDiv.innerHTML = `
                    <div class="flex items-start gap-2.5 text-emerald-400 text-xs w-full">
                        <span class="text-base leading-none">✅</span>
                        <div>
                            <div class="font-bold text-emerald-300">Outbound Connection Successful!</div>
                            <div class="text-[10px] text-slate-400 mt-0.5">SMTP connection check passed. Your server successfully routed the handshake and authenticated.</div>
                        </div>
                    </div>
                `;
                summaryDiv.className = "mb-3 p-3.5 rounded-xl border border-emerald-500/20 bg-emerald-500/5 flex items-start gap-3";
            } else {
                summaryDiv.innerHTML = `
                    <div class="flex items-start gap-2.5 text-rose-400 text-xs w-full">
                        <span class="text-base leading-none">❌</span>
                        <div>
                            <div class="font-bold text-rose-300">SMTP Connection Check Failed</div>
                            <div class="text-[10px] text-slate-400 mt-0.5">Reason: ${result.error || 'Connection timed out or host rejected handshake. Check SMTP credentials below.'}</div>
                        </div>
                    </div>
                `;
                summaryDiv.className = "mb-3 p-3.5 rounded-xl border border-rose-500/20 bg-rose-500/5 flex items-start gap-3";
            }
        }
    } catch (e) {
        logsPre.innerText += `\nHTTP Error: ${e.message}`;
        if (summaryDiv) {
            summaryDiv.innerHTML = `
                <div class="flex items-start gap-2.5 text-rose-400 text-xs w-full">
                    <span class="text-base leading-none">❌</span>
                    <div>
                        <div class="font-bold text-rose-300">HTTP Connection Request Failed</div>
                        <div class="text-[10px] text-slate-400 mt-0.5">Failed to contact diagnostic endpoint: ${e.message}</div>
                    </div>
                </div>
            `;
            summaryDiv.className = "mb-3 p-3.5 rounded-xl border border-rose-500/20 bg-rose-500/5 flex items-start gap-3";
        }
    }
    logsPre.scrollTop = logsPre.scrollHeight;
}


/* ==========================================================================
 * Ideal Customer Profile (api/icp.php)
 * ========================================================================== */

const ICP_DIMENSION_KEYS = ['company_size', 'industry_fit', 'tech_stack', 'target_title', 'geography', 'trigger_signals'];

/** Mirrors IcpProfile::OPTIONAL_DIMENSIONS: the only dims with an on/off toggle. */
const ICP_TOGGLEABLE_DIMENSIONS = ['tech_stack'];

/** default_enable_weights from the last GET api/icp.php (fallback enable weight). */
let icpDefaultEnableWeights = { tech_stack: 15 };

function icpCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

async function icpPost(payload) {
    const res = await fetch('api/icp.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': icpCsrfToken() },
        body: JSON.stringify(payload)
    });
    return res.json();
}

function icpEscape(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);
}

function icpStatus(msg, isError) {
    const el = document.getElementById('icp-status');
    if (!el) return;
    el.textContent = msg;
    el.classList.remove('hidden');
    el.className = 'text-[11px] ' + (isError ? 'text-rose-400' : 'text-emerald-400');
    if (!isError) setTimeout(() => el.classList.add('hidden'), 4000);
}

function icpListToText(arr) {
    return Array.isArray(arr) ? arr.join(', ') : '';
}

function icpSetText(id, arr) {
    const el = document.getElementById(id);
    if (el) el.value = icpListToText(arr);
}

/** Load the full ICP state into the settings form. Called when the settings tab opens. */
async function loadIcp() {
    if (!document.getElementById('icp-weights')) return; // section not on this page
    try {
        const res = await fetch('api/icp.php');
        const result = await res.json();
        if (!result.success) {
            icpStatus('Could not load ICP: ' + (result.error || 'unknown error'), true);
            return;
        }
        const d = result.data || {};
        const profile = d.profile || {};
        const dims = d.dimensions || {};
        const labels = d.dimension_labels || {};
        icpDefaultEnableWeights = d.default_enable_weights || icpDefaultEnableWeights;

        document.getElementById('icp-profile-name').textContent = profile.name || 'Default ICP';
        document.getElementById('icp-pain').value = profile.pain_statement || '';

        // (a) Day-zero targets
        const cfg = k => (dims[k] && dims[k].target_config) || {};
        document.getElementById('icp-t-company_size-min').value = cfg('company_size').min_employees ?? '';
        document.getElementById('icp-t-company_size-max').value = cfg('company_size').max_employees ?? '';
        icpSetText('icp-t-industry_fit-include', cfg('industry_fit').include);
        icpSetText('icp-t-industry_fit-exclude', cfg('industry_fit').exclude);
        icpSetText('icp-t-tech_stack-keywords', cfg('tech_stack').tools);
        icpSetText('icp-t-target_title-titles', cfg('target_title').titles);
        icpSetText('icp-t-geography-countries', cfg('geography').countries);
        icpSetText('icp-t-geography-regions', cfg('geography').regions);
        icpSetText('icp-t-trigger_signals-signals', cfg('trigger_signals').signals);

        // (b) Weight editor
        const wrap = document.getElementById('icp-weights');
        wrap.innerHTML = '';
        ICP_DIMENSION_KEYS.forEach(key => {
            const dim = dims[key] || { weight: 0, buyer_locked: false };
            const locked = !!dim.buyer_locked;
            const toggleable = ICP_TOGGLEABLE_DIMENSIONS.indexOf(key) !== -1;
            // Initial switch state comes from the server's enabled flag, never
            // from weight==0 (a disabled dim carries weight 0, but weight 0 is
            // not how we learn the flag).
            const isOn = dim.enabled === undefined ? true : !!dim.enabled;
            const row = document.createElement('div');
            row.className = 'flex items-center gap-3 p-3 rounded-xl bg-slate-900/40 border border-white/5';
            row.innerHTML =
                '<div class="flex-1 min-w-0">' +
                    '<div class="text-xs font-bold text-slate-200">' + icpEscape(labels[key] || key) + '</div>' +
                    '<div class="text-[10px] text-slate-500">' +
                        (locked
                            ? '<span class="text-amber-400 font-bold">🔒 buyer-locked</span> <button type="button" onclick="icpUnlockDimension(\'' + icpEscape(key) + '\')" class="ml-1 underline text-slate-400 hover:text-slate-200">unlock</button>'
                            : '<span class="text-emerald-400">🔓 auto-tunable</span>') +
                        (toggleable
                            ? ' <span class="ml-2 font-bold ' + (isOn ? 'text-emerald-400' : 'text-slate-500') + '">' + (isOn ? '\u25cf on' : '\u25cb off') + '</span>'
                            : '') +
                    '</div>' +
                '</div>' +
                '<input type="number" id="icp-w-' + icpEscape(key) + '" min="0" max="100" value="' + Number(dim.weight || 0) + '" ' +
                (toggleable
                    ? '<button type="button" role="switch" aria-checked="' + isOn + '" ' +
                      'onclick="icpToggleDimension(\'' + icpEscape(key) + '\', ' + (!isOn) + ')"' +
                      'title="' + (isOn ? 'Disable' : 'Enable') + ' ' + icpEscape(key) + '" ' +
                      'class="relative w-11 h-6 shrink-0 rounded-full transition-colors ' + (isOn ? 'bg-emerald-500' : 'bg-slate-700') + '">' +
                      '<span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform ' + (isOn ? 'translate-x-5' : '') + '"></span>' +
                      '</button>'
                    : '') +
                    'oninput="icpRefreshWeightSum()" ' +
                    'class="w-20 bg-slate-900/60 border border-white/5 rounded-xl px-3 py-2 outline-none text-sm text-slate-200 text-center focus:border-emerald-500/50 transition">';
            wrap.appendChild(row);
        });
        icpRefreshWeightSum();

        // (c) Exclusion list
        const excl = d.exclusions || [];
        const exclWrap = document.getElementById('icp-exclusions');
        exclWrap.innerHTML = '';
        if (!excl.length) {
            exclWrap.innerHTML = '<p class="text-[11px] text-slate-500 italic">No vetoes yet — every lead scores normally.</p>';
        }
        excl.forEach(e => {
            const row = document.createElement('div');
            row.className = 'flex items-center gap-3 p-2.5 rounded-xl bg-rose-500/5 border border-rose-500/20';
            row.innerHTML =
                '<span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase tracking-wider bg-rose-500/15 text-rose-300 border border-rose-500/25 shrink-0">' + icpEscape(e.exclusion_type) + '</span>' +
                '<div class="flex-1 min-w-0">' +
                    '<div class="text-xs text-slate-200 font-semibold truncate">' + icpEscape(e.value) + '</div>' +
                    (e.note ? '<div class="text-[10px] text-slate-500 truncate">' + icpEscape(e.note) + '</div>' : '') +
                '</div>' +
                '<button type="button" data-exc-id="' + Number(e.id) + '" class="exc-del text-[10px] font-bold text-rose-400 hover:text-rose-200 uppercase tracking-wider shrink-0">Remove</button>';
            exclWrap.appendChild(row);
        });
        exclWrap.querySelectorAll('.exc-del').forEach(btn => {
            btn.addEventListener('click', () => icpDeleteExclusion(Number(btn.dataset.excId)));
        });

        // (d) Thresholds
        const t = d.thresholds || {};
        document.getElementById('icp-threshold-qualify').value = t.qualify ?? 75;
        document.getElementById('icp-threshold-review').value = t.review ?? 50;

        // Weight history
        const hist = d.weight_history || [];
        const histWrap = document.getElementById('icp-history');
        histWrap.innerHTML = '';
        if (!hist.length) {
            histWrap.innerHTML = '<p class="text-[11px] text-slate-500 italic">No adjustments yet.</p>';
        }
        hist.forEach(h => {
            const row = document.createElement('div');
            row.className = 'flex items-center gap-2 p-2 rounded-lg bg-slate-900/40 border border-white/5';
            row.innerHTML =
                '<span class="font-semibold text-slate-300">' + icpEscape(h.dimension_key) + '</span>' +
                '<span class="text-slate-500">' + Number(h.old_weight) + ' → ' + Number(h.new_weight) + '</span>' +
                '<span class="px-1.5 py-0.5 rounded text-[9px] font-bold uppercase ' +
                    (h.created_by === 'auto_tuner' ? 'bg-indigo-500/15 text-indigo-300' : 'bg-emerald-500/15 text-emerald-300') + '">' +
                    icpEscape(h.created_by === 'auto_tuner' ? 'auto' : 'manual') + '</span>' +
                (h.reason ? '<span class="text-slate-500 truncate flex-1">' + icpEscape(h.reason) + '</span>' : '') +
                '<span class="text-slate-600 ml-auto shrink-0">' + icpEscape(h.created_at || '') + '</span>';
            histWrap.appendChild(row);
        });
    } catch (e) {
        icpStatus('Could not load ICP: ' + e.message, true);
    }
}

function icpRefreshWeightSum() {
    let sum = 0;
    ICP_DIMENSION_KEYS.forEach(key => {
        const el = document.getElementById('icp-w-' + key);
        if (el) sum += Number(el.value) || 0;
    });
    const el = document.getElementById('icp-weight-sum');
    el.textContent = sum;
    el.className = 'text-xl font-bold ' + (sum === 100 ? 'text-emerald-400' : 'text-rose-400');
}

/**
 * Toggle a toggleable dimension (tech_stack) on or off.
 *
 * Builds a full weight vector from the current spinbuttons: on enable the
 * spinbutton value is used if it is an integer 1-100, otherwise the
 * server-provided default enable weight (15); the other dims scale
 * proportionally to (100 - techWeight) with rounding corrected on the
 * largest weight. On disable tech_stack is forced to 0 and the others
 * scale to 100. POSTs via icpPost(); on success re-renders, on failure
 * shows icpStatus and re-renders to revert the switch.
 */
async function icpToggleDimension(key, on) {
    const cur = {};
    ICP_DIMENSION_KEYS.forEach(k => {
        const el = document.getElementById('icp-w-' + k);
        cur[k] = el ? Number(el.value) || 0 : 0;
    });

    let techWeight;
    if (on) {
        const v = cur[key];
        const defW = icpDefaultEnableWeights[key];
        const fallback = Number.isInteger(defW) && defW >= 1 && defW <= 100 ? defW : 15;
        techWeight = (Number.isInteger(v) && v >= 1 && v <= 100) ? v : fallback;
    } else {
        techWeight = 0;
    }

    const weights = {};
    const others = ICP_DIMENSION_KEYS.filter(k => k !== key);
    const target = 100 - techWeight;
    const otherSum = others.reduce((sum, k) => sum + cur[k], 0);
    if (otherSum > 0) {
        let acc = 0;
        others.forEach(k => {
            const w = Math.round(cur[k] * target / otherSum);
            weights[k] = w;
            acc += w;
        });
        // Correct rounding drift on the largest weight.
        let largest = others[0];
        others.forEach(k => { if (weights[k] > weights[largest]) largest = k; });
        weights[largest] += (target - acc);
    } else {
        others.forEach((k, i) => {
            weights[k] = Math.floor(target / others.length) + (i < target % others.length ? 1 : 0);
        });
    }
    weights[key] = techWeight;

    try {
        const result = await icpPost({
            action: 'set_dimension_enabled',
            dimension_key: key,
            enabled: on,
            weights: weights,
            reason: 'buyer toggled tech_stack ' + (on ? 'on' : 'off') + ' via settings UI'
        });
        if (result.success) {
            icpStatus('Tech stack dimension ' + (on ? 'enabled' : 'disabled') + '.');
        } else {
            icpStatus('Toggle failed: ' + (result.error || 'unknown error'), true);
        }
    } catch (e) {
        icpStatus('Toggle failed: ' + e.message, true);
    }
    loadIcp(); // re-render: shows the real server state either way
}

/** (a) Save the day-zero founder hypothesis: pain statement + dimension targets. */
async function saveIcpHypothesis() {
    const textToList = id => {
        const el = document.getElementById(id);
        if (!el || !el.value.trim()) return [];
        return el.value.split(',').map(s => s.trim()).filter(Boolean);
    };
    const intOrNull = id => {
        const el = document.getElementById(id);
        const v = el ? el.value.trim() : '';
        return v === '' ? null : Number(v);
    };
    const payload = {
        action: 'save_hypothesis',
        pain_statement: document.getElementById('icp-pain').value,
        targets: {
            company_size: { min_employees: intOrNull('icp-t-company_size-min'), max_employees: intOrNull('icp-t-company_size-max') },
            industry_fit: { include: textToList('icp-t-industry_fit-include'), exclude: textToList('icp-t-industry_fit-exclude') },
            tech_stack: { tools: textToList('icp-t-tech_stack-keywords') },
            target_title: { titles: textToList('icp-t-target_title-titles') },
            geography: { countries: textToList('icp-t-geography-countries'), regions: textToList('icp-t-geography-regions') },
            trigger_signals: { signals: textToList('icp-t-trigger_signals-signals') },
        }
    };
    try {
        const result = await icpPost(payload);
        if (result.success) icpStatus('Hypothesis saved.');
        else icpStatus('Save failed: ' + (result.error || 'unknown error'), true);
    } catch (e) {
        icpStatus('Save failed: ' + e.message, true);
    }
}

/** (b) Save the full weight vector. Server validates the sum-to-100 invariant. */
async function saveIcpWeights() {
    const weights = {};
    ICP_DIMENSION_KEYS.forEach(key => {
        const el = document.getElementById('icp-w-' + key);
        weights[key] = el ? Number(el.value) || 0 : 0;
    });
    try {
        const result = await icpPost({ action: 'save_weights', weights, reason: 'manual weight edit' });
        if (result.success) {
            icpStatus('Weights saved — edited dimensions are now buyer-locked.');
            loadIcp(); // refresh lock badges + history
        } else {
            icpStatus('Save failed: ' + (result.error || 'unknown error'), true);
        }
    } catch (e) {
        icpStatus('Save failed: ' + e.message, true);
    }
}

/** (c) Add / remove anti-persona vetoes. */
async function icpAddExclusion() {
    try {
        const result = await icpPost({
            action: 'add_exclusion',
            exclusion_type: document.getElementById('icp-exc-type').value,
            value: document.getElementById('icp-exc-value').value,
            note: document.getElementById('icp-exc-note').value
        });
        if (result.success) {
            document.getElementById('icp-exc-value').value = '';
            document.getElementById('icp-exc-note').value = '';
            icpStatus('Veto added.');
            loadIcp();
        } else {
            icpStatus('Add failed: ' + (result.error || 'unknown error'), true);
        }
    } catch (e) {
        icpStatus('Add failed: ' + e.message, true);
    }
}

async function icpDeleteExclusion(id) {
    if (!confirm('Remove this veto?')) return;
    try {
        const result = await icpPost({ action: 'delete_exclusion', id });
        if (result.success) {
            icpStatus('Veto removed.');
            loadIcp();
        } else {
            icpStatus('Remove failed: ' + (result.error || 'unknown error'), true);
        }
    } catch (e) {
        icpStatus('Remove failed: ' + e.message, true);
    }
}

/** (b) Release a buyer lock so the auto-tuner may adjust the dimension again. */
async function icpUnlockDimension(key) {
    if (!confirm('Unlock "' + key + '"? The auto-tuner may change its weight again.')) return;
    try {
        const result = await icpPost({ action: 'unlock_dimension', dimension_key: key });
        if (result.success) {
            icpStatus('Dimension unlocked.');
            loadIcp();
        } else {
            icpStatus('Unlock failed: ' + (result.error || 'unknown error'), true);
        }
    } catch (e) {
        icpStatus('Unlock failed: ' + e.message, true);
    }
}

/** (d) Save qualification thresholds. */
async function saveIcpThresholds() {
    const qualify = Number(document.getElementById('icp-threshold-qualify').value);
    const review = Number(document.getElementById('icp-threshold-review').value);
    if (!(qualify >= 1 && qualify <= 100) || !(review >= 0 && review < qualify)) {
        icpStatus('Invalid thresholds: need 0 <= review < qualify <= 100.', true);
        return;
    }
    try {
        const result = await icpPost({ action: 'save_thresholds', qualify, review });
        if (result.success) icpStatus('Thresholds saved.');
        else icpStatus('Save failed: ' + (result.error || 'unknown error'), true);
    } catch (e) {
        icpStatus('Save failed: ' + e.message, true);
    }
}
