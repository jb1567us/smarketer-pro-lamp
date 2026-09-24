<?php
require_once __DIR__ . '/includes/autoload.php';
\App\Auth::requirePageAuth();

// ---------------------------------------------------------------------------
// UI mode: 'guided' (default, for non-marketer owners) or 'advanced' (for
// marketers). Persisted in the settings table; modes change visibility,
// labels and guidance only — never business behavior.
// ---------------------------------------------------------------------------
$ui_mode = 'guided';
$pdo_for_mode = null;
try {
    $pdo_for_mode = \App\Database::getConnection();
    $st = $pdo_for_mode->prepare("SELECT setting_value FROM settings WHERE setting_key = 'ui_mode' LIMIT 1");
    $st->execute();
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row && in_array($row['setting_value'], ['guided', 'advanced'], true)) {
        $ui_mode = $row['setting_value'];
    }
} catch (\Throwable $e) {
    $ui_mode = 'guided'; // fail closed to the simpler interface
}
$is_guided = ($ui_mode === 'guided');

// Navigation definition: tab => [icon, guided label, advanced label, modes]
$NAV_ITEMS = [
    'dashboard'   => ['icon' => '📊', 'guided' => 'Dashboard',          'advanced' => 'Dashboard',          'modes' => 'guided,advanced'],
    'leads'       => ['icon' => '👥', 'guided' => 'Leads',              'advanced' => 'Leads',              'modes' => 'guided,advanced'],
    'campaigns'   => ['icon' => '🎯', 'guided' => 'Campaigns',          'advanced' => 'Outreach Campaigns', 'modes' => 'guided,advanced'],
    'mass'        => ['icon' => '🕸️', 'guided' => 'Discover',           'advanced' => 'Find Prospects',     'modes' => 'guided,advanced'],
    'influencer'  => ['icon' => '🔍', 'guided' => 'Find Influencers',   'advanced' => 'Find Influencers',   'modes' => 'advanced'],
    'agent'       => ['icon' => '🧪', 'guided' => 'AI Copywriting Lab', 'advanced' => 'AI Copywriting Lab', 'modes' => 'advanced'],
    'settings'    => ['icon' => '⚙️', 'guided' => 'Settings',           'advanced' => 'System Settings',    'modes' => 'guided,advanced'],
    'diagnostics' => ['icon' => '🩺', 'guided' => 'Diagnostics',        'advanced' => 'Diagnostics',        'modes' => 'advanced'],
];
$nav_label = function (string $tab) use ($NAV_ITEMS, $is_guided): string {
    $item = $NAV_ITEMS[$tab];
    return $is_guided ? $item['guided'] : $item['advanced'];
};
$nav_visible = function (string $tab) use ($NAV_ITEMS, $is_guided): bool {
    return in_array($is_guided ? 'guided' : 'advanced', explode(',', $NAV_ITEMS[$tab]['modes']), true);
};

