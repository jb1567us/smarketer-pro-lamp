/**
 * Dashboard & API Logic
 */

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
    const name = document.getElementById('new-campaign-name').value;
    const desc = document.getElementById('new-campaign-desc').value;

    try {
        await fetch('api/campaigns.php?type=campaigns', {
            method: 'POST',
            body: JSON.stringify({ name, description: desc })
        });
        closeModal();
        fetchCampaigns();
    } catch (e) {
        alert('Failed to save campaign');
    }
}

async function fetchLeads() {
    try {
        const response = await fetch('api/leads.php?limit=10');
        const result = await response.json();

        if (result.success) {
            updateLeadsTable(result.data);
        }
    } catch (error) {
        console.error('Failed to fetch leads:', error);
    }
}

function updateLeadsTable(leads) {
    const tbody = document.getElementById('leads-body');
    if (!tbody || !leads) return;

    window.allLeads = leads; // Cache for search

    if (leads.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-10 text-center text-slate-500 italic">No leads found. Start by importing a CSV.</td></tr>';
        return;
    }

    renderTableRows(leads);
}

function renderTableRows(leads) {
    const tbody = document.getElementById('leads-body');
    tbody.innerHTML = '';
    leads.forEach(lead => {
        const tr = document.createElement('tr');
        tr.className = 'group hover:bg-white/[0.02] transition-colors';
        tr.innerHTML = `
            <td class="px-6 py-5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-slate-800 flex items-center justify-center font-bold text-slate-400 border border-white/5 group-hover:border-blue-500/50 transition">
                        ${lead.company_name.charAt(0)}
                    </div>
                    <div>
                        <div onclick="viewLead(${lead.id})" class="font-bold text-white group-hover:text-blue-400 cursor-pointer transition">${escapeHtml(lead.company_name)}</div>
                        <div class="text-[11px] text-slate-500 font-medium">${escapeHtml(lead.contact_name || 'No Key Contact')}</div>
                        ${lead.campaign_name ? `<div class="text-[9px] text-blue-400 font-bold bg-blue-500/10 border border-blue-500/20 px-1.5 py-0.5 rounded w-fit mt-1">🎯 ${escapeHtml(lead.campaign_name)}</div>` : `<div class="text-[9px] text-slate-500 italic mt-1">No Campaign</div>`}
                    </div>
                </div>
            </td>
            <td class="px-6 py-5">
                <div class="flex flex-col gap-1">
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-lg w-fit ${getStatusClass(lead.status)}">
                        ${lead.status}
                    </span>
                    <span class="text-[10px] text-slate-500 font-medium ml-1">Updated 2h ago</span>
                </div>
            </td>
            <td class="px-6 py-5">
                <div class="flex items-center gap-2">
                    <div class="flex-1 h-1.5 bg-slate-800 rounded-full overflow-hidden w-16">
                        <div class="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-500" style="width: ${lead.lead_score}%"></div>
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
                    <button onclick="editLead(${lead.id})" class="p-2 rounded-lg hover:bg-white/10 transition">⚙️</button>
                    <button onclick="deleteLead(${lead.id})" class="p-2 rounded-lg hover:bg-rose-500/10 text-rose-500/50 hover:text-rose-500 transition">🗑️</button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
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
            // Show a brief notification
            const notification = document.createElement('div');
            notification.className = 'fixed bottom-8 right-8 glass p-6 rounded-2xl border-l-4 border-emerald-500 z-[100] animate-bounce';
            notification.innerHTML = `
                <div class="font-bold text-emerald-400">Analysis Complete</div>
                <div class="text-xs text-slate-400">${result.email_found ? 'Email Extracted: ' + result.email_found : 'Intent Analyzed (No new email)'}</div>
            `;
            document.body.appendChild(notification);
            setTimeout(() => notification.remove(), 4000);
            
            fetchLeads(); // Refresh table
            fetchStats(); // Refresh stats
        } else {
            alert('Analysis failed: ' + result.error);
        }
    } catch (e) {
        alert('Failed to connect to analysis agent');
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
            alert('Email sent successfully!');
            closeModal();
            fetchLeads();
            fetchStats();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (e) {
        alert('Failed to send email.');
    } finally {
        btn.disabled = false;
        btn.innerText = originalText;
    }
}

function searchLeads() {
    const query = document.getElementById('lead-search').value.toLowerCase();
    if (!window.allLeads) return;
    
    const filtered = window.allLeads.filter(l => 
        l.company_name?.toLowerCase().includes(query) || 
        l.contact_name?.toLowerCase().includes(query) || 
        l.status?.toLowerCase().includes(query) ||
        l.email?.toLowerCase().includes(query)
    );
    renderTableRows(filtered);
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
        id: id,
        company_name: document.getElementById('edit-company').value,
        contact_name: document.getElementById('edit-contact').value,
        email: document.getElementById('edit-email').value,
        campaign_id: document.getElementById('edit-campaign').value
    };

    try {
        const res = await fetch('api/leads.php?action=update', {
            method: 'POST',
            body: JSON.stringify(data)
        });
        const result = await res.json();
        if (result.success) {
            closeModal();
            fetchLeads();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (e) {
        alert('Failed to save lead');
    }
}

async function deleteLead(id) {
    if (!confirm('Are you sure you want to delete this lead?')) return;

    try {
        const res = await fetch(`api/leads.php?action=delete&id=${id}`, { method: 'POST' });
        const result = await res.json();
        if (result.success) {
            fetchLeads();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (e) {
        alert('Failed to delete lead');
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
        }
    } catch (e) {
        console.warn('Failed to fetch stats');
    }
}

async function fetchCampaigns() {
    const tab = document.getElementById('campaigns-tab');
    try {
        const response = await fetch('api/campaigns.php?type=campaigns');
        const result = await response.json();
        if (result.success) {
            tab.innerHTML = result.data.map(c => `
                <div class="glass p-6 rounded-2xl border-t-2 border-blue-500">
                    <h3 class="text-xl font-bold mb-2">${escapeHtml(c.name)}</h3>
                    <p class="text-slate-400 text-sm mb-4">${escapeHtml(c.description || 'No description')}</p>
                    <div class="flex justify-between items-center">
                        <span class="text-xs px-2 py-1 bg-blue-900/40 text-blue-300 rounded">${c.is_active ? 'Active' : 'Paused'}</span>
                        <button onclick="viewTemplates(${c.id})" class="text-blue-400 hover:text-white transition">Manage Sequence</button>
                    </div>
                </div>
            `).join('') || '<div class="p-20 text-center text-slate-500 col-span-full">No campaigns found. Create your first sequence!</div>';
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
        <div id="template-list" class="space-y-4 mb-6">Loading steps...</div>
        <button onclick="addNewStep(${campaignId})" class="w-full border-2 border-dashed border-slate-700 py-4 rounded-xl text-slate-400 hover:border-blue-500 hover:text-blue-400 transition mb-6">+ Add Sequence Step</button>
    </div>`;
    container.classList.remove('hidden');

    try {
        const response = await fetch(`api/campaigns.php?type=templates&campaign_id=${campaignId}`);
        const result = await response.json();
        const listEl = document.getElementById('template-list');
        listEl.innerHTML = result.data.map(t => `
            <div class="bg-slate-900/50 p-4 rounded-xl border border-slate-700">
                <div class="text-xs uppercase font-bold text-blue-500 mb-2">Step ${t.step_order}</div>
                <div class="font-bold mb-1">${escapeHtml(t.subject)}</div>
                <div class="text-sm text-slate-400 truncate">${escapeHtml(t.body)}</div>
            </div>
        `).join('') || '<div class="text-center py-8 text-slate-500 italic">No steps defined for this sequence.</div>';
    } catch (e) {
        alert('Failed to load steps');
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
    const subject = document.getElementById('tpl-subject').value;
    const body = document.getElementById('tpl-body').value;

    try {
        await fetch('api/campaigns.php?type=templates', {
            method: 'POST',
            body: JSON.stringify({ campaign_id: campaignId, subject, body, step_order: 1 })
        });
        viewTemplates(campaignId);
    } catch (e) {
        alert('Failed to save step');
    }
}

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
        }
    } catch (e) {
        console.warn('Failed to fetch settings');
    }
}

async function saveSettings() {
    const keys = [
        'operational_mode', 'active_llm_provider', 'ollama_url',
        'gemini_api_key', 'openai_api_key', 'anthropic_api_key', 'groq_api_key', 'mistral_api_key', 'openrouter_api_key',
        'active_search_provider', 'fallback_search_provider', 'failover_threshold',
        'scrapingant_api_key', 'scrapingant_api_key_backup', 
        'firecrawl_api_key', 'firecrawl_api_key_backup', 
        'serper_api_key', 'tavily_api_key', 'exa_api_key', 'searxng_url', 'apify_api_token',
        'scraper_priority', 'bright_data_proxy_url', 'proxy_rotation_enabled',
        'active_email_provider', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_encryption'
    ];
    const settings = {};
    keys.forEach(k => {
        const el = document.getElementById(`setting-${k}`);
        if (el) settings[k] = el.value;
    });

    try {
        const response = await fetch('api/settings.php', {
            method: 'POST',
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

function escapeHtml(text) {
    const div = document.createElement('div');
    if (!text) return '';
    div.textContent = text;
    return div.innerHTML;
}
