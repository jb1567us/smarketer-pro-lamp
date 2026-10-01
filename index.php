<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smarketer Pro - B2B Lead Hub</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(\App\Auth::csrfToken()) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #0f172a; color: #f8fafc; }
        .glass { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.1); }
    </style>
</head>
<body class="bg-[#0b0f1a] text-slate-200 font-sans selection:bg-blue-500/30">
    <!-- Sidebar Navigation -->
    <aside class="fixed top-0 left-0 h-full w-20 lg:w-64 glass border-r border-white/5 z-50 flex flex-col items-center lg:items-start p-4 transition-all duration-300">
        <div class="mb-10 px-2 flex items-center gap-3">
            <div class="w-10 h-10 bg-gradient-to-tr from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-blue-500/20">
                <span class="text-xl">🚀</span>
            </div>
            <h1 class="text-xl font-bold hidden lg:block bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">Smarketer <span class="text-blue-500">Pro</span></h1>
        </div>

        <nav class="flex-1 w-full space-y-2">
            <!-- Pipeline -->
            <a href="index.php?tab=leads" id="tab-leads-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">📊</span>
                <span class="hidden lg:block font-medium">Deals / Pipeline</span>
            </a>
            <!-- Campaigns -->
            <a href="index.php?tab=campaigns" id="tab-campaigns-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">🎯</span>
                <span class="hidden lg:block font-medium">Outreach Campaigns</span>
            </a>
            <!-- Agent Lab -->
            <a href="index.php?tab=agent" id="tab-agent-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">🧪</span>
                <span class="hidden lg:block font-medium">AI Copywriting Lab</span>
            </a>
            <!-- Influencer Scout -->
            <a href="index.php?tab=influencer" id="tab-influencer-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">🔍</span>
                <span class="hidden lg:block font-medium">Find Influencers</span>
            </a>
            <!-- Mass Harvester -->
            <a href="index.php?tab=mass" id="tab-mass-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">🕸️</span>
                <span class="hidden lg:block font-medium">Find Prospects</span>
            </a>
            <!-- Settings -->
            <a href="index.php?tab=settings" id="tab-settings-btn" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition">⚙️</span>
                <span class="hidden lg:block font-medium">System Settings</span>
            </a>
        </nav>

        <div class="w-full pt-4 border-t border-white/5 space-y-2">
            <div id="safety-indicator" class="hidden lg:flex items-center gap-2 px-3 py-2 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-400 text-[10px] font-bold uppercase tracking-wider">
                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                Safety Active
            </div>
            <form method="POST" action="logout.php" class="w-full">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Auth::csrfToken()) ?>">
                <button type="submit" class="w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5 hover:text-slate-200">
                    <span class="text-xl group-hover:scale-110 transition">\U0001f6aa</span>
                    <span class="hidden lg:block font-medium">Log out</span>
                </button>
            </form>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="ml-20 lg:ml-64 p-4 lg:p-8 transition-all duration-300">
        <!-- Auto-pause alert banner (compliance item 4): populated by renderPauseBanner() -->
        <div id="pause-banner" class="mb-4"></div>
        <!-- Top Control Bar -->
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div>
                <h2 class="text-2xl font-bold">Sales & Outreach Dashboard</h2>
                <p class="text-slate-400 text-sm">Real-time B2B outreach and intent monitoring</p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="showAddLeadModal()" class="px-4 py-2 rounded-xl bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/20 text-emerald-400 hover:text-emerald-300 transition text-sm font-medium">+ Add Lead</button>
                <button onclick="showImportLeadsModal()" class="px-4 py-2 rounded-xl bg-slate-800 hover:bg-slate-700 transition border border-white/5 text-sm font-medium">Import CSV</button>
                <button onclick="showNewCampaignModal()" class="px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 text-sm font-bold">New Campaign</button>
            </div>
        </header>

        <!-- KPI Ribbon -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="glass p-4 rounded-2xl border-l-4 border-blue-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">Prospects Discovered</div>
                <div id="stat-total_leads" class="text-2xl font-bold">0</div>
                <div class="text-[10px] text-blue-500 mt-1 font-medium">Found in Discovery</div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-purple-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">Interested Leads</div>
                <div id="stat-qualified" class="text-2xl font-bold">0</div>
                <div id="conv-qualified" class="text-[10px] text-purple-500 mt-1 font-medium">Ready for Outreach</div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-emerald-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">Conversations In Progress</div>
                <div id="stat-contacted" class="text-2xl font-bold">0</div>
                <div id="conv-contacted" class="text-[10px] text-emerald-500 mt-1 font-medium">Active Discussions</div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-amber-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">Outreach Wins</div>
                <div id="stat-converted" class="text-2xl font-bold">0</div>
                <div id="conv-converted" class="text-[10px] text-amber-500 mt-1 font-medium">Successfully Closed</div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-rose-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1">Outreach Status</div>
                <div id="supervisor-status" class="text-lg font-bold text-emerald-400">Active & Sending</div>
                <div id="supervisor-last-check" class="text-[9px] text-slate-500 mt-1 truncate">All systems nominal</div>
            </div>
        </div>

        <!-- Dynamic Content -->
        <div id="leads-tab" class="tab-content grid grid-cols-1 xl:grid-cols-3 gap-8">
            <div class="xl:col-span-2 space-y-6">
                <!-- Advanced Search & Filter -->
                <div class="glass p-4 rounded-2xl flex flex-col md:flex-row gap-4">
                    <div class="relative flex-1">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">🔍</span>
                        <input type="text" id="lead-search" onkeyup="searchLeads()" placeholder="Search deals, company, or intent signals..." class="w-full bg-slate-900/50 border border-white/5 rounded-xl pl-10 pr-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition">
                    </div>
                    <div class="flex gap-2">
                        <select class="bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 text-sm outline-none">
                            <option>All Scores</option>
                            <option>High Score (>80)</option>
                            <option>Recent Activity</option>
                        </select>
                        <button onclick="fetchLeads()" class="p-2.5 rounded-xl bg-white/5 hover:bg-white/10 transition">🔄</button>
                    </div>
                </div>

                <!-- Leads Table -->
                <div class="glass rounded-3xl overflow-hidden border border-white/5">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-white/[0.02] text-slate-500 text-[10px] uppercase tracking-[0.2em] font-bold">
                                <th class="px-4 py-5">
                                    <input type="checkbox" id="leads-select-all" onchange="toggleSelectAll(this)" class="w-4 h-4 rounded accent-blue-500 cursor-pointer" title="Select all">
                                </th>
                                <th class="px-6 py-5">Organization & Contact</th>
                                <th class="px-6 py-5">Pipeline Phase</th>
                                <th class="px-6 py-5">Intent Score</th>
                                <th class="px-6 py-5">Quick Actions</th>
                                <th class="px-6 py-5">Edit</th>
                            </tr>
                        </thead>
                        <tbody id="leads-body" class="divide-y divide-white/5">
                            <!-- Populated via JS -->
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Side Intelligence Panel -->
            <aside class="space-y-6">
                <div class="glass p-6 rounded-3xl border border-white/5">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="font-bold">Active Signal Queue</h3>
                        <span class="px-2 py-1 rounded bg-blue-500/10 text-blue-400 text-[10px] font-bold">Live</span>
                    </div>
                    <div id="task-list" class="space-y-4 text-sm text-slate-500 italic text-center py-10">
                        <div class="flex flex-col items-center gap-3">
                            <span class="text-4xl grayscale opacity-20">📡</span>
                            <p>Monitoring for intent signals...</p>
                        </div>
                    </div>
                </div>

                <div class="bg-gradient-to-br from-indigo-600/20 to-blue-600/20 p-6 rounded-3xl border border-blue-500/20 relative overflow-hidden group">
                    <div class="absolute -right-4 -bottom-4 text-8xl opacity-10 group-hover:scale-110 transition-transform">🤖</div>
                    <h3 class="font-bold text-white mb-2">Agent lab Insights</h3>
                    <p class="text-slate-400 text-xs mb-4 leading-relaxed">Let your Extraction Expert and Intent Analyst prioritize your morning for you.</p>
                    <button onclick="location.href='agent_lab.php'" class="w-full py-2 rounded-xl bg-blue-600 text-white text-xs font-bold hover:bg-blue-500 transition">Open Lab</button>
                </div>
            </aside>
        </div>

        <!-- Other tabs... -->
        <div id="campaigns-tab" class="tab-content hidden grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"></div>
        
        <div id="agent-tab" class="tab-content hidden">
            <?php include 'agent_lab_content.php'; ?>
        </div>

        <div id="influencer-tab" class="tab-content hidden">
            <?php include 'influencer_scout_content.php'; ?>
        </div>

        <div id="mass-tab" class="tab-content hidden">
            <?php include 'mass_tools_content.php'; ?>
        </div>

        <div id="settings-tab" class="tab-content hidden glass p-8 rounded-3xl max-w-5xl mx-auto border border-white/5">
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h2 class="text-2xl font-bold bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">System & Integrations Settings</h2>
                    <p class="text-slate-500 text-sm">Configure your outreach engine nodes, API credentials, and email channels</p>
                </div>
                <button onclick="saveSettings()" class="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 text-sm font-bold">Save Changes</button>
            </div>

            <!-- Interactive Setup Wizard Guide -->
            <div class="mb-8 p-6 rounded-2xl bg-gradient-to-br from-indigo-950/60 via-slate-900/50 to-slate-900/40 border border-blue-500/30 shadow-2xl relative overflow-hidden">
                <div class="absolute top-0 right-0 w-64 h-64 bg-blue-600/5 rounded-full blur-3xl pointer-events-none"></div>
                <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 mb-6 pb-6 border-b border-white/5">
                    <div>
                        <div class="flex items-center gap-3">
                            <span class="text-2xl">🚀</span>
                            <div>
                                <h3 class="text-lg font-bold text-slate-100 bg-gradient-to-r from-white to-slate-300 bg-clip-text">Outreach Setup Wizard</h3>
                                <p class="text-slate-400 text-xs mt-0.5">Smarketer Pro dynamically tracks your credentials to ensure your AI Copywriter and Leads Harvester run seamlessly.</p>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2 text-[10px] font-bold uppercase tracking-wider">
                        <span id="setup-check-llm" class="px-2.5 py-1 rounded bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> AI Brain: Unset
                        </span>
                        <span id="setup-check-search" class="px-2.5 py-1 rounded bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Search Engine: Unset
                        </span>
                        <span id="setup-check-email" class="px-2.5 py-1 rounded bg-rose-500/10 border border-rose-500/20 text-rose-400 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span> Outreach: Unset
                        </span>
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <!-- Step 1: AI Brain -->
                    <div class="glass p-4 rounded-xl border border-white/5 hover:border-blue-500/30 transition flex flex-col justify-between">
                        <div>
                            <div class="flex justify-between items-start mb-2">
                                <span class="px-2.5 py-0.5 rounded-full text-[9px] font-bold bg-blue-500/10 text-blue-400 border border-blue-500/20 uppercase tracking-widest">Step 1</span>
                                <span class="text-xs">🤖</span>
                            </div>
                            <h4 class="font-bold text-xs text-slate-200">Connect AI Brain</h4>
                            <p class="text-[10px] text-slate-400 mt-1 leading-relaxed">Required for AI copywriting, qualifying intent, and analyzing responses.</p>
                        </div>
                        <div class="mt-4 flex items-center justify-between">
                            <a href="https://aistudio.google.com/" target="_blank" class="text-[10px] text-blue-400 hover:text-blue-300 flex items-center gap-0.5 font-semibold">
                                Get Gemini Key ↗
                            </a>
                            <button type="button" onclick="focusSettingField('setting-gemini_api_key')" class="px-2.5 py-1 rounded bg-blue-600 hover:bg-blue-500 text-[10px] font-bold text-white transition">Configure</button>
                        </div>
                    </div>

                    <!-- Step 2: Search Power -->
                    <div class="glass p-4 rounded-xl border border-white/5 hover:border-indigo-500/30 transition flex flex-col justify-between">
                        <div>
                            <div class="flex justify-between items-start mb-2">
                                <span class="px-2.5 py-0.5 rounded-full text-[9px] font-bold bg-indigo-500/10 text-indigo-400 border border-indigo-500/20 uppercase tracking-widest">Step 2</span>
                                <span class="text-xs">🕸️</span>
                            </div>
                            <h4 class="font-bold text-xs text-slate-200">Connect Prospect Search</h4>
                            <p class="text-[10px] text-slate-400 mt-1 leading-relaxed">Enables high-fidelity lead extraction, crawlers, and intent scouts.</p>
                        </div>
                        <div class="mt-4 flex items-center justify-between">
                            <a href="https://dashboard.tavily.com/" target="_blank" class="text-[10px] text-indigo-400 hover:text-indigo-300 flex items-center gap-0.5 font-semibold">
                                Get Tavily Key ↗
                            </a>
                            <button type="button" onclick="focusSettingField('setting-tavily_api_key')" class="px-2.5 py-1 rounded bg-indigo-600 hover:bg-indigo-500 text-[10px] font-bold text-white transition">Configure</button>
                        </div>
                    </div>

                    <!-- Step 3: Outbound SMTP -->
                    <div class="glass p-4 rounded-xl border border-white/5 hover:border-emerald-500/30 transition flex flex-col justify-between">
                        <div>
                            <div class="flex justify-between items-start mb-2">
                                <span class="px-2.5 py-0.5 rounded-full text-[9px] font-bold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 uppercase tracking-widest">Step 3</span>
                                <span class="text-xs">✉️</span>
                            </div>
                            <h4 class="font-bold text-xs text-slate-200">Set Up Email Sender</h4>
                            <p class="text-[10px] text-slate-400 mt-1 leading-relaxed">Standard business SMTP credentials to run high-volume outreach sequences.</p>
                        </div>
                        <div class="mt-4 flex items-center justify-between">
                            <span class="text-[10px] text-emerald-400 font-semibold flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span> Active
                            </span>
                            <button type="button" onclick="focusSettingField('setting-active_email_provider')" class="px-2.5 py-1 rounded bg-emerald-600 hover:bg-emerald-500 text-[10px] font-bold text-white transition">Configure</button>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Two-Tier Configuration Settings -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Column 1: Core AI Brain & Prospect Discovery Search (Tier 1) -->
                <div class="space-y-6">
                    <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6">
                        <div class="flex items-center gap-2 mb-2 pb-3 border-b border-white/5">
                            <span class="text-blue-500 text-lg">🤖</span>
                            <div>
                                <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">AI Brain Node</h3>
                                <p class="text-[10px] text-slate-500 mt-0.5">Primary intelligence engine for email drafting & personalization</p>
                            </div>
                        </div>

                        <!-- Active LLM select -->
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Active LLM Provider</label>
                            <select id="setting-active_llm_provider" onchange="toggleActiveProviderFields()" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                <option value="gemini">Google Gemini AI (Recommended)</option>
                                <option value="groq">Groq (Ultra High Speed)</option>
                                <option value="openrouter">OpenRouter (API Gateway)</option>
                                <option value="ollama">Ollama (Local Self-Hosted)</option>
                            </select>
                        </div>

                        <!-- Gemini Key in Tier 1 -->
                        <div id="provider-group-gemini" class="llm-provider-fields space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Gemini API Key</label>
                                <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Gemini Key ↗</a>
                            </div>
                            <input type="password" id="setting-gemini_api_key" placeholder="AIzaSy..." class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>
                    </div>

                    <!-- Jev Decision Tier -->
                    <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6">
                        <div class="flex items-center gap-2 mb-2 pb-3 border-b border-white/5">
                            <span class="text-violet-500 text-lg">⚡</span>
                            <div>
                                <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Decision Tier (Jev)</h3>
                                <p class="text-[10px] text-slate-500 mt-0.5">Fast, cheap yes/no &amp; scoring decisions for lead qualification</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Jev Enabled</label>
                                <select id="setting-jev_enabled" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                    <option value="0">Disabled (LLM only)</option>
                                    <option value="1">Enabled</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Mode</label>
                                <select id="setting-jev_mode" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                    <option value="shadow">Shadow (log only, zero behavior change)</option>
                                    <option value="live">Live (Jev decides, LLM fallback)</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">TypeSafe API Key</label>
                                <a href="https://console.typesafe.ai" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Jev Key ↗</a>
                            </div>
                            <input type="password" id="setting-jev_api_key" placeholder="ts_..." class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                            <p class="text-[9px] text-slate-500">Or set the TYPESAFE_API_KEY environment variable. $0.042 / 1M input tokens, output free.</p>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="space-y-1.5">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Model</label>
                                <input type="text" id="setting-jev_model" placeholder="jev-latest" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Min Confidence (live mode)</label>
                                <input type="text" id="setting-jev_min_confidence" placeholder="0.65" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                            </div>
                        </div>
                    </div>

                    <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6">
                        <div class="flex items-center gap-2 mb-2 pb-3 border-b border-white/5">
                            <span class="text-indigo-500 text-lg">🔍</span>
                            <div>
                                <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Prospect Search & OSINT Engine</h3>
                                <p class="text-[10px] text-slate-500 mt-0.5">Primary intelligence feeds for extracting B2B target intelligence</p>
                            </div>
                        </div>

                        <!-- Active Search select -->
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Active Search Engine</label>
                            <select id="setting-active_search_provider" onchange="toggleActiveProviderFields()" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                <option value="tavily">Tavily Search API (Optimized)</option>
                                <option value="exa">Exa Neural Search (Semantic)</option>
                                <option value="ddg">DuckDuckGo (Free / Basic)</option>
                                <option value="searxng">SearXNG (Internal Rotation)</option>
                                <option value="firecrawl">Firecrawl Search (Deep Scraper)</option>
                                <option value="scrapingant">ScrapingAnt (Proxied Scraper)</option>
                                <option value="serper">Serper Google API (High Accuracy)</option>
                            </select>
                        </div>

                        <!-- Tavily in Tier 1 -->
                        <div id="provider-group-tavily" class="search-provider-fields space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Tavily API Key</label>
                                <a href="https://tavily.com/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Tavily Key ↗</a>
                            </div>
                            <input type="password" id="setting-tavily_api_key" placeholder="tvly-..." class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- Exa in Tier 1 -->
                        <div id="provider-group-exa" class="search-provider-fields space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Exa API Key</label>
                                <a href="https://exa.ai/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Exa Key ↗</a>
                            </div>
                            <input type="password" id="setting-exa_api_key" placeholder="••••••••" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>
                    </div>
                </div>

                <!-- Column 2: Outreach Channels & Email Delivery Hub (Tier 1) -->
                <div class="space-y-6">
                    <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6">
                        <div class="flex items-center gap-2 mb-2 pb-3 border-b border-white/5">
                            <span class="text-emerald-500 text-lg">📬</span>
                            <div>
                                <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Core Outreach Gateways</h3>
                                <p class="text-[10px] text-slate-500 mt-0.5">Target sending accounts and active outreach delivery channels</p>
                            </div>
                        </div>

                        <!-- Target Sender Email -->
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Authorized Sender Address</label>
                            <input type="email" id="setting-email_sender" placeholder="hello@yourdomain.com" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                            <p class="text-[9px] text-slate-500">The "From" header address that must match your authenticated delivery domain</p>
                        </div>

                        <!-- Sender Identity (legal requirement) -->
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Company Legal Name <span class="text-rose-400">* required for sending</span></label>
                            <input type="text" id="setting-company_legal_name" placeholder="Acme Corp LLC" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                            <p class="text-[9px] text-slate-500">Your registered business name. Appears in the footer of every email (CAN-SPAM requirement).</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Physical Postal Address <span class="text-rose-400">* required for sending</span></label>
                            <textarea id="setting-physical_address" rows="2" placeholder="123 Main St, Austin, TX 78701" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition"></textarea>
                            <p class="text-[9px] text-slate-500">A valid physical address. PO boxes registered to you are acceptable. Sending is blocked until this is set.</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Public App URL</label>
                            <input type="text" id="setting-app_base_url" placeholder="https://example.com/b2b_outreach_lamp" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                            <p class="text-[9px] text-slate-500">Base URL of this app, used to build one-click unsubscribe links. Without it, recipients get a mailto fallback.</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">CASL Country Gate (master)</label>
                            <select id="setting-compliance_casl_ca_block" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                <option value="1">On — block unconsented CA / unknown-country sends (recommended)</option>
                                <option value="0">Off — I accept the legal risk</option>
                            </select>
                            <p class="text-[9px] text-slate-500">Master switch for the CASL country gate. It keys off each lead's recorded country (not the .ca domain). Turning it off is logged as your decision.</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Unknown-Country CASL Handling</label>
                            <select id="setting-compliance_casl_unknown_country" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                <option value="block">Block unknown-country leads without express consent (recommended)</option>
                                <option value="allow">Allow — I accept the legal risk</option>
                            </select>
                            <p class="text-[9px] text-slate-500">Safe default for leads with no recorded country. Leads with express consent are never blocked by an unknown country.</p>
                        </div>

                        <!-- Email Verification gate (MillionVerifier) -->
                        <div class="space-y-1.5 pt-2 border-t border-white/5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">✉️ Email Verification <span class="text-slate-600 normal-case font-medium">— off by default</span></label>
                            <p class="text-[9px] text-slate-500">When enabled, every send is checked against MillionVerifier first. Risky addresses are blocked (or flagged) before they can hurt your sender reputation. Uses your key and your credits.</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Verification Gate</label>
                            <select id="setting-verification_required" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                <option value="0">Off (default)</option>
                                <option value="1">On — verify every recipient before sending</option>
                            </select>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">MillionVerifier API Key</label>
                                <a href="https://www.millionverifier.com/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Key ↗</a>
                            </div>
                            <input type="password" id="setting-verification_api_key" placeholder="••••••••" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                            <p class="text-[9px] text-slate-500">Pay-as-you-go credits, never expire. With no key the gate stays dormant even when enabled.</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="space-y-1.5">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Risky Addresses</label>
                                <select id="setting-verification_risky_action" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                    <option value="block">Block send (recommended)</option>
                                    <option value="flag">Flag but allow</option>
                                </select>
                            </div>
                            <div class="space-y-1.5">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">"Unknown" Verdict</label>
                                <select id="setting-verification_strict" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                    <option value="0">Fail open — allow</option>
                                    <option value="1">Strict — block</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Verdict Cache (days)</label>
                            <input type="number" min="0" max="365" id="setting-verification_cache_days" placeholder="30" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                            <p class="text-[9px] text-slate-500">Reuse a cached verdict for this many days instead of spending another credit. 0 = always re-verify.</p>
                        </div>

                        <!-- Active Email Provider selector -->
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Active Outreach Method</label>
                            <select id="setting-active_email_provider" onchange="toggleActiveProviderFields()" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                <option value="smtp">Standard Business Email (SMTP Outbound)</option>
                                <option value="resend">Resend HTTP API (Recommended)</option>
                                <option value="brevo">Brevo HTTP API</option>
                                <option value="sendgrid">SendGrid REST API</option>
                                <option value="mailgun">Mailgun REST API</option>
                                <option value="mailjet">Mailjet REST API</option>
                                <option value="postmark">Postmark REST API</option>
                                <option value="mailersend">MailerSend REST API</option>
                                <option value="mailtrap">Mailtrap Sandbox API</option>
                                <option value="zoho">ZeptoMail (Zoho API)</option>
                                <option value="netcore">Pepipost (Netcore API)</option>
                            </select>
                        </div>

                        <!-- Resend in Tier 1 -->
                        <div id="email-group-resend" class="email-provider-fields space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Resend API Key</label>
                                <a href="https://resend.com/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Resend Key ↗</a>
                            </div>
                            <input type="password" id="setting-resend_api_key" placeholder="re_..." class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- Brevo in Tier 1 -->
                        <div id="email-group-brevo" class="email-provider-fields space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Brevo API Key</label>
                                <a href="https://brevo.com/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Brevo Key ↗</a>
                            </div>
                            <input type="password" id="setting-brevo_api_key" placeholder="xkeysib-..." class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- SendGrid in Tier 1 -->
                        <div id="email-group-sendgrid" class="email-provider-fields hidden space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">SendGrid API Key</label>
                                <a href="https://app.sendgrid.com/settings/api_keys" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get SendGrid Key ↗</a>
                            </div>
                            <input type="password" id="setting-sendgrid_api_key" placeholder="SG...." class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- Mailgun in Tier 1 -->
                        <div id="email-group-mailgun" class="email-provider-fields hidden space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Mailgun API Key</label>
                                <a href="https://app.mailgun.com/settings/api_keys" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Mailgun Key ↗</a>
                            </div>
                            <input type="password" id="setting-mailgun_api_key" placeholder="key-..." class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- Mailjet in Tier 1 -->
                        <div id="email-group-mailjet" class="email-provider-fields hidden space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Mailjet API Key</label>
                                <a href="https://app.mailjet.com/account/api_keys" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Mailjet Key ↗</a>
                            </div>
                            <input type="password" id="setting-mailjet_api_key" placeholder="Secret Key" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- Postmark in Tier 1 -->
                        <div id="email-group-postmark" class="email-provider-fields hidden space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Postmark Server Token</label>
                                <a href="https://account.postmarkapp.com/servers" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Postmark Token ↗</a>
                            </div>
                            <input type="password" id="setting-postmark_api_key" placeholder="Server Token" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- MailerSend in Tier 1 -->
                        <div id="email-group-mailersend" class="email-provider-fields hidden space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">MailerSend API Key</label>
                                <a href="https://app.mailersend.com/api-tokens" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get MailerSend Key ↗</a>
                            </div>
                            <input type="password" id="setting-mailersend_api_key" placeholder="mlsn.templates. ..." class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- Mailtrap in Tier 1 -->
                        <div id="email-group-mailtrap" class="email-provider-fields hidden space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Mailtrap API Token</label>
                                <a href="https://mailtrap.io/signin" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Mailtrap Token ↗</a>
                            </div>
                            <input type="password" id="setting-mailtrap_api_key" placeholder="Token" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- ZeptoMail in Tier 1 -->
                        <div id="email-group-zoho" class="email-provider-fields hidden space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">ZeptoMail (Zoho) API Key</label>
                                <a href="https://zeptomail.zoho.com/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get ZeptoMail Key ↗</a>
                            </div>
                            <input type="password" id="setting-zoho_api_key" placeholder="SendMail Token" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- Pepipost in Tier 1 -->
                        <div id="email-group-netcore" class="email-provider-fields hidden space-y-1.5">
                            <div class="flex justify-between items-center">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Pepipost (Netcore) API Key</label>
                                <a href="https://app.pepipost.com/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 font-bold uppercase flex items-center gap-0.5">🔑 Get Pepipost Key ↗</a>
                            </div>
                            <input type="password" id="setting-netcore_api_key" placeholder="API Key" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>

                        <!-- Standard SMTP in Tier 1 -->
                        <div id="email-group-smtp" class="email-provider-fields space-y-4">
                            <div class="grid grid-cols-3 gap-3">
                                <div class="col-span-2 space-y-1.5">
                                    <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">SMTP Host</label>
                                    <input type="text" id="setting-smtp_host" placeholder="smtp.mailgun.org" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">SMTP Port</label>
                                    <input type="text" id="setting-smtp_port" placeholder="587" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div class="space-y-1.5">
                                    <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">SMTP Username</label>
                                    <input type="text" id="setting-smtp_user" placeholder="postmaster@yourdomain.com" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                </div>
                                <div class="space-y-1.5">
                                    <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">SMTP Password</label>
                                    <input type="password" id="setting-smtp_pass" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                </div>
                            </div>

                            <div class="space-y-1.5">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">SMTP Encryption</label>
                                <select id="setting-smtp_encryption" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                    <option value="tls">TLS (Port 587)</option>
                                    <option value="ssl">SSL (Port 465)</option>
                                    <option value="none">None (Port 25)</option>
                                </select>
                            </div>

                            <!-- Outbound SMTP Socket Connection Diagnostics -->
                            <div class="pt-2">
                                <button type="button" onclick="runSmtpDiagnostics()" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 transition border border-white/10 text-xs font-bold text-white transition shadow-lg">
                                    <span>⚡ Outbound SMTP Connection Check (Verify Ports)</span>
                                </button>
                                <div id="smtp-diagnostics-output" class="hidden mt-3 bg-slate-950 border border-white/5 rounded-2xl p-4">
                                    <!-- Human-Readable Diagnostics Summary -->
                                    <div id="smtp-diagnostics-summary" class="mb-3 p-3.5 rounded-xl border border-white/5 bg-slate-900/40 flex items-start gap-3">
                                        <!-- Will be dynamically populated via JS -->
                                    </div>
                                    
                                    <details class="group mt-2">
                                        <summary class="list-none flex items-center justify-between text-[10px] font-bold uppercase tracking-wider text-slate-400 cursor-pointer select-none">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-[8px] transition-transform duration-200 group-open:rotate-90">▶</span>
                                                <span>🔍 View Technical Handshake Logs</span>
                                            </div>
                                            <button type="button" onclick="document.getElementById('smtp-diagnostics-output').classList.add('hidden')" class="text-[10px] text-slate-500 hover:text-slate-300 font-bold uppercase">Close</button>
                                        </summary>
                                        <pre id="smtp-diagnostics-logs" class="mt-3 text-[10px] font-mono text-slate-300 bg-black/40 rounded-lg p-3 max-h-[220px] overflow-y-auto whitespace-pre-wrap text-left leading-relaxed border border-white/5"></pre>
                                    </details>
                                </div>
                            </div>
                        <!-- Collapsible Advanced Developer Connections Accordion -->
                        <div class="mt-6 border border-white/5 rounded-2xl bg-slate-950/20 overflow-hidden">
                            <details class="group">
                                <summary class="flex justify-between items-center px-5 py-3.5 bg-white/[0.01] hover:bg-white/[0.03] transition cursor-pointer select-none">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs">🔑</span>
                                        <div>
                                            <span class="text-xs font-bold text-slate-300">Advanced API Credentials & Passwords</span>
                                            <p class="text-[9px] text-slate-500 mt-0.5">Store backup keys and specialized SMTP credentials securely</p>
                                        </div>
                                    </div>
                                    <span class="text-slate-400 text-xs transition-transform duration-300 group-open:rotate-180">▼</span>
                                </summary>
                                <div class="p-5 border-t border-white/5 space-y-4 bg-slate-900/10">
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">SendPulse SMTP Pass</label>
                                            <a href="https://login.sendpulse.com/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-semibold">Get Pass ↗</a>
                                        </div>
                                        <input type="password" id="setting-sendpulse_smtp_pass" placeholder="SendPulse Password" class="w-full bg-slate-900/60 border border-white/5 rounded-lg px-3 py-2 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Amazon SES SMTP Pass</label>
                                            <a href="https://console.aws.amazon.com/ses/home" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-semibold">Get Pass ↗</a>
                                        </div>
                                        <input type="password" id="setting-amazon_ses_smtp_pass" placeholder="SES Password" class="w-full bg-slate-900/60 border border-white/5 rounded-lg px-3 py-2 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Zoho SMTP Pass</label>
                                            <a href="https://accounts.zoho.com/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-semibold">Get Pass ↗</a>
                                        </div>
                                        <input type="password" id="setting-zoho_smtp_pass" placeholder="Zoho SMTP Password" class="w-full bg-slate-900/60 border border-white/5 rounded-lg px-3 py-2 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-1">
                                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Netcore SMTP Pass</label>
                                            <a href="https://netcorecloud.com/" target="_blank" class="text-[9px] text-blue-400 hover:text-blue-300 transition flex items-center gap-0.5 font-semibold">Get Pass ↗</a>
                                        </div>
                                        <input type="password" id="setting-netcore_smtp_pass" placeholder="Netcore SMTP Password" class="w-full bg-slate-900/60 border border-white/5 rounded-lg px-3 py-2 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                    </div>
                                </div>
                            </details>
                        </div>
                    </div>

                    <!-- Advanced Network & Proxy Settings Card -->
                    <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6">
                        <div class="flex items-center gap-2 mb-2 pb-3 border-b border-white/5">
                            <span class="text-indigo-500 text-lg">🌐</span>
                            <div>
                                <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Advanced Network & Proxy Fleet</h3>
                                <p class="text-[10px] text-slate-500 mt-0.5">Configure outbound proxy servers and rotation rules</p>
                            </div>
                        </div>

                        <!-- Proxy Enabled Switch -->
                        <div class="flex items-center justify-between p-4 bg-slate-900/40 rounded-xl border border-white/5">
                            <div>
                                <label class="block text-slate-300 text-xs font-bold mb-0.5">Enable Outbound Proxies</label>
                                <span class="text-slate-500 text-[10px]">Route automated harvesting requests through a proxy network.</span>
                            </div>
                            <select id="setting-proxy_enabled" class="bg-slate-900 border border-white/10 rounded-lg px-3 py-1.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                <option value="false">Disabled</option>
                                <option value="true">Enabled</option>
                            </select>
                        </div>

                        <!-- Proxy Config Fields -->
                        <div class="space-y-4">
                            <div class="space-y-1.5">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Proxy Socks URL / Registry</label>
                                <input type="text" id="setting-proxy_socks_url" placeholder="https://raw.githubusercontent.com/.../http.txt" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                <p class="text-[9px] text-slate-500">API or URL endpoint returning a list of active proxy strings.</p>
                            </div>

                            <div class="space-y-1.5">
                                <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Proxy Verification Target</label>
                                <input type="text" id="setting-proxy_verify_url" placeholder="http://httpbin.org/ip" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                <p class="text-[9px] text-slate-500">Endpoint used to verify connectivity and validate proxy rotation.</p>
                            </div>
                        </div>
                    </div>

                <!-- Global Logic -->
                <div class="space-y-6">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-amber-500">🛡️</span>
                        <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Operational Integrity</h3>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Operational Mode</label>
                            <select id="setting-operational_mode" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm appearance-none">
                                <option value="Safety">Safety First (Simulated)</option>
                                <option value="Production">Full Production (Live Outreach)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Scraper Fallback Order</label>
                            <input type="text" id="setting-scraper_priority" placeholder="firecrawl,exa,tavily,searxng" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition text-sm">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ideal Customer Profile -->
            <div class="mt-12 pt-10 border-t border-white/5 space-y-8">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-emerald-400 text-lg">🎯</span>
                        <div>
                            <h3 class="text-sm font-bold tracking-wider text-slate-300 uppercase">Ideal Customer Profile</h3>
                            <p class="text-[11px] text-slate-500">Weighted scoring model: who qualifies, who gets human review, who is vetoed outright</p>
                        </div>
                    </div>
                    <span id="icp-profile-name" class="px-3 py-1 rounded-full text-[10px] font-bold bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 uppercase tracking-wider">—</span>
                </div>
                <p id="icp-status" class="text-[11px] text-slate-500 hidden"></p>

                <!-- (a) Day-zero founder hypothesis -->
                <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6">
                    <div class="flex items-center gap-2 pb-3 border-b border-white/5">
                        <span class="text-emerald-500">💡</span>
                        <div>
                            <h4 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Founder Hypothesis (Day Zero)</h4>
                            <p class="text-[10px] text-slate-500 mt-0.5">Your best guess before real buyer data arrives — refine anytime</p>
                        </div>
                    </div>
                    <div class="space-y-1.5">
                        <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Pain Statement (one sentence)</label>
                        <input type="text" id="icp-pain" maxlength="500" placeholder="e.g. Agencies lose warm leads because follow-up is manual and slow" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Company Size — Min Employees</label>
                            <input type="number" id="icp-t-company_size-min" min="1" placeholder="e.g. 10" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Company Size — Max Employees</label>
                            <input type="number" id="icp-t-company_size-max" min="1" placeholder="e.g. 500" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Industries — Include (comma separated)</label>
                            <input type="text" id="icp-t-industry_fit-include" placeholder="saas, agencies" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Industries — Exclude (comma separated)</label>
                            <input type="text" id="icp-t-industry_fit-exclude" placeholder="crypto, gambling" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Target Technologies (comma separated)</label>
                            <input type="text" id="icp-t-tech_stack-keywords" placeholder="shopify, hubspot" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Target Titles (comma separated)</label>
                            <input type="text" id="icp-t-target_title-titles" placeholder="owner, head of marketing" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Countries (comma separated)</label>
                            <input type="text" id="icp-t-geography-countries" placeholder="US, GB, DE" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Regions (comma separated)</label>
                            <input type="text" id="icp-t-geography-regions" placeholder="Texas, Bavaria" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                        </div>
                        <div class="space-y-1.5 md:col-span-2">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Trigger Signals (comma separated)</label>
                            <input type="text" id="icp-t-trigger_signals-signals" list="icp-signal-list" placeholder="funding, hiring, leadership_change, product_launch" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-emerald-500/50 transition">
                            <datalist id="icp-signal-list">
                                <option value="funding"></option>
                                <option value="hiring"></option>
                                <option value="leadership_change"></option>
                                <option value="product_launch"></option>
                            </datalist>
                        </div>
                    </div>
                    <div>
                        <button type="button" onclick="saveIcpHypothesis()" class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 transition shadow-lg shadow-emerald-500/20 text-sm font-bold">Save Hypothesis</button>
                    </div>
                </div>

                <!-- (b) Weight editor -->
                <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6">
                    <div class="flex items-center justify-between pb-3 border-b border-white/5">
                        <div class="flex items-center gap-2">
                            <span class="text-amber-500">⚖️</span>
                            <div>
                                <h4 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Dimension Weights</h4>
                                <p class="text-[10px] text-slate-500 mt-0.5">Must sum to 100. Manual edits lock the dimension against auto-tuning.</p>
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] text-slate-500 uppercase font-bold tracking-wider">Total</div>
                            <div id="icp-weight-sum" class="text-xl font-bold text-slate-200">—</div>
                        </div>
                    </div>
                    <div id="icp-weights" class="grid grid-cols-1 md:grid-cols-2 gap-4"></div>
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="saveIcpWeights()" class="px-6 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 transition shadow-lg shadow-emerald-500/20 text-sm font-bold">Save Weights</button>
                        <p class="text-[10px] text-slate-500">A buyer-locked 🔒 dimension keeps its weight until you unlock it.</p>
                    </div>
                </div>

                <!-- (c) Exclusion list manager (anti-persona hard vetoes) -->
                <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6">
                    <div class="flex items-center gap-2 pb-3 border-b border-white/5">
                        <span class="text-rose-500">🚫</span>
                        <div>
                            <h4 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Anti-Persona Veto List</h4>
                            <p class="text-[10px] text-slate-500 mt-0.5">Any match disqualifies the lead outright — no scoring, no review</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Type</label>
                            <select id="icp-exc-type" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-rose-500/50 transition appearance-none">
                                <option value="industry">Industry</option>
                                <option value="company">Company</option>
                                <option value="domain">Domain</option>
                                <option value="title">Title</option>
                                <option value="keyword">Keyword</option>
                            </select>
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Value</label>
                            <input type="text" id="icp-exc-value" maxlength="255" placeholder="e.g. crypto, acme.com" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-rose-500/50 transition">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Note (optional)</label>
                            <input type="text" id="icp-exc-note" maxlength="500" placeholder="why this is a veto" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-rose-500/50 transition">
                        </div>
                        <button type="button" onclick="icpAddExclusion()" class="px-5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 transition text-sm font-bold">Add Veto</button>
                    </div>
                    <div id="icp-exclusions" class="space-y-2"></div>
                </div>

                <!-- (d) Thresholds -->
                <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6">
                    <div class="flex items-center gap-2 pb-3 border-b border-white/5">
                        <span class="text-blue-500">📏</span>
                        <div>
                            <h4 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Qualification Thresholds</h4>
                            <p class="text-[10px] text-slate-500 mt-0.5">Score ≥ qualify: auto-qualified · review–qualify: human review · &lt; review: disqualified</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 max-w-md">
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Auto-Qualify ≥</label>
                            <input type="number" id="icp-threshold-qualify" min="1" max="100" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Human Review ≥</label>
                            <input type="number" id="icp-threshold-review" min="0" max="99" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                        </div>
                    </div>
                    <div>
                        <button type="button" onclick="saveIcpThresholds()" class="px-6 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 text-sm font-bold">Save Thresholds</button>
                    </div>
                </div>

                <!-- Weight adjustment history -->
                <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-4">
                    <div class="flex items-center gap-2 pb-3 border-b border-white/5">
                        <span class="text-slate-500">🕘</span>
                        <div>
                            <h4 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400">Weight Adjustment History</h4>
                            <p class="text-[10px] text-slate-500 mt-0.5">Every weight change is audited — manual edits and auto-tuner alike</p>
                        </div>
                    </div>
                    <div id="icp-history" class="space-y-1.5 text-xs text-slate-400"></div>
                </div>
            </div>

            <!-- Quota Monitor & Health Dashboard -->
            <div class="mt-12 pt-10 border-t border-white/5 space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-indigo-400 text-lg">📊</span>
                        <div>
                            <h3 class="text-sm font-bold tracking-wider text-slate-300 uppercase">Provider Quota & Health Monitor</h3>
                            <p class="text-[11px] text-slate-500">Real-time daily usage logs tracking against platform quotas</p>
                        </div>
                    </div>
                    <button onclick="refreshQuotaDashboard()" class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-white/5 hover:bg-white/10 text-xs font-semibold text-slate-300 transition border border-white/5">
                        <span class="text-xs">🔄</span> Refresh Stats
                    </button>
                </div>
                <div id="quota-dashboard-container" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Glassmorphic Quota Cards will be dynamically injected here -->
                </div>
            </div>
        </div>
    </main>

    <div id="modal-container" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md hidden z-[100] flex items-center justify-center p-4"></div>
    <div id="drawer-container" class="fixed top-0 right-0 h-full w-full max-w-lg bg-slate-900/95 backdrop-blur-xl border-l border-white/10 shadow-2xl z-[90] transform translate-x-full transition-transform duration-300 ease-out flex flex-col"></div>

    <script src="assets/js/dashboard.js?v=<?= htmlspecialchars((string)@filemtime(__DIR__ . '/assets/js/dashboard.js')) ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Parse initial tab from query parameter
            const urlParams = new URLSearchParams(window.location.search);
            let tab = urlParams.get('tab');
            if (!tab || !['leads', 'campaigns', 'settings', 'agent', 'influencer', 'mass'].includes(tab)) {
                tab = 'leads';
            }
            showTab(tab);
            if (tab === 'settings' && typeof loadIcp === 'function') loadIcp();

            // Intercept sidebar clicks for seamless SPA tab switching on the dashboard
            document.querySelectorAll('a.tab-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const href = btn.getAttribute('href');
                    if (href && href.startsWith('index.php?tab=')) {
                        e.preventDefault();
                        const tabName = href.split('?tab=')[1];
                        showTab(tabName);
                        history.pushState(null, '', 'index.php?tab=' + tabName);
                        if (tabName === 'settings' && typeof loadIcp === 'function') loadIcp();
                    }
                });
            });
        });
    </script>
</body>
</html>
