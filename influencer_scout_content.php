<header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
    <div>
        <h2 class="text-3xl font-bold bg-gradient-to-r from-white to-slate-400 bg-clip-text text-transparent flex items-center gap-2">
            🕵️ <span>Influencer Scout Panel</span>
        </h2>
        <p class="text-slate-400 text-sm">Automated Social Discovery, Candidate Vetting & Outreach Pipeline</p>
    </div>
</header>

<!-- Premium Tabbed Controller -->
<div class="flex items-center gap-2 mb-6 border-b border-white/5 pb-px">
    <button onclick="switchScoutTab('scout-search')" id="tab-scout-search" class="px-6 py-3 border-b-2 border-blue-500 text-blue-400 text-sm font-bold transition flex items-center gap-2">
        <span>🔍</span> AI Search Scout
    </button>
    <button onclick="switchScoutTab('manual-insert')" id="tab-manual-insert" class="px-6 py-3 border-b-2 border-transparent text-slate-400 hover:text-slate-300 text-sm font-semibold transition flex items-center gap-2">
        <span>✍️</span> Manual Insertion
    </button>
</div>

<!-- Tab 1: AI Search Scout -->
<div id="panel-scout-search" class="glass p-6 rounded-3xl mb-8 border border-white/5">
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div>
            <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Keyword or Niche</label>
            <input type="text" id="scout-niche" placeholder="e.g. Fitness, CEO, Realtor" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white placeholder-slate-600 text-sm transition">
        </div>
        
        <div>
            <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Location</label>
            <input type="text" id="scout-location" placeholder="e.g. Austin, New York" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white placeholder-slate-600 text-sm transition">
        </div>
        
        <div>
            <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Social Platform</label>
            <select id="scout-platform" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm transition">
                <option value="LinkedIn">LinkedIn</option>
                <option value="Facebook">Facebook</option>
                <option value="X">X (Twitter)</option>
                <option value="Instagram">Instagram</option>
                <option value="TikTok">TikTok</option>
            </select>
        </div>

        <div>
            <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Search Engine</label>
            <select id="scout-engine" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm transition">
                <option value="searxng">SearXNG (Local/Proxy)</option>
                <option value="ddg">DuckDuckGo (Free)</option>
                <option value="tavily">Tavily (Premium)</option>
                <option value="scrapingant">ScrapingAnt (Proxied)</option>
                <option value="serper">Serper API</option>
                <option value="gemini">Gemini Search</option>
                <option value="exa">Exa Neural</option>
            </select>
        </div>
        
        <div class="flex items-end">
            <button onclick="launchSearchScout()" class="w-full bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-blue-500/20 text-white transition flex items-center justify-center gap-2">
                <span id="scout-btn-icon">⚡</span> <span id="scout-btn-text">Launch AI Scout</span>
            </button>
        </div>
    </div>

    <!-- Expandable Advanced Vetting Filters -->
    <div class="mb-6">
        <button onclick="toggleAdvancedFilters()" class="text-xs text-blue-400 hover:text-blue-300 font-bold transition flex items-center gap-1.5 focus:outline-none">
            <span id="adv-filter-arrow" class="inline-block transition-transform duration-200">▶</span>
            <span>⚙️ Advanced Vetting & Vigor Filters</span>
        </button>
        
        <div id="advanced-filters-drawer" class="hidden mt-4 p-5 rounded-2xl bg-slate-900/30 border border-white/5 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 transition-all duration-300">
            <div>
                <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Min Followers</label>
                <input type="number" id="scout-min-followers" placeholder="e.g. 1000" class="w-full bg-slate-950/50 border border-white/5 rounded-xl px-4 py-2 outline-none focus:ring-2 focus:ring-blue-500/50 text-white placeholder-slate-700 text-xs transition">
            </div>
            <div>
                <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Max Followers</label>
                <input type="number" id="scout-max-followers" placeholder="e.g. 500000" class="w-full bg-slate-950/50 border border-white/5 rounded-xl px-4 py-2 outline-none focus:ring-2 focus:ring-blue-500/50 text-white placeholder-slate-700 text-xs transition">
            </div>
            <div>
                <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Min Search Results</label>
                <input type="number" id="scout-min-results" value="5" placeholder="e.g. 5" class="w-full bg-slate-950/50 border border-white/5 rounded-xl px-4 py-2 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-xs transition">
            </div>
            <div>
                <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Max Search Results</label>
                <input type="number" id="scout-max-results" value="25" placeholder="e.g. 25" class="w-full bg-slate-950/50 border border-white/5 rounded-xl px-4 py-2 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-xs transition">
            </div>
        </div>
    </div>

    <!-- Live Search Logs / Info -->
    <div id="scout-status-bar" class="hidden flex items-center justify-between text-xs border border-white/5 bg-white/[0.01] rounded-xl px-4 py-3 text-slate-400">
        <div class="flex items-center gap-2">
            <span class="animate-pulse inline-block w-2.5 h-2.5 rounded-full bg-blue-500"></span>
            <span id="scout-status-text">Formulating targeted search query...</span>
        </div>
        <div id="scout-provider-badge" class="font-semibold text-blue-400 bg-blue-500/10 px-2 py-0.5 rounded border border-blue-500/20 uppercase tracking-wider text-[10px]">Active</div>
    </div>

    <!-- Candidate Discovery Preview Grid -->
    <div id="discovery-preview-container" class="hidden mt-6 border-t border-white/5 pt-6">
        <h4 class="text-sm font-bold text-white mb-4 flex items-center gap-2">
            <span>✨</span> Discovered Social Candidates
        </h4>
        <div id="discovery-grid" class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-[400px] overflow-y-auto pr-2 custom-scrollbar">
            <!-- Discovered cards injected here -->
        </div>
    </div>