// First-run checklist state (real data, no fake progress).
$checklist = ['ai' => false, 'lead' => false, 'campaign' => false, 'sender' => false];
if ($is_guided && $pdo_for_mode) {
    try {
        $s = $pdo_for_mode->query("SELECT setting_key, setting_value FROM settings")->fetchAll(\PDO::FETCH_KEY_PAIR);
        $aiKeys = ['gemini_api_key', 'groq_api_key', 'openrouter_api_key', 'openai_api_key', 'anthropic_api_key', 'mistral_api_key'];
        foreach ($aiKeys as $k) {
            if (!empty($s[$k])) { $checklist['ai'] = true; break; }
        }
        if (!empty($s['ollama_url'])) $checklist['ai'] = true;
        $checklist['sender'] = !empty($s['email_sender']) || (!empty($s['smtp_host']) && !empty($s['smtp_user']));
        $checklist['lead'] = ((int)$pdo_for_mode->query("SELECT COUNT(*) FROM leads")->fetchColumn()) > 0;
        $checklist['campaign'] = ((int)$pdo_for_mode->query("SELECT COUNT(*) FROM campaigns")->fetchColumn()) > 0;
    } catch (\Throwable $e) {
        // checklist stays all-todo rather than breaking the page
    }
}
$checklist_done = !in_array(false, $checklist, true);
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
            <?php foreach ($NAV_ITEMS as $tab => $item):
                if (!$nav_visible($tab)) continue;
                $hiddenAttr = ''; // visibility is server-rendered per mode
            ?>
            <a href="index.php?tab=<?= $tab ?>" id="tab-<?= $tab ?>-btn" data-modes="<?= htmlspecialchars($item['modes']) ?>" class="tab-btn w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5">
                <span class="text-xl group-hover:scale-110 transition"><?= $item['icon'] ?></span>
                <span class="hidden lg:block font-medium"><?= htmlspecialchars($nav_label($tab)) ?></span>
            </a>
            <?php endforeach; ?>
        </nav>

        <div class="w-full pt-4 border-t border-white/5 space-y-2">
            <!-- Interface mode switch -->
            <div class="px-1 pb-1">
                <div class="hidden lg:block text-[10px] uppercase tracking-widest text-slate-500 px-2 mb-1.5">Interface mode</div>
                <div class="flex rounded-xl bg-slate-900/60 border border-white/10 p-1 gap-1" role="group" aria-label="Interface mode">
                    <button type="button" onclick="setUiMode('guided')" title="Guided mode — simplified for business owners"
                        class="flex-1 px-2 py-1.5 rounded-lg text-[11px] font-bold transition <?= $is_guided ? 'bg-blue-600 text-white shadow' : 'text-slate-400 hover:text-slate-200' ?>">
                        <span class="hidden lg:inline">Guided</span><span class="lg:hidden">G</span>
                    </button>
                    <button type="button" onclick="setUiMode('advanced')" title="Advanced mode — full marketer controls"
                        class="flex-1 px-2 py-1.5 rounded-lg text-[11px] font-bold transition <?= !$is_guided ? 'bg-blue-600 text-white shadow' : 'text-slate-400 hover:text-slate-200' ?>">
                        <span class="hidden lg:inline">Advanced</span><span class="lg:hidden">A</span>
                    </button>
                </div>
                <?php if (!$is_guided): ?>
                <div class="hidden lg:block text-center text-[9px] uppercase tracking-widest text-amber-400/80 mt-1.5">Advanced mode</div>
                <?php endif; ?>
            </div>
            <div id="safety-indicator" class="hidden lg:flex items-center gap-2 px-3 py-2 rounded-lg bg-amber-500/10 border border-amber-500/20 text-amber-400 text-[10px] font-bold uppercase tracking-wider">
                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                Safety Active
            </div>
            <form method="POST" action="logout.php" class="w-full">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(\App\Auth::csrfToken()) ?>">
                <button type="submit" class="w-full flex items-center gap-4 px-4 py-3 rounded-xl transition group text-slate-400 hover:bg-white/5 hover:text-slate-200">
                    <span class="text-xl group-hover:scale-110 transition">🚪</span>
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

        <!-- Dashboard tab: KPIs + first-run checklist -->
        <div id="dashboard-tab" class="tab-content">
        <?php if ($is_guided && !$checklist_done): ?>
            <div id="guided-checklist" class="mb-8 p-6 rounded-2xl bg-gradient-to-br from-blue-950/60 via-slate-900/50 to-slate-900/40 border border-blue-500/30 relative overflow-hidden">
                <div class="flex items-start justify-between gap-4 mb-5">
                    <div>
                        <h3 class="text-lg font-bold text-slate-100">Get your first campaign running</h3>
                        <p class="text-slate-400 text-xs mt-1">Four steps. Each one unlocks automatically when it's done.</p>
                    </div>
                    <button onclick="dismissChecklist()" class="text-slate-500 hover:text-slate-300 text-xs px-2 py-1" title="Dismiss">✕</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
                    <?php
                    $steps = [
                        'ai'       => ['n' => 1, 'title' => 'Connect AI',            'desc' => 'Add an AI key so the app can write and personalize emails.', 'action' => "showTab('settings');focusSettingField('setting-gemini_api_key')", 'cta' => 'Connect'],
                        'lead'     => ['n' => 2, 'title' => 'Add your first lead',   'desc' => 'Add a prospect manually, or find many at once in Discover.',          'action' => "showAddLeadModal()", 'cta' => 'Add lead'],
                        'campaign' => ['n' => 3, 'title' => 'Create a campaign',     'desc' => 'A campaign is a sequence of follow-up emails sent automatically.',     'action' => "showNewCampaignModal()", 'cta' => 'Create'],
                        'sender'   => ['n' => 4, 'title' => 'Set your sender identity','desc' => 'The name and email address your prospects will see.',                  'action' => "showTab('settings');focusSettingField('setting-email_sender')", 'cta' => 'Set up'],
                    ];
                    foreach ($steps as $key => $s):
                        $done = $checklist[$key];
                    ?>
                    <div class="p-4 rounded-xl border <?= $done ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-white/[0.02] border-white/10' ?>">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] font-bold uppercase tracking-widest <?= $done ? 'text-emerald-400' : 'text-slate-500' ?>">Step <?= $s['n'] ?></span>
                            <?php if ($done): ?><span class="text-emerald-400 text-sm">✓</span><?php endif; ?>
                        </div>
                        <h4 class="font-bold text-sm text-slate-200"><?= htmlspecialchars($s['title']) ?></h4>
                        <p class="text-[11px] text-slate-400 mt-1 leading-relaxed"><?= htmlspecialchars($s['desc']) ?></p>
                        <?php if (!$done): ?>
                        <button onclick="<?= htmlspecialchars($s['action']) ?>" class="mt-3 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-[11px] font-bold text-white transition"><?= htmlspecialchars($s['cta']) ?> →</button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if ($is_guided): ?>
            <div id="guided-hint" class="mb-8 p-4 rounded-2xl bg-white/[0.02] border border-white/10 flex items-center justify-between gap-4">
                <p class="text-xs text-slate-400">You're in <strong class="text-slate-200">Guided mode</strong> — the essentials, without the clutter. Need the full marketer controls? Switch to <strong class="text-slate-200">Advanced</strong> below.</p>
                <button onclick="dismissGuidedHint()" class="text-slate-500 hover:text-slate-300 text-xs shrink-0" title="Dismiss">✕</button>
            </div>
        <?php endif; ?>
        <!-- KPI Ribbon -->
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <div class="glass p-4 rounded-2xl border-l-4 border-blue-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1"><?= $is_guided ? 'Ready to contact' : 'Lead Funnel' ?></div>
                <div id="stat-funnel_mailable" class="text-2xl font-bold">0</div>
                <div id="funnel-detail" class="text-[10px] text-blue-500 mt-1 font-medium"><?= $is_guided ? 'Verified and safe to email' : 'Mailable = verified valid, not suppressed' ?></div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-purple-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1"><?= $is_guided ? 'Interested' : 'Interested Leads' ?></div>
                <div id="stat-qualified" class="text-2xl font-bold">0</div>
                <div id="conv-qualified" class="text-[10px] text-purple-500 mt-1 font-medium"><?= $is_guided ? 'Showed buying interest' : 'Qualified — not necessarily verified' ?></div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-emerald-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1"><?= $is_guided ? 'Active replies' : 'Conversations In Progress' ?></div>
                <div id="stat-contacted" class="text-2xl font-bold">0</div>
                <div id="conv-contacted" class="text-[10px] text-emerald-500 mt-1 font-medium"><?= $is_guided ? 'Prospects talking to you' : 'Active Discussions' ?></div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-amber-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1"><?= $is_guided ? 'Won' : 'Outreach Wins' ?></div>
                <div id="stat-converted" class="text-2xl font-bold">0</div>
                <div id="conv-converted" class="text-[10px] text-amber-500 mt-1 font-medium"><?= $is_guided ? 'Deals closed from outreach' : 'Successfully Closed' ?></div>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-rose-500">
                <div class="text-slate-500 text-[10px] uppercase font-bold tracking-widest mb-1"><?= $is_guided ? 'Sending status' : 'Outreach Status' ?></div>
                <div id="supervisor-status" class="text-lg font-bold text-emerald-400">Active & Sending</div>
                <div id="supervisor-last-check" class="text-[9px] text-slate-500 mt-1 truncate">All systems nominal</div>
            </div>
        </div>
        </div><!-- /dashboard-tab -->

        <!-- Dynamic Content -->
        <div id="leads-tab" class="tab-content hidden">
            <div class="space-y-6">
                <!-- Advanced Search & Filter -->
                <div class="glass p-4 rounded-2xl flex flex-col md:flex-row gap-4">
                    <div class="relative flex-1">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">🔍</span>
                        <input type="text" id="lead-search" onkeyup="searchLeads()" placeholder="Search leads, companies, or notes..." class="w-full bg-slate-900/50 border border-white/5 rounded-xl pl-10 pr-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 transition">
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
                                <th class="px-6 py-5">Status</th>
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
                    <h2 class="text-2xl font-bold bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent"><?= $is_guided ? 'Settings' : 'System & Integrations Settings' ?></h2>
                    <p class="text-slate-500 text-sm"><?= $is_guided ? 'Connect your tools and set how emails go out' : 'Configure your outreach engine nodes, API credentials, and email channels' ?></p>
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
                                <h3 class="text-lg font-bold text-slate-100 bg-gradient-to-r from-white to-slate-300 bg-clip-text"><?= $is_guided ? 'Get set up' : 'Outreach Setup Wizard' ?></h3>
                                <p class="text-slate-400 text-xs mt-0.5"><?= $is_guided ? 'Three connections make everything work. The app checks them live as you type.' : 'Smarketer Pro dynamically tracks your credentials to ensure your AI Copywriter and Leads Harvester run seamlessly.' ?></p>
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
                            <p class="text-[10px] text-slate-400 mt-1 leading-relaxed">Connect your own sending account (SendGrid, Resend, Amazon SES). Your account, your reputation — the app only sends through it.</p>
                        </div>
                        <div class="mt-4 flex items-center justify-between">
                            <span id="setup-step-email-status" class="text-[10px] text-slate-400 font-semibold flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-slate-500"></span> Not set up
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
                                <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400"><?= $is_guided ? 'Connect AI' : 'AI Brain Node' ?></h3>
                                <p class="text-[10px] text-slate-500 mt-0.5"><?= $is_guided ? 'The AI that writes and personalizes your emails' : 'Primary intelligence engine for email drafting & personalization' ?></p>
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
                    <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6 <?= $is_guided ? 'hidden' : '' ?>" data-guided="0">
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
                                <input type="text" id="setting-jev_model" placeholder="jev-1.13" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
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
                                <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400"><?= $is_guided ? 'Find new prospects' : 'Prospect Search & OSINT Engine' ?></h3>
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
                                <h3 class="text-xs uppercase tracking-[0.2em] font-bold text-slate-400"><?= $is_guided ? 'Email sending' : 'Core Outreach Gateways' ?></h3>
                                <p class="text-[10px] text-slate-500 mt-0.5"><?= $is_guided ? 'How your emails get sent, and the address they come from' : 'Target sending accounts and active outreach delivery channels' ?></p>
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
                            <p class="text-[9px] text-slate-500">Your registered business name. Appears in the footer of every email (required: commercial mail without a real sender identity gets filtered as spam).</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Physical Postal Address <span class="text-rose-400">* required for sending</span></label>
                            <textarea id="setting-physical_address" rows="2" placeholder="123 Main St, Austin, TX 78701" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition"></textarea>
                            <p class="text-[9px] text-slate-500">A valid physical address. PO boxes registered to you are acceptable. Sends are refused until this is set — mail with a blank sender identity is what gets accounts flagged.</p>
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
                                <option value="0">Off — my risk, my responsibility</option>
                            </select>
                            <p class="text-[9px] text-slate-500">Master switch for the CASL country gate. It keys off each lead's recorded country (not the .ca domain). Turning it off is logged as your decision. You are the data controller for this install: you decide what gets sent, and you are liable for your own sending practices. These gates protect your accounts — they do not make your sending legal.</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Unknown-Country CASL Handling</label>
                            <select id="setting-compliance_casl_unknown_country" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                <option value="block">Block unknown-country leads without express consent (recommended)</option>
                                <option value="allow">Allow — my risk, my responsibility</option>
                            </select>
                            <p class="text-[9px] text-slate-500">Safe default for leads with no recorded country. Leads with express consent are never blocked by an unknown country.</p>
                        </div>

                        <!-- License (soft phone-home lock) -->
                        <div class="space-y-1.5 p-4 rounded-xl bg-white/[0.02] border border-white/5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">🔑 License</label>
                            <div id="license-status" class="text-xs text-slate-400">Loading license status…</div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
                                <div>
                                    <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-1">License server URL</label>
                                    <input type="text" id="setting-license_server_url" placeholder="https://license.example.com/api" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                </div>
                                <div>
                                    <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider mb-1">License key</label>
                                    <input type="text" id="setting-license_key" placeholder="SMP-XXXX-XXXX-XXXX" autocomplete="off" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition">
                                </div>
                            </div>
                            <p class="text-[9px] text-slate-500">Optional — the app works fully without a license. Save first, then use the buttons below. Moving the license server later is just a URL change here.</p>
                            <div class="flex flex-wrap gap-2 mt-2">
                                <button type="button" onclick="licenseAction('register')" class="px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-500 text-[11px] font-bold text-white transition">Activate this domain</button>
                                <button type="button" onclick="licenseAction('validate')" class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-[11px] font-bold text-white transition">Re-validate now</button>
                                <button type="button" onclick="licenseAction('release')" class="px-3 py-1.5 rounded-lg bg-slate-700 hover:bg-slate-600 text-[11px] font-bold text-white transition">Release this domain</button>
                            </div>
                            <p class="text-[9px] text-slate-500 mt-1">“Release this domain” frees the slot yourself when moving hosts — no support ticket needed.</p>
                        </div>

                        <!-- Active Email Provider selector -->
                        <div class="space-y-1.5">
                            <label class="block text-[10px] text-slate-500 font-bold uppercase tracking-wider">Active Outreach Method</label>
                            <select id="setting-active_email_provider" onchange="toggleActiveProviderFields()" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-2.5 outline-none text-xs text-slate-300 focus:border-blue-500/50 transition appearance-none">
                                <optgroup label="API providers — Recommended">
                                    <option value="sendgrid">SendGrid ★ Recommended</option>
                                    <option value="resend">Resend ★ Recommended</option>
                                    <option value="amazon_ses">Amazon SES ★ Recommended</option>
                                    <option value="brevo">Brevo</option>
                                    <option value="mailgun">Mailgun</option>
                                    <option value="mailjet">Mailjet</option>
                                    <option value="postmark">Postmark</option>
                                    <option value="mailersend">MailerSend</option>
                                    <option value="zoho">ZeptoMail (Zoho)</option>
                                    <option value="netcore">Pepipost (Netcore)</option>
                                    <option value="mailtrap">Mailtrap (testing sandbox)</option>
                                </optgroup>
                                <optgroup label="Advanced — not recommended">
                                    <option value="smtp">Shared-host SMTP (not recommended)</option>
                                    <option value="sendpulse">SendPulse SMTP</option>
                                    <option value="custom_smtp">Custom SMTP</option>
                                    <option value="zoho_smtp">Zoho SMTP</option>
                                    <option value="netcore_smtp">Netcore SMTP</option>
                                </optgroup>
                            </select>
                            <p class="text-[9px] text-slate-500">Emails go out through <em>your</em> provider account, on <em>your</em> domain. This software provides no sending infrastructure and makes no inbox-placement promises.</p>
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

                        <!-- Shared-host SMTP: Advanced — not recommended -->
                        <div id="email-group-smtp" class="email-provider-fields space-y-4">
                            <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
                                <p class="text-[11px] font-bold text-amber-400 uppercase tracking-wider">Advanced — not recommended</p>
                                <p class="text-[10px] text-slate-400 mt-1 leading-relaxed">
                                    Mail sent this way leaves from your shared host's IP. Shared-hosting IP
                                    reputation is outside our control — expect worse deliverability than a
                                    dedicated sending provider on your own account. Suitable only for testing
                                    or very low volume. This software makes no inbox-placement promises.
                                </p>
                            </div>
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
                    <div class="p-6 rounded-2xl bg-white/[0.01] border border-white/5 space-y-6 <?= $is_guided ? 'hidden' : '' ?>" data-guided="0">
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
                <div class="space-y-6 <?= $is_guided ? 'hidden' : '' ?>" data-guided="0">
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

            <!-- Quota Monitor & Health Dashboard -->
            <div class="mt-12 pt-10 border-t border-white/5 space-y-6 <?= $is_guided ? 'hidden' : '' ?>" data-guided="0">
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

        <div id="diagnostics-tab" class="tab-content hidden glass p-8 rounded-3xl max-w-5xl mx-auto border border-white/5">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-2xl font-bold bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent">🩺 System Diagnostics</h2>
                    <p class="text-slate-500 text-sm">One-click health check. Fix what you can, then paste the report below when you ask for help.</p>
                </div>
                <div class="flex gap-2">
                    <button onclick="loadDiagnostics()" class="px-4 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 text-sm font-bold">🔄 Re-run checks</button>
                    <button onclick="copyDiagnosticsReport()" class="px-4 py-2.5 rounded-xl bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/20 text-emerald-400 hover:text-emerald-300 transition text-sm font-bold">📋 Copy report</button>
                </div>
            </div>

            <div id="diagnostics-results" class="space-y-4">
                <div class="p-8 text-center text-slate-500">Running checks…</div>
            </div>

            <div class="mt-8">
                <h3 class="text-sm font-bold text-slate-300 mb-2">Paste-ready report</h3>
                <p class="text-slate-500 text-xs mb-3">Copy this into your forum post when you ask for help. It contains no passwords or API keys.</p>
                <textarea id="diagnostics-report" readonly rows="14" class="w-full bg-slate-900/60 border border-white/5 rounded-xl px-4 py-3 text-xs text-slate-300 font-mono outline-none resize-y"></textarea>
            </div>

            <!-- Support boundaries -->
            <div class="mt-8 p-6 rounded-2xl bg-amber-500/[0.04] border border-amber-500/20">
                <h3 class="text-sm font-bold text-amber-300 mb-2">📣 Getting help</h3>
                <ul class="text-slate-400 text-sm space-y-1.5 list-disc list-inside">
                    <li>Support for this product is the <strong class="text-slate-200">community forum only</strong> — there is no helpdesk ticket queue.</li>
                    <li>Response times are <strong class="text-slate-200">not guaranteed</strong>. Community members and the developer answer when they can.</li>
                    <li>When you post, <strong class="text-slate-200">always paste the diagnostics report above</strong> — posts without it take much longer to get a useful answer.</li>
                    <li>Never post your <strong class="text-slate-200">API keys, passwords, or license key</strong> — this report never includes them; keep it that way.</li>
                </ul>
            </div>
        </div>
    </main>

    <div id="modal-container" class="fixed inset-0 bg-slate-950/80 backdrop-blur-md hidden z-[100] flex items-center justify-center p-4"></div>
    <div id="drawer-container" class="fixed top-0 right-0 h-full w-full max-w-lg bg-slate-900/95 backdrop-blur-xl border-l border-white/10 shadow-2xl z-[90] transform translate-x-full transition-transform duration-300 ease-out flex flex-col"></div>

    <script src="assets/js/dashboard.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Parse initial tab from query parameter
            const urlParams = new URLSearchParams(window.location.search);
            const uiMode = document.body.dataset.uiMode || 'guided';
            const advancedOnly = ['diagnostics', 'agent', 'influencer'];
            let tab = urlParams.get('tab');
            if (!tab || !['dashboard', 'leads', 'campaigns', 'settings', 'diagnostics', 'agent', 'influencer', 'mass'].includes(tab)) {
                tab = 'dashboard';
            }
            // Guided mode has no dead ends: advanced-only tabs redirect home.
            if (uiMode === 'guided' && advancedOnly.includes(tab)) {
                tab = 'dashboard';
            }
            showTab(tab);

            // Intercept sidebar clicks for seamless SPA tab switching on the dashboard
            document.querySelectorAll('a.tab-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    const href = btn.getAttribute('href');
                    if (href && href.startsWith('index.php?tab=')) {
                        e.preventDefault();
                        const tabName = href.split('?tab=')[1];
                        showTab(tabName);
                        history.pushState(null, '', 'index.php?tab=' + tabName);
                    }
                });
            });
        });
    </script>
</body>
</html>
