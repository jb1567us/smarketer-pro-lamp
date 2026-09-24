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

/* ─────────────────────────────────────────────────────────────
   PRE-SEND HONEST NOTICE (Fix 1: provider-first sending)
   Shown at campaign launch / manual resume: confirms which provider
   account and sender address will send, and states plainly that
   deliverability depends on the buyer's provider, account reputation,
   list quality, and DNS setup — not on this software.
───────────────────────────────────────────────────────────── */
function showSendNotice(notice) {
    if (!notice) return;
    const container = document.getElementById('modal-container');
    const cfg = notice.configured === false
        ? `<p class="text-xs text-rose-400 font-semibold bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2">⚠️ No credentials stored for this provider — sends will fail until you add them in Settings.</p>`
        : '';
    container.innerHTML = `
        <div class="glass p-8 rounded-2xl w-full max-w-lg text-left">
            <h3 class="text-xl font-bold mb-2">${escapeHtml(notice.heading || 'Before this campaign sends')}</h3>
            <div class="space-y-3">
                <div class="rounded-xl border border-white/10 bg-slate-900/60 px-4 py-3">
                    <p class="text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Sending via</p>
                    <p class="text-sm font-bold text-white">${escapeHtml(notice.provider_label || notice.provider || '')}</p>
                    <p class="text-xs text-slate-400 mt-0.5">From: ${escapeHtml(notice.sender_email || '(not configured)')}</p>
                </div>
                <p class="text-xs text-slate-300 leading-relaxed whitespace-pre-line">${escapeHtml(notice.body || '')}</p>
                ${cfg}
                <div class="flex gap-3 pt-2">
                    <button onclick="closeModal()" class="flex-1 bg-blue-600 hover:bg-blue-500 py-2.5 rounded-xl font-bold text-sm transition">Understood</button>
                </div>
            </div>
        </div>`;
    container.classList.remove('hidden');
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
            // Honest pre-send notice on launch (campaign activated, not paused).
            if (result.is_active && result.send_notice) {
                showSendNotice(result.send_notice);
            }
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

async function runTask(leadId, type) {
    try {
        const response = await fetch('api/trigger_task.php', {
            method: 'POST',
            body: JSON.stringify({ lead_id: leadId, task_type: type })
        });
        const result = await response.json();
        if (result.success) {
            alert(`Task ${type} triggered: ${result.message}`);
            fetchLeads();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (e) {
        alert('Failed to trigger task');
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
            <div class="h-4 w-px bg-white/10"></div>
            <div>Verification: <span class="text-white">${escapeHtml(verificationLabel(lead))}</span></div>
        </div>
        <div class="flex-1 overflow-y-auto p-6 space-y-6" id="drawer-traces-container">
            <div class="text-center py-12">
                <span class="text-2xl animate-spin inline-block mb-3">⌛</span>
                <p class="text-slate-400 text-sm">Loading AI Explainability Traces...</p>
            </div>
        </div>
    `;

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
            <p class="text-xs text-slate-500 mb-4 leading-relaxed">Campaigns send from your own accounts, and you are the data controller: you are liable for your own sending practices. Nothing in this app makes a campaign's sending legal — the guardrails just reduce your risk of bans and account shutdowns.</p>
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

/* FIX2: plain-language verification state for the lead drawer. Never claims
 * "verified" for a mailbox that was never actually checked. */
function verificationLabel(lead) {
    const status = (lead && lead.verification_status) ? String(lead.verification_status) : 'unknown';
    const at = lead && lead.verified_at ? ` (${lead.verified_at})` : '';
    switch (status) {
        case 'valid':   return `Valid — checked${at}`;
        case 'invalid': return `Invalid — checked${at}`;
        case 'risky':   return `Risky — checked${at}`;
        default:        return 'Not verified';
    }
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

async function sendDraftEmail(leadId, btn) {
    const subject = document.getElementById('draft-subject').value;
    const body = document.getElementById('draft-body').value;
    const originalText = btn.innerText;

    btn.disabled = true;
    btn.innerText = "Sending...";

    try {
        const response = await fetch('api/send_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, subject, body })
        });
        const result = await response.json();

        if (result.success) {
            toast('Email sent! 🚀');
            closeModal();
            fetchLeads();
            fetchStats();
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
            <p class="text-[10px] text-slate-500 mt-4 leading-relaxed">⚠️ Importing a list doesn't make it safe to mail: you are the data controller for these addresses and you are liable for how you obtained and use them. The built-in guardrails (suppression list, email verification gate, DNS preflight, rate monitors) protect your provider accounts from bans — they do not make your sending legal.</p>
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
            alert(`Successfully imported ${result.count} leads (unverified).`);
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
            const total = (s.funnel_harvested ?? s.total_leads ?? 0) || 1;
            document.getElementById('conv-qualified').innerText = `${((s.qualified || 0) / total * 100).toFixed(1)}% of harvested`;
            document.getElementById('conv-contacted').innerText = `${((s.contacted || 0) / total * 100).toFixed(1)}% of harvested`;
            document.getElementById('conv-converted').innerText = `${((s.converted || 0) / total * 100).toFixed(1)}% of harvested`;

            // FIX2: honest verification-funnel detail line. Mailable means
            // verified-valid AND not suppressed — NOT "ready to send".
            // The invalid rate is computed only over addresses that were
            // actually checked; 'unknown' rows were never verified.
            const fChecked = s.funnel_checked ?? 0;
            const fInvalid = s.funnel_invalid ?? 0;
            const invalidPct = fChecked > 0 ? ((fInvalid / fChecked) * 100).toFixed(1) : '0.0';
            const funnelDetail = document.getElementById('funnel-detail');
            if (funnelDetail) {
                funnelDetail.innerText =
                    `${(s.funnel_harvested ?? 0).toLocaleString()} harvested · ` +
                    `${(s.funnel_verified_valid ?? 0).toLocaleString()} verified valid · ` +
                    `${invalidPct}% invalid of ${fChecked.toLocaleString()} checked · ` +
                    `${(s.funnel_suppressed ?? 0).toLocaleString()} suppressed`;
            }

            // Compliance item 4: visible notice for auto-paused campaigns.
            renderPauseBanner(s.paused_campaigns || []);
        }
    } catch (e) {
        console.warn('Failed to fetch stats');
    }
}

/* Auto-pause notice: the send monitor paused campaigns to protect the
 * buyer's provider account. Called from fetchStats() with api/stats.php's
 * paused_campaigns list. */
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
                Paused to protect your provider account from suspension: bounce or complaint rate crossed the safety threshold, and providers suspend accounts over exactly these signals. Find the cause before resuming — a bad list segment (check bounce/complaint entries in your email logs), failing sender authentication (run the DNS preflight when you restart the campaign), or a damaged sending domain. Resuming without fixing the cause burns more reputation.
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
            if (result.send_notice) {
                showSendNotice(result.send_notice);
            }
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
                    ${campaignBlockedHtml(c)}
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
    // SMTP-group providers: mail leaves the shared host over SMTP. Kept in
    // sync with \App\SendNotice::SMTP_PROVIDERS (+ future *_smtp keys).
    const smtpProviders = ['smtp', 'custom_smtp', 'amazon_ses', 'sendpulse', 'zoho_smtp', 'netcore_smtp'];
    const isSmtpProvider = smtpProviders.includes(activeEmail) || activeEmail.endsWith('_smtp');
    const smtpGroup = document.getElementById('email-group-smtp');
    if (smtpGroup) {
        if (isSmtpProvider) {
            smtpGroup.classList.remove('hidden');
        } else {
            smtpGroup.classList.add('hidden');
        }
    }
    
    // Also toggle fields for other email APIs
    document.querySelectorAll('.email-provider-fields').forEach(el => el.classList.add('hidden'));
    if (!isSmtpProvider) {
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
    const smtpProviders = ['smtp', 'custom_smtp', 'amazon_ses', 'sendpulse', 'zoho_smtp', 'netcore_smtp'];
    let emailConnected = false;
    if (smtpProviders.includes(activeEmail) || activeEmail.endsWith('_smtp')) {
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
            // License status panel
            refreshLicenseStatus();
        }
    } catch (e) {
        console.warn('Failed to fetch settings');
    }
}

// ── License (soft phone-home lock) ─────────────────────────────────────
async function refreshLicenseStatus() {
    const box = document.getElementById('license-status');
    if (!box) return;
    try {
        const r = await fetch('api/license.php?action=status');
        const j = await r.json();
        if (!j.success) { box.textContent = 'Could not load license status.'; return; }
        const d = j.data;
        const colors = { 'Licensed': 'text-emerald-400', 'Revoked': 'text-rose-400', 'Unlicensed': 'text-slate-400', 'Key issue': 'text-amber-400', 'Server unreachable': 'text-amber-400', 'Unknown': 'text-slate-500' };
        const color = colors[d.label] || 'text-slate-400';
        let html = `<span class="font-bold ${color}">${escapeHtml(d.label)}</span>`;
        if (d.detail) html += ` <span class="text-slate-500">— ${escapeHtml(d.detail)}</span>`;
        const bits = [];
        if (d.domain) bits.push('domain: ' + d.domain);
        if (d.max_domains > 0) bits.push(`${d.domains.length}/${d.max_domains} slots used`);
        if (d.checked_at) bits.push('checked ' + new Date(d.checked_at * 1000).toLocaleString());
        if (d.grace_expired) bits.push('<span class="text-amber-400 font-bold">grace period expired — still fully working</span>');
        if (!d.sending_allowed) bits.push('<span class="text-rose-400 font-bold">sending paused (revoked)</span>');
        if (bits.length) html += `<div class="text-[10px] text-slate-500 mt-1">${bits.join(' · ')}</div>`;
        box.innerHTML = html;
    } catch (e) {
        box.textContent = 'Could not load license status.';
    }
}

async function licenseAction(action) {
    const box = document.getElementById('license-status');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    try {
        if (box) box.textContent = 'Working…';
        const body = new URLSearchParams();
        body.append('action', action);
        body.append('csrf_token', csrfToken);
        if (action === 'register') {
            const keyEl = document.getElementById('setting-license_key');
            const key = (keyEl?.value || '').trim();
            if (!key) { alert('Enter a license key first (and Save).'); if (box) refreshLicenseStatus(); return; }
            body.append('key', key);
        }
        if (action === 'release' && !confirm('Release this domain\u2019s license slot? You can re-register afterwards.')) {
            refreshLicenseStatus(); return;
        }
        const r = await fetch('api/license.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrfToken },
            body
        });
        const j = await r.json();
        if (j.success) {
            if (j.message) alert(j.message);
            else if (j.detail) alert(j.detail);
        } else {
            alert('Error: ' + (j.error || j.message || 'unknown'));
        }
    } catch (e) {
        alert('License action failed — please try again.');
    }
    refreshLicenseStatus();
    if (typeof fetchSettings === 'function') fetchSettings();
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
// ── /License ─────────────────────────────────────────────────────────────

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
        'ses_region',
        'proxy_enabled', 'proxy_socks_url', 'proxy_verify_url',
        'license_server_url', 'license_key',
        // Email verification (MillionVerifier send gate + bulk verify)
        'verification_required', 'verification_api_key', 'verification_risky_action',
        'verification_strict', 'verification_cache_days',
        'verification_bulk_batch_size', 'verification_bulk_delay_ms',
        'verification_bulk_max_unknown_streak'
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

/* ── ITEM 1: MillionVerifier connection test ──────────────────────────────
 * Posts the (possibly unsaved) key from the settings field to
 * api/verification_test.php. The server tests the key against
 * MillionVerifier's credits endpoint — no verification credit is spent and
 * the key is never echoed back in the response. */
async function testVerificationConnection() {
    const output = document.getElementById('verification-test-output');
    const keyEl = document.getElementById('setting-verification_api_key');
    if (!output) return;
    output.classList.remove('hidden');
    output.innerHTML = '<div class="p-4 rounded-xl border border-blue-500/20 bg-blue-500/5 text-xs text-slate-300 flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-blue-500 animate-ping"></span>Testing MillionVerifier connection…</div>';

    const body = {};
    const typedKey = keyEl ? keyEl.value.trim() : '';
    if (typedKey) body.api_key = typedKey; // test-before-save; never persisted

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const response = await fetch('api/verification_test.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify(body)
        });
        const result = await response.json();
        const ok = !!result.success;
        const msg = escapeHtml(result.message || result.error || 'Unknown response.');
        output.innerHTML = `
            <div class="p-4 rounded-xl border ${ok ? 'border-emerald-500/20 bg-emerald-500/5' : 'border-rose-500/20 bg-rose-500/5'} flex items-start gap-2.5">
                <span class="text-base leading-none">${ok ? '✅' : '❌'}</span>
                <div>
                    <div class="font-bold text-xs ${ok ? 'text-emerald-300' : 'text-rose-300'}">${ok ? 'Connection OK' : 'Connection Failed'}</div>
                    <div class="text-[10px] text-slate-400 mt-0.5">${msg}</div>
                    ${ok ? '<div class="text-[9px] text-slate-500 mt-1">Reminder: verification reduces bounces; it does not guarantee deliverability or inbox placement.</div>' : ''}
                </div>
            </div>`;
    } catch (e) {
        output.innerHTML = '<div class="p-4 rounded-xl border border-rose-500/20 bg-rose-500/5 text-xs text-rose-300">❌ Request failed: ' + escapeHtml(e.message) + '</div>';
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


/* ── ITEM2: Bulk email verification ─────────────────────────────────────
 * "Verify leads" enqueues ONE task; the cron queue worker verifies in
 * batches. The panel below polls api/leads.php?action=verify_status.
 */
let bulkVerifyTimer = null;
let bulkVerifyTaskId = null;

async function bulkVerifyOpen() {
    const selected = getSelectedIds();
    let unchecked = 0;
    try {
        const r = await fetch('api/leads.php?action=verify_count&mode=unchecked');
        const j = await r.json();
        if (j.success) unchecked = j.data.count;
    } catch (e) { /* offline: fall through with 0 */ }

    const creditNote = '\n\nEach lookup uses about one of YOUR MillionVerifier credits. ' +
        'The job runs in the background via the queue worker - watch progress here, cancel any time. ' +
        "Invalid addresses are marked invalid (unmailable). 'Risky' and 'unknown' are NEVER marked valid.";

    if (selected.length) {
        if (confirm('Verify the ' + selected.length + ' selected lead(s)? (~' + selected.length + ' credit(s).)' + creditNote)) {
            bulkVerifyStart('selected', selected);
            return;
        }
        // Declined selected: offer all-unchecked as the alternative.
        if (unchecked > 0 && confirm('Verify ALL ' + unchecked + ' unchecked lead(s) instead? (~' + unchecked + ' credit(s).)' + creditNote)) {
            bulkVerifyStart('unchecked', []);
        }
        return;
    }
    if (!unchecked) { toast('Nothing to verify: every lead already has a verdict.', 'warn'); return; }
    if (confirm('Verify all ' + unchecked + ' unchecked lead(s)? (~' + unchecked + ' credit(s).)' + creditNote)) {
        bulkVerifyStart('unchecked', []);
    }
}

async function bulkVerifyStart(mode, ids) {
    try {
        const r = await fetch('api/leads.php?action=verify_bulk', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode, ids })
        });
        const j = await r.json();
        if (!j.success) { toast(j.error || 'Could not start bulk verify', 'warn'); return; }
        toast(`Bulk verify queued: ${j.data.total} lead(s).`, 'success');
        bulkVerifyShow(j.data.task_id);
    } catch (e) {
        toast('Could not start bulk verify (network error).', 'warn');
    }
}

function bulkVerifyShow(taskId) {
    bulkVerifyTaskId = taskId;
    document.getElementById('bulk-verify-panel')?.classList.remove('hidden');
    document.getElementById('bulk-verify-cancel')?.classList.remove('hidden');
    if (bulkVerifyTimer) clearInterval(bulkVerifyTimer);
    bulkVerifyPoll();
    bulkVerifyTimer = setInterval(bulkVerifyPoll, 3000);
}

async function bulkVerifyPoll() {
    if (!bulkVerifyTaskId) return;
    try {
        const r = await fetch(`api/leads.php?action=verify_status&id=${bulkVerifyTaskId}`);
        const j = await r.json();
        if (!j.success) return;
        const d = j.data;
        const bar = document.getElementById('bulk-verify-bar');
        const counts = document.getElementById('bulk-verify-counts');
        const note = document.getElementById('bulk-verify-note');
        const state = document.getElementById('bulk-verify-state');
        if (bar) bar.style.width = `${d.percent}%`;
        if (state) state.textContent = `— ${d.state} (${d.checked}/${d.total})`;
        if (counts) counts.textContent =
            `Checked ${d.checked} of ${d.total} · valid ${d.valid} · invalid ${d.invalid} · risky ${d.risky} · unknown ${d.unknown}`;
        if (note) note.textContent = d.note || d.error || '';
        const terminal = ['Completed', 'Failed', 'Cancelled'].includes(d.state);
        if (terminal) {
            if (bulkVerifyTimer) { clearInterval(bulkVerifyTimer); bulkVerifyTimer = null; }
            document.getElementById('bulk-verify-cancel')?.classList.add('hidden');
            if (d.state === 'Completed') { toast('Bulk verify finished.', 'success'); fetchLeads(); }
            else if (d.state === 'Failed') { toast('Bulk verify failed: ' + (d.error || d.note || 'see note'), 'warn'); }
        }
    } catch (e) { /* transient: next poll retries */ }
}

async function bulkVerifyCancel() {
    if (!bulkVerifyTaskId) return;
    if (!confirm('Cancel this bulk-verify job? Leads already checked keep their verdicts; the rest stay unchecked.')) return;
    try {
        const r = await fetch(`api/leads.php?action=verify_cancel&id=${bulkVerifyTaskId}`, { method: 'POST' });
        const j = await r.json();
        if (j.success) toast('Cancellation requested.', 'success');
        else toast(j.error || 'Could not cancel', 'warn');
    } catch (e) {
        toast('Cancel failed (network error).', 'warn');
    }
    bulkVerifyPoll();
}
// ── /ITEM2 ─────────────────────────────────────────────────────────────