</div>

<!-- Tab 2: Manual Insertion -->
<div id="panel-manual-insert" class="glass p-6 rounded-3xl mb-8 border border-white/5 hidden">
    <div class="flex flex-col md:flex-row gap-4">
        <div class="flex-1">
            <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Candidate Name</label>
            <input type="text" id="inf-name" placeholder="Full Name" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white placeholder-slate-600 text-sm transition">
        </div>
        
        <div>
            <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Platform</label>
            <select id="inf-platform" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white text-sm transition">
                <option value="LinkedIn">LinkedIn</option>
                <option value="Facebook">Facebook</option>
                <option value="X">X (Twitter)</option>
                <option value="Instagram">Instagram</option>
                <option value="TikTok">TikTok</option>
            </select>
        </div>
        
        <div class="flex-1">
            <label class="block text-[10px] text-slate-500 mb-1.5 uppercase font-bold tracking-wider">Social Handle</label>
            <input type="text" id="inf-handle" placeholder="@handle" class="w-full bg-slate-900/50 border border-white/5 rounded-xl px-4 py-2.5 outline-none focus:ring-2 focus:ring-blue-500/50 text-white placeholder-slate-600 text-sm transition">
        </div>
        
        <div class="flex items-end">
            <button onclick="addInfluencer()" class="w-full md:w-auto px-8 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-500 transition shadow-lg shadow-blue-500/20 text-sm font-bold text-white whitespace-nowrap">Add Candidate</button>
        </div>
    </div>
</div>

<!-- List Panel -->
<div class="glass rounded-3xl overflow-hidden border border-white/5 min-h-[400px]">
    <div class="px-6 py-5 border-b border-white/5 flex items-center justify-between">
        <h3 class="font-bold text-white text-base">All Tracked Candidates</h3>
        <span class="text-xs text-slate-500 font-medium" id="influencer-total-badge">0 Candidates</span>
    </div>
    
    <table class="w-full text-left border-collapse">
        <thead>
            <tr class="bg-white/[0.02] text-slate-500 text-[10px] uppercase tracking-[0.2em] font-bold">
                <th class="px-6 py-5">Name & Handle</th>
                <th class="px-6 py-5">Platform</th>
                <th class="px-6 py-5">Est. Followers</th>
                <th class="px-6 py-5">Status</th>
                <th class="px-6 py-5 text-right">Actions</th>
            </tr>
        </thead>
        <tbody id="inf-list" class="divide-y divide-white/5">
            <tr><td colspan="5" class="p-6 text-center text-slate-500">Loading...</td></tr>
        </tbody>
    </table>
</div>

<script>
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

    // Safe encoding for HTML attribute values (double-quotes escaped)
    function escapeAttr(text) {
        if (!text) return '';
        return text.toString().replace(/&/g, '&amp;').replace(/"/g, '&quot;');
    }

    // Delegated click handler for dynamically-created crawl buttons in the roster table
    document.addEventListener('click', async function(e) {
        const btn = e.target.closest('.crawl-btn');
        if (!btn) return;
        const id       = btn.dataset.id;
        const url      = btn.dataset.url;
        const platform = btn.dataset.platform;
        if (!url) { alert('No profile URL to crawl.'); return; }
        btn.disabled = true;
        btn.innerHTML = '⏳ Crawling...';
        try {
            const res = await fetch('api/influencer_scout.php?action=crawl_profile', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: parseInt(id) || 0, url, platform })
            });
            const result = await res.json();
            if (result.success) {
                const td = btn.closest('td');
                if (td) td.innerHTML = '<span class="text-emerald-400 font-bold">' + parseInt(result.follower_count).toLocaleString() + '</span>';
            } else {
                btn.disabled = false;
                btn.innerHTML = '⚠️ Failed';
                btn.title = result.error || 'Crawl failed — platform may block bots or require login.';
                btn.classList.add('border-rose-500/30');
            }
        } catch (err) {
            btn.disabled = false;
            btn.innerHTML = '🔍 Crawl to extract';
        }
    });

    function switchScoutTab(tabId) {
        const btnSearch = document.getElementById('tab-scout-search');
        const btnManual = document.getElementById('tab-manual-insert');
        const pnlSearch = document.getElementById('panel-scout-search');
        const pnlManual = document.getElementById('panel-manual-insert');
        
        if (tabId === 'scout-search') {
            btnSearch.className = "px-6 py-3 border-b-2 border-blue-500 text-blue-400 text-sm font-bold transition flex items-center gap-2";
            btnManual.className = "px-6 py-3 border-b-2 border-transparent text-slate-400 hover:text-slate-300 text-sm font-semibold transition flex items-center gap-2";
            pnlSearch.classList.remove('hidden');
            pnlManual.classList.add('hidden');
        } else {
            btnSearch.className = "px-6 py-3 border-b-2 border-transparent text-slate-400 hover:text-slate-300 text-sm font-semibold transition flex items-center gap-2";
            btnManual.className = "px-6 py-3 border-b-2 border-blue-500 text-blue-400 text-sm font-bold transition flex items-center gap-2";
            pnlSearch.classList.add('hidden');
            pnlManual.classList.remove('hidden');
        }
    }

    async function loadInfluencers() {
        try {
            const res = await fetch('api/influencer_scout.php');
            const data = await res.json();
            const tbody = document.getElementById('inf-list');
            const totalEl = document.getElementById('influencer-total-badge');
            
            window.trackedInfluencers = data.data || [];
            
            if (totalEl && data.data) {
                totalEl.innerText = `${data.data.length} Candidates`;
            }
            
            if (!data.data || !data.data.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="p-6 text-center text-slate-500">No candidates found. Scout some!</td></tr>';
                return;
            }

            tbody.innerHTML = data.data.map(i => {
                let badgeClass = 'bg-slate-800 text-slate-300 border-white/5';
                const pLower = i.platform.toLowerCase();
                if (pLower === 'linkedin') badgeClass = 'bg-blue-500/10 text-blue-400 border-blue-500/20';
                else if (pLower === 'facebook') badgeClass = 'bg-indigo-500/10 text-indigo-400 border-indigo-500/20';
                else if (pLower === 'x' || pLower === 'twitter') badgeClass = 'bg-slate-400/10 text-slate-200 border-slate-400/20';
                else if (pLower === 'instagram') badgeClass = 'bg-pink-500/10 text-pink-400 border-pink-500/20';
                else if (pLower === 'tiktok') badgeClass = 'bg-rose-500/10 text-rose-400 border-rose-500/20';

                const followerCell = parseInt(i.follower_count) > 0
                    ? parseInt(i.follower_count).toLocaleString()
                    : '<button class="crawl-btn inline-flex items-center gap-1 px-2 py-0.5 rounded bg-amber-500/10 hover:bg-amber-500/20 text-amber-400 border border-amber-500/20 text-[10px] font-bold cursor-pointer transition" data-id="' + i.id + '" data-url="' + escapeAttr(i.url) + '" data-platform="' + escapeAttr(i.platform) + '" title="Click to crawl profile and extract follower count">🔍 Crawl to extract</button>';

                const actionBtns = i.status === 'New'
                    ? '<button onclick="updateStatus(' + i.id + ', \'Vetted\')" class="text-emerald-400 hover:text-emerald-300 font-bold text-xs mr-3 transition">Vet</button>'
                      + '<button onclick="updateStatus(' + i.id + ', \'Rejected\')" class="text-rose-400 hover:text-rose-300 font-bold text-xs mr-3 transition">Reject</button>'
                      + '<button onclick="openEditInfluencerModal(' + i.id + ')" class="text-blue-400 hover:text-blue-300 font-bold text-xs mr-3 transition">Edit</button>'
                      + '<button onclick="deleteInfluencer(' + i.id + ')" class="text-slate-500 hover:text-rose-400 font-bold text-xs transition" title="Delete">✕</button>'
                    : '<button onclick="updateStatus(' + i.id + ', \'New\')" class="text-slate-400 hover:text-slate-300 font-semibold text-xs mr-3 transition">Reset</button>'
                      + '<button onclick="openEditInfluencerModal(' + i.id + ')" class="text-blue-400 hover:text-blue-300 font-bold text-xs mr-3 transition">Edit</button>'
                      + '<button onclick="deleteInfluencer(' + i.id + ')" class="text-slate-500 hover:text-rose-400 font-bold text-xs transition" title="Delete">✕</button>';

                return '<tr class="hover:bg-white/[0.01] transition">'
                    + '<td class="px-6 py-4"><div class="font-bold text-white">' + escapeHtml(i.name) + '</div><div class="text-xs text-slate-500">' + escapeHtml(i.handle) + '</div></td>'
                    + '<td class="px-6 py-4"><span class="px-2.5 py-1 rounded-lg text-xs font-bold border ' + badgeClass + '">' + escapeHtml(i.platform) + '</span></td>'
                    + '<td class="px-6 py-4 text-slate-300 font-medium">' + followerCell + '</td>'
                    + '<td class="px-6 py-4"><span class="px-2.5 py-1 rounded-lg text-xs font-bold border '
                      + (i.status === 'New' ? 'bg-blue-500/10 text-blue-400 border-blue-500/20' : '')
                      + (i.status === 'Vetted' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : '')
                      + (i.status === 'Rejected' ? 'bg-rose-500/10 text-rose-400 border-rose-500/20' : '')
                      + (i.status === 'Contacted' ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' : '')
                      + '">' + i.status + '</span></td>'
                    + '<td class="px-6 py-4 text-right">' + actionBtns + '</td>'
                    + '</tr>';
            }).join('');
        } catch (e) {
            console.error(e);
        }
    }

    async function addInfluencer() {
        const name = document.getElementById('inf-name').value;
        const platform = document.getElementById('inf-platform').value;
        const handle = document.getElementById('inf-handle').value;

        if(!name || !handle) return alert('Name and Handle required');

        try {
            const res = await fetch('api/influencer_scout.php?action=add', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({name, platform, handle})
            });
            const result = await res.json();
            if (result.success) {
                document.getElementById('inf-name').value = '';
                document.getElementById('inf-handle').value = '';
                loadInfluencers();
            } else {
                alert('Error: ' + result.error);
            }
        } catch (error) {
            alert('Failed to add candidate.');
        }
    }

    async function updateStatus(id, status) {
        try {
            await fetch('api/influencer_scout.php?action=update_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({id, status})
            });
            loadInfluencers();
        } catch (e) {
            console.error(e);
        }
    }

    function toggleAdvancedFilters() {
        const drawer = document.getElementById('advanced-filters-drawer');
        const arrow = document.getElementById('adv-filter-arrow');
        if (drawer.classList.contains('hidden')) {
            drawer.classList.remove('hidden');
            arrow.style.transform = 'rotate(90deg)';
        } else {
            drawer.classList.add('hidden');
            arrow.style.transform = 'rotate(0deg)';
        }
    }

    // AI Scout Searching Logic
    async function launchSearchScout() {
        const niche = document.getElementById('scout-niche').value;
        const location = document.getElementById('scout-location').value;
        const platform = document.getElementById('scout-platform').value;
        const provider = document.getElementById('scout-engine').value;
        
        const minFollowers = document.getElementById('scout-min-followers').value;
        const maxFollowers = document.getElementById('scout-max-followers').value;
        const minResults = document.getElementById('scout-min-results').value;
        const maxResults = document.getElementById('scout-max-results').value;
        
        const btnIcon = document.getElementById('scout-btn-icon');
        const btnText = document.getElementById('scout-btn-text');
        const statusBar = document.getElementById('scout-status-bar');
        const statusText = document.getElementById('scout-status-text');
        const previewContainer = document.getElementById('discovery-preview-container');
        const discoveryGrid = document.getElementById('discovery-grid');
        
        if (!niche || !location) {
            alert('Please specify both niche and target location to run AI Scout.');
            return;
        }
        
        // Setup loading state
        btnIcon.className = "animate-spin inline-block";
        btnIcon.innerText = "⏳";
        btnText.innerText = "Searching...";
        statusBar.classList.remove('hidden');
        statusText.innerText = `Generating precise search patterns for ${platform}...`;
        previewContainer.classList.add('hidden');
        
        try {
            statusText.innerText = `Harvesting target search matches for "${niche}" in ${location}...`;
            const response = await fetch('api/influencer_scout.php?action=discover', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    niche, 
                    location, 
                    platform, 
                    provider,
                    min_followers: minFollowers,
                    max_followers: maxFollowers,
                    min_results: minResults,
                    max_results: maxResults
                })
            });
            
            const result = await response.json();
            if (result.success) {
                statusText.innerText = `Social profiles retrieved using dork template against ${result.provider}!`;
                
                if (!result.data || !result.data.length) {
                    discoveryGrid.innerHTML = `
                        <div class="col-span-2 text-center py-12 text-slate-400 text-xs glass rounded-2xl border border-white/5 p-6">
                            <div class="flex justify-center mb-3">
                                <span class="p-2 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 text-base">⚠️</span>
                            </div>
                            <p class="mb-2 text-slate-200 font-bold text-sm">No direct profiles discovered</p>
                            <p class="mb-4 text-slate-400 leading-relaxed max-w-md mx-auto">Provider <strong class="text-slate-300">${escapeHtml(result.provider)}</strong> returned 0 results — it may be rate-limited, misconfigured, or blocked by the target platform's anti-bot protections.</p>
                            <div class="flex flex-col sm:flex-row items-center justify-center gap-3">
                                <button onclick="injectMockCandidates('${escapeHtml(platform)}', '${escapeHtml(niche)}', '${escapeHtml(location)}')" class="px-5 py-2.5 rounded-xl bg-blue-600/20 hover:bg-blue-600/30 text-blue-400 font-bold border border-blue-500/30 hover:border-blue-500/50 shadow-lg shadow-blue-500/10 transition text-xs">
                                    ⚡ Generate Live Mock Candidates
                                </button>
                                <span class="text-slate-600 text-[10px] uppercase font-bold">or</span>
                                <div class="text-xs text-blue-400 font-semibold bg-blue-500/5 border border-blue-500/10 rounded-xl px-4 py-2">
                                    💡 Switch to <span class="underline">DuckDuckGo (Free)</span> in the engine selector — no API key required!
                                </div>
                            </div>
                        </div>
                    `;
                } else {
                    discoveryGrid.innerHTML = result.data.map((c, index) => {
                        let btnId = `import-scout-${index}`;
                        return `
                            <div class="glass p-5 rounded-2xl border border-white/5 hover:border-white/10 transition relative group flex flex-col justify-between">
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-md border 
                                            ${c.platform.toLowerCase() === 'linkedin'?'bg-blue-500/10 text-blue-400 border-blue-500/20':''}
                                            ${c.platform.toLowerCase() === 'facebook'?'bg-indigo-500/10 text-indigo-400 border-indigo-500/20':''}
                                            ${c.platform.toLowerCase() === 'x'?'bg-slate-400/10 text-slate-200 border-slate-400/20':''}
                                            ${c.platform.toLowerCase() === 'instagram'?'bg-pink-500/10 text-pink-400 border-pink-500/20':''}
                                            ${c.platform.toLowerCase() === 'tiktok'?'bg-rose-500/10 text-rose-400 border-rose-500/20':''}
                                        ">${escapeHtml(c.platform)}</span>
                                        <span class="text-xs text-slate-400 font-medium">
                                            ${parseInt(c.follower_count) > 0 
                                                ? `${parseInt(c.follower_count).toLocaleString()} Followers` 
                                                : `<button onclick="crawlFollowersFromCard('${escapeHtml(c.url)}', '${escapeHtml(c.platform)}', this)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 border border-blue-500/20 text-[10px] font-bold cursor-pointer transition" title="Crawl profile for follower count"><span>🔍</span> Crawl for Followers</button>`
                                            }
                                        </span>
                                    </div>
                                    <h5 class="font-bold text-white text-sm">${escapeHtml(c.name)}</h5>
                                    <p class="text-xs text-blue-400 mb-2 truncate font-semibold">${escapeHtml(c.handle)}</p>
                                    <p class="text-[11px] text-slate-500 line-clamp-2 leading-relaxed mb-4">${escapeHtml(c.snippet || 'Profile matches outreach criteria.')}</p>
                                </div>
                                <div class="flex gap-2">
                                    <button id="${btnId}" onclick="importScoutCandidate('${escapeHtml(c.name)}', '${escapeHtml(c.platform)}', '${escapeHtml(c.handle)}', '${escapeHtml(c.url)}', ${c.follower_count}, '${btnId}')" class="flex-1 py-1.5 rounded-lg bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/30 text-blue-300 text-xs font-bold transition flex items-center justify-center gap-1">
                                        📥 Import Candidate
                                    </button>
                                    <a href="${escapeHtml(c.url)}" target="_blank" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold border border-white/5 transition flex items-center justify-center">
                                        🔗
                                    </a>
                                </div>
                            </div>
                        `;
                    }).join('');
                }
                
                previewContainer.classList.remove('hidden');
            } else {
                statusText.innerText = 'Search failed: ' + result.error;
                alert('Scout Engine Error: ' + result.error);
            }
        } catch (error) {
            statusText.innerText = 'An error occurred during network retrieval.';
            alert('Scout Engine Network Error.');
        } finally {
            btnIcon.className = "";
            btnIcon.innerText = "⚡";
            btnText.innerText = "Launch AI Scout";
        }
    }

    // Crawl from discovery grid card (no DB id — just updates the UI)
    async function crawlFollowersFromCard(url, platform, btn) {
        if (!url) { alert('No profile URL available.'); return; }
        const originalHTML = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="animate-spin inline-block">⏳</span> Crawling...';
        try {
            const res = await fetch('api/influencer_scout.php?action=crawl_profile', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: 0, url, platform })
            });
            const result = await res.json();
            if (result.success) {
                btn.outerHTML = '<span class="text-emerald-400 font-bold text-xs">' + parseInt(result.follower_count).toLocaleString() + ' Followers</span>';
            } else {
                btn.disabled = false;
                btn.innerHTML = '<span>⚠️</span> Failed';
                btn.title = result.error || 'Crawl failed';
            }
        } catch (e) {
            btn.disabled = false;
            btn.innerHTML = originalHTML;
        }
    }

    // Delete influencer from roster
    async function deleteInfluencer(id) {
        if (!confirm('Remove this candidate from your roster?')) return;
        try {
            await fetch('api/influencer_scout.php?action=delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            loadInfluencers();
        } catch (e) { alert('Delete failed.'); }
    }

    async function importScoutCandidate(name, platform, handle, url, follower_count, btnId) {
        const btn = document.getElementById(btnId);
        if (!btn) return;
        
        btn.disabled = true;
        btn.innerText = "Importing...";
        
        try {
            const res = await fetch('api/influencer_scout.php?action=add', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({name, platform, handle, url, follower_count})
            });
            const result = await res.json();
            if (result.success) {
                btn.className = "flex-1 py-1.5 rounded-lg bg-emerald-500/20 border border-emerald-500/30 text-emerald-400 text-xs font-bold transition cursor-default flex items-center justify-center gap-1";
                btn.innerHTML = "✅ Imported";
                loadInfluencers();
            } else {
                btn.disabled = false;
                btn.innerText = "Import Candidate";
                alert('Import Failed: ' + result.error);
            }
        } catch (error) {
            btn.disabled = false;
            btn.innerText = "Import Candidate";
            alert('Import Network Error.');
        }
    }

    function injectMockCandidates(platform, niche, location) {
        const discoveryGrid = document.getElementById('discovery-grid');
        const statusText = document.getElementById('scout-status-text');
        
        const minFollowers = parseInt(document.getElementById('scout-min-followers').value) || 0;
        const maxFollowers = parseInt(document.getElementById('scout-max-followers').value) || Infinity;
        const maxResults = parseInt(document.getElementById('scout-max-results').value) || 25;
        
        statusText.innerText = `Generated premium local mock profiles for "${niche}" in ${location}!`;
        
        let platName = platform.charAt(0).toUpperCase() + platform.slice(1);
        let candidates = [];
        
        if (platform.toLowerCase() === 'linkedin') {
            candidates = [
                { name: "Sarah Jenkins", handle: "sarah-jenkins-realestate", url: "https://linkedin.com/in/sarah-jenkins-realestate", follower_count: 42100, snippet: `Top producing residential real estate specialist in ${location}. Focusing on luxury listings and investment portfolios.` },
                { name: "Marcus Thorne", handle: "marcus-thorne-broker", url: "https://linkedin.com/in/marcus-thorne-broker", follower_count: 18500, snippet: `Managing Director at ${niche} Advisors ${location}. 15+ years managing commercial acquisitions.` },
                { name: "Elena Rostova", handle: "elena-rostova-agent", url: "https://linkedin.com/in/elena-rostova-agent", follower_count: 53200, snippet: `Residential Realtor at Elite Living. Host of the "${location} Home Show" Podcast.` },
                { name: "David Chen", handle: "david-chen-investments", url: "https://linkedin.com/in/david-chen-investments", follower_count: 29800, snippet: `Sourcing off-market ${niche} opportunities in ${location} Metro. General Partner at Austin Capital.` }
            ];
        } else if (platform.toLowerCase() === 'facebook') {
            candidates = [
                { name: "Austin Homes Group", handle: "austinhomesgroup", url: "https://facebook.com/austinhomesgroup", follower_count: 12800, snippet: `Austin's premier residential real estate team helping families buy & sell properties in ${location}.` },
                { name: "Apex Commercial", handle: "apexcommerciallistings", url: "https://facebook.com/apexcommerciallistings", follower_count: 8400, snippet: `Commercial brokerage listing industrial warehouses, retail spaces, and land tracts in ${location}.` },
                { name: "Modern Living Austin", handle: "modernlivingaustin", url: "https://facebook.com/modernlivingaustin", follower_count: 24500, snippet: `Showcasing modern architecture, mid-century renovations, and new construction designs.` },
                { name: "Central Texas Relocation", handle: "centexrelocate", url: "https://facebook.com/centexrelocate", follower_count: 19100, snippet: `Relocation guides, community spotlights, and neighborhood reviews for families moving to ${location}.` }
            ];
        } else if (platform.toLowerCase() === 'x' || platform.toLowerCase() === 'twitter') {
            candidates = [
                { name: "RealEstateGuru", handle: "@AustinsGuru", url: "https://x.com/AustinsGuru", follower_count: 24500, snippet: `${location} property market insights, weekly investment analysis, and hot listings.` },
                { name: "Austin Development", handle: "@AtxDevelops", url: "https://x.com/AtxDevelops", follower_count: 41200, snippet: `Tracking zoning changes, commercial developments, permits, and skyline expansion in ${location}.` },
                { name: "PropTech Advisor", handle: "@PropTechInsider", url: "https://x.com/PropTechInsider", follower_count: 15300, snippet: `Analyzing tech stack shifts in modern brokerage, CRM automation, and AI valuation.` },
                { name: "Agent Austin", handle: "@AgentAustinTX", url: "https://x.com/AgentAustinTX", follower_count: 32800, snippet: `Daily updates on local inventory, mortgage rates, and buyer concession trends in Central Texas.` }
            ];
        } else if (platform.toLowerCase() === 'instagram') {
            candidates = [
                { name: "Austin Luxury Properties", handle: "@austin_luxury_homes", url: "https://instagram.com/austin_luxury_homes", follower_count: 89600, snippet: `Curated showcase of the most stunning luxury estates and modern architecture in ${location}.` },
                { name: "Interior Design ATX", handle: "@interiordesign_atx", url: "https://instagram.com/interiordesign_atx", follower_count: 67100, snippet: `Staging inspiration, modern home interior design, and local designer spotlights.` },
                { name: "Austin Neighborhoods", handle: "@austin_neighborhoods", url: "https://instagram.com/austin_neighborhoods", follower_count: 34200, snippet: `Highlighting restaurants, parks, schools, and cultural hotspots across Central Texas.` },
                { name: "Realtor Austin Lifestyle", handle: "@austin_realtor_life", url: "https://instagram.com/austin_realtor_life", follower_count: 104000, snippet: `A day in the life of a luxury agent in ${location}. Open house tours and local events.` }
            ];
        } else { // tiktok
            candidates = [
                { name: "Agent Texas", handle: "@agent_texas", url: "https://tiktok.com/@agent_texas", follower_count: 142000, snippet: `Behind the scenes of Austin home tours, property investments, and buyer tips in ${location}!` },
                { name: "ATX Real Estate Guy", handle: "@atx_realestate_guy", url: "https://tiktok.com/@atx_realestate_guy", follower_count: 89000, snippet: `Answering first-time homebuyer questions, mortgage hacks, and property deal walkthroughs.` },
                { name: "Austin House Tours", handle: "@austinhousetours", url: "https://tiktok.com/@austinhousetours", follower_count: 254000, snippet: `POV tours of multi-million dollar luxury estates, custom builds, and unique homes.` },
                { name: "STR Invest Austin", handle: "@str_invest_austin", url: "https://tiktok.com/@str_invest_austin", follower_count: 73000, snippet: `How to analyze short-term rentals, Airbnb management, and hospitality investing in Central Texas.` }
            ];
        }
        
        // Apply Vetting Filters to Mock Candidates
        let filteredCandidates = candidates.filter(c => {
            const fCount = parseInt(c.follower_count);
            if (minFollowers > 0 && fCount === 0) return false;
            return fCount >= minFollowers && fCount <= maxFollowers;
        }).slice(0, maxResults);
        
        if (!filteredCandidates.length) {
            discoveryGrid.innerHTML = `
                <div class="col-span-2 text-center py-12 text-slate-400 text-xs glass rounded-2xl border border-white/5 p-6 w-full">
                    <div class="flex justify-center mb-3">
                        <span class="p-2 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 text-base">⚠️</span>
                    </div>
                    <p class="mb-2 text-slate-200 font-bold text-sm">No mock profiles matched your criteria</p>
                    <p class="text-slate-400 leading-relaxed max-w-md mx-auto">Try widening your Advanced Vetting Filters (Min/Max Followers) to see the mock results.</p>
                </div>
            `;
            return;
        }
        
        discoveryGrid.innerHTML = filteredCandidates.map((c, index) => {
            let btnId = `import-scout-${index}`;
            return `
                <div class="glass p-5 rounded-2xl border border-white/5 hover:border-white/10 transition relative group flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-[10px] uppercase font-bold px-2 py-0.5 rounded-md border 
                                ${platform.toLowerCase() === 'linkedin'?'bg-blue-500/10 text-blue-400 border-blue-500/20':''}
                                ${platform.toLowerCase() === 'facebook'?'bg-indigo-500/10 text-indigo-400 border-indigo-500/20':''}
                                ${platform.toLowerCase() === 'x' || platform.toLowerCase() === 'twitter'?'bg-slate-400/10 text-slate-200 border-slate-400/20':''}
                                ${platform.toLowerCase() === 'instagram'?'bg-pink-500/10 text-pink-400 border-pink-500/20':''}
                                ${platform.toLowerCase() === 'tiktok'?'bg-rose-500/10 text-rose-400 border-rose-500/20':''}
                            ">${escapeHtml(platName)}</span>
                            <span class="text-xs text-slate-400 font-medium">
                                ${parseInt(c.follower_count) > 0 
                                    ? `${parseInt(c.follower_count).toLocaleString()} Followers` 
                                    : `<button onclick="crawlFollowersFromCard('${escapeHtml(c.url)}', '${escapeHtml(platName)}', this)" class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 border border-blue-500/20 text-[10px] font-bold cursor-pointer transition" title="Crawl profile for follower count"><span>🔍</span> Crawl for Followers</button>`
                                }
                            </span>
                        </div>
                        <h5 class="font-bold text-white text-sm">${escapeHtml(c.name)}</h5>
                        <p class="text-xs text-blue-400 mb-2 truncate font-semibold">${escapeHtml(c.handle)}</p>
                        <p class="text-[11px] text-slate-500 line-clamp-2 leading-relaxed mb-4">${escapeHtml(c.snippet)}</p>
                    </div>
                    <div class="flex gap-2">
                        <button id="${btnId}" onclick="importScoutCandidate('${escapeHtml(c.name)}', '${escapeHtml(platName)}', '${escapeHtml(c.handle)}', '${escapeHtml(c.url)}', ${c.follower_count}, '${btnId}')" class="flex-1 py-1.5 rounded-lg bg-blue-600/20 hover:bg-blue-600/30 border border-blue-500/30 text-blue-300 text-xs font-bold transition flex items-center justify-center gap-1">
                            📥 Import Candidate
                        </button>
                        <a href="${escapeHtml(c.url)}" target="_blank" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs font-bold border border-white/5 transition flex items-center justify-center">
                            🔗
                        </a>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Edit Influencer Modal hydration and rendering
    function openEditInfluencerModal(id) {
        const influencer = (window.trackedInfluencers || []).find(i => i.id === id);
        if (!influencer) {
            if (typeof toast === 'function') toast('Candidate details not found.', 'error');
            else alert('Candidate details not found.');
            return;
        }

        const container = document.getElementById('modal-container');
        container.innerHTML = `
            <div class="glass p-8 rounded-2xl w-full max-w-md">
                <h3 class="text-2xl font-bold mb-6">Edit Candidate</h3>
                <div class="space-y-4 text-left">
                    <div>
                        <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Candidate Name <span class="text-rose-400">*</span></label>
                        <input type="text" id="edit-inf-name" value="${escapeHtml(influencer.name)}" placeholder="Full Name" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Platform <span class="text-rose-400">*</span></label>
                        <select id="edit-inf-platform" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition appearance-none">
                            <option value="LinkedIn" ${influencer.platform === 'LinkedIn' ? 'selected' : ''}>LinkedIn</option>
                            <option value="Facebook" ${influencer.platform === 'Facebook' ? 'selected' : ''}>Facebook</option>
                            <option value="X" ${influencer.platform === 'X' || influencer.platform === 'Twitter' ? 'selected' : ''}>X (Twitter)</option>
                            <option value="Instagram" ${influencer.platform === 'Instagram' ? 'selected' : ''}>Instagram</option>
                            <option value="TikTok" ${influencer.platform === 'TikTok' ? 'selected' : ''}>TikTok</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Social Handle <span class="text-rose-400">*</span></label>
                        <input type="text" id="edit-inf-handle" value="${escapeHtml(influencer.handle)}" placeholder="@handle" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Profile URL</label>
                        <input type="url" id="edit-inf-url" value="${escapeHtml(influencer.url || '')}" placeholder="https://..." class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Follower Count</label>
                        <input type="number" id="edit-inf-followers" value="${parseInt(influencer.follower_count) || 0}" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Tags</label>
                        <input type="text" id="edit-inf-tags" value="${escapeHtml(influencer.tags || '')}" placeholder="comma separated" class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition">
                    </div>
                    <div>
                        <label class="block text-[10px] text-slate-500 uppercase font-bold tracking-wider mb-1">Notes</label>
                        <textarea id="edit-inf-notes" rows="3" placeholder="Internal notes..." class="w-full bg-slate-900 border border-white/10 rounded-xl px-4 py-2.5 text-sm text-white focus:border-blue-500/50 outline-none transition resize-none">${escapeHtml(influencer.notes || '')}</textarea>
                    </div>
                    <div id="edit-inf-error" class="hidden text-xs text-rose-400 font-semibold bg-rose-500/10 border border-rose-500/20 rounded-lg px-3 py-2"></div>
                    <div class="flex gap-3 pt-2">
                        <button onclick="submitEditInfluencer(${id})" class="flex-1 bg-blue-600 hover:bg-blue-500 py-2.5 rounded-xl font-bold text-sm transition">Save Changes</button>
                        <button onclick="closeModal()" class="flex-1 bg-slate-800 hover:bg-slate-700 py-2.5 rounded-xl text-sm transition">Cancel</button>
                    </div>
                </div>
            </div>`;
        container.classList.remove('hidden');
        document.getElementById('edit-inf-name').focus();
    }

    async function submitEditInfluencer(id) {
        const name = document.getElementById('edit-inf-name').value.trim();
        const platform = document.getElementById('edit-inf-platform').value;
        const handle = document.getElementById('edit-inf-handle').value.trim();
        const url = document.getElementById('edit-inf-url').value.trim();
        const follower_count = parseInt(document.getElementById('edit-inf-followers').value) || 0;
        const tags = document.getElementById('edit-inf-tags').value.trim();
        const notes = document.getElementById('edit-inf-notes').value.trim();
        const errEl = document.getElementById('edit-inf-error');
        errEl.classList.add('hidden');

        if (!name) { errEl.textContent = 'Candidate name is required.'; errEl.classList.remove('hidden'); return; }
        if (!handle) { errEl.textContent = 'Social handle is required.'; errEl.classList.remove('hidden'); return; }

        try {
            const res = await fetch('api/influencer_scout.php?action=update', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id,
                    name,
                    platform,
                    handle,
                    url,
                    follower_count,
                    tags,
                    notes
                })
            });
            const result = await res.json();
            if (result.success) {
                closeModal();
                if (typeof toast === 'function') {
                    toast('Candidate updated successfully!');
                } else {
                    alert('Candidate updated successfully!');
                }
                loadInfluencers();
            } else {
                errEl.textContent = result.error || 'Failed to update candidate.';
                errEl.classList.remove('hidden');
            }
        } catch (error) {
            errEl.textContent = 'Network error occurred.';
            errEl.classList.remove('hidden');
        }
    }

    // Assign globally to make accessible in inline onclick handlers
    window.openEditInfluencerModal = openEditInfluencerModal;
    window.submitEditInfluencer = submitEditInfluencer;

    // Auto load on start
    loadInfluencers();
</script>

