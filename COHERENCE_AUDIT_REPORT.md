# Software Coherence Audit: Mass Tools & Proxy Fleet Management
**Auditor Mode**: Lead QA + Product Manager + Systems Architect

---

## 1. System Map

- **Pages / Views**:
  - `mass_tools.php`: Main workspace containing the Resource Extraction Setup (Search, Persona, Queries, Background Job controls), Live Status monitor, Visual Dork Generator, Strategy Guide, and the Proxy Fleet Manager card.
- **Components**:
  - Sidebar Navigation (`index.php`, `mass_tools.php`, `agent_lab.php`, `influencer_scout.php`).
- **API Routes**:
  - `api/mass_tools.php?action=harvest`: Starts synchronous or queued search operations.
  - `api/mass_tools.php?action=add_proxies` (Currently Dead): Purports to populate the `proxies` database table via `ProxyManager`.
  - `api/mass_tools.php?action=stats`: Retrieves proxy counts grouped by status.
  - `api/settings.php`: Saves raw application-wide values (including raw proxy lists).
- **Database Tables**:
  - `settings`: Holds generic key-value store for app configuration (like `active_search_provider` and raw `proxies` string).
  - `proxies`: Structured table tracking rotated proxy URLs, status (Active/Dead), last used timestamps, and failure counts.
- **Main Workflows**:
  - **Dork Creation**: Visual Dork Generator -> Dork list copy -> Harvester text field.
  - **Acquisition Campaign**: Enter queries -> Toggle background job -> Trigger `SimpleHarvester` -> Write leads to database.
  - **Proxy Addition**: Paste proxies -> Click save -> Update Settings -> (Expected) Rotate requests across proxy list.
- **External Services**:
  - DuckDuckGo (Free web scraping)
  - SearXNG (Public and local search nodes)
  - ScrapingAnt, Tavily, Exa, Gemini (API search providers)
- **Disconnected / Missing Links (Wiring Gaps)**:
  - **UI-to-DB Proxy Mismatch**: Pasting proxies in the Proxy Fleet Manager and clicking save *only* writes to the `settings` table as a text block. It never populates the structured `proxies` database table, leaving the `proxies` table completely empty.
  - **Unutilized Proxy Fleet**: Web scraper fetcher (`ExtractionEngine::fetchPage`) and free search providers (`DuckDuckGoProvider`, `DirectBrowserProvider`) run cURL requests directly from the host IP, bypassing the `ProxyManager` completely.
  - **No Failure Reporting**: Because proxies are never rotated, `ProxyManager::reportFailure()` is never called, meaning dead proxies are never pruned or flagged as "Dead".

---

## 2. Software Coherence Audit Report

### 🔴 Critical: Proxy Fleet Manager is a Dead End
- **Feature**: Proxy Fleet Manager Card
- **Problem**: Saving proxies writes to `settings` table but never inserts them into the structured `proxies` table.
- **Affected Files**:
  - [mass_tools.php](file:///d:/sandbox/b2b_outreach_lamp/mass_tools.php)
  - [api/mass_tools.php](file:///d:/sandbox/b2b_outreach_lamp/api/mass_tools.php)
- **Evidence**:
  - `mass_tools.php` lines 288-294:
    ```javascript
    async function saveProxies() {
        const list = document.getElementById('proxy_list').value;
        await fetch('api/settings.php', { ... body: JSON.stringify({ proxies: list }) });
    }
    ```
    Saves to generic `settings` API, bypassing `ProxyManager::addProxies()`.
- **Why it matters**: Users paste private proxy fleets believing their requests are anonymous and protected, but the backend is completely unaware of these proxies, making direct requests that leak the hosting server's IP and trigger immediate firewalls or blocklists.
- **Recommended Fix**: Update `saveProxies()` in `mass_tools.php` to post to `api/mass_tools.php?action=add_proxies` which will save the settings string *and* refresh the structured `proxies` database table.
- **Fix Type**: Frontend / Backend / Database

---

### 🔴 Critical: Scrapers & Harvesters Bypass Proxy Fleet
- **Feature**: Core Web Scraping & Free Search Providers
- **Problem**: `ExtractionEngine::fetchPage()` and scrapers like `DuckDuckGoProvider` perform plain direct cURL requests with zero proxy routing.
- **Affected Files**:
  - [ExtractionEngine.php](file:///d:/sandbox/b2b_outreach_lamp/includes/ExtractionEngine.php)
  - [DuckDuckGoProvider.php](file:///d:/sandbox/b2b_outreach_lamp/includes/Search/DuckDuckGoProvider.php)
  - [DirectBrowserProvider.php](file:///d:/sandbox/b2b_outreach_lamp/includes/Search/DirectBrowserProvider.php)
- **Evidence**:
  - `ExtractionEngine.php` lines 288-303:
    ```php
    private static function fetchPage($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [ ... CURLOPT_USERAGENT => 'Mozilla/5.0 ...' ]);
        $html = curl_exec($ch);
    }
    ```
    No reference to `ProxyManager` or `CURLOPT_PROXY`.
- **Why it matters**: Mass harvesting requires scraping dozens of target company domains (via the deep crawl contact links) and DuckDuckGo search queries. Without rotating proxies, the host server IP will be instantly banned by cloud protection layers (e.g., Cloudflare, AWS WAF, DDG rate limiting), rendering the Harvester completely useless.
- **Recommended Fix**: Integrate `ProxyManager` rotation into `fetchPage()`, `DuckDuckGoProvider::search()`, and `DirectBrowserProvider::search()`, enabling proxy injection and reporting failures.
- **Fix Type**: Backend / Architecture

---

### 🟡 High: Broken Verification Script Contract
- **Feature**: Developer Tools
- **Problem**: `verify_mass.php` tries to call `SimpleHarvester::addTask` and `SimpleHarvester::run`, neither of which exist.
- **Affected Files**:
  - [verify_mass.php](file:///d:/sandbox/b2b_outreach_lamp/verify_mass.php)
  - [SimpleHarvester.php](file:///d:/sandbox/b2b_outreach_lamp/includes/SimpleHarvester.php)
- **Evidence**:
  - `verify_mass.php` lines 21-30:
    ```php
    $harvester = new SimpleHarvester($pdo);
    $harvester->addTask('test query 1');
    $results = $harvester->run();
    ```
    `SimpleHarvester.php` has only `harvest($query, $limit)` method.
- **Why it matters**: Broken developer testing scripts prevent reliable automated QA validation and result in false positives or unrunnable test pipelines.
- **Recommended Fix**: Refactor `verify_mass.php` to run a real synchronous search query through `SimpleHarvester::harvest()` and assert outputs, ensuring the contract matches codebase reality.
- **Fix Type**: Backend / Architecture

---

## 3. Actionable App Punch List

- [ ] **1. Connect UI Proxy Saving to Structured DB Loader (Critical)**
  - Update `saveProxies()` in `mass_tools.php` to POST to `api/mass_tools.php?action=add_proxies`.
  - Refactor `api/mass_tools.php?action=add_proxies` to:
    1. Persist raw text list in `settings` table (for UI reloads).
    2. Flush the current `proxies` database table (`DELETE FROM proxies`).
    3. Import the list into `proxies` using `ProxyManager::addProxies()`.

- [ ] **2. Inject Proxy Fleet Rotation into cURL Fetcher (Critical)**
  - Update `ExtractionEngine::fetchPage($url)` to fetch an active proxy from `ProxyManager`.
  - Apply `CURLOPT_PROXY` if a proxy exists.
  - Wrap the cURL execute with success checking: if request fails on a proxy, call `ProxyManager::reportFailure($proxy)`.

- [ ] **3. Apply Proxy Fleet Rotation to Search Providers (Critical)**
  - Load `ProxyManager` inside `DuckDuckGoProvider` and `DirectBrowserProvider`.
  - Fetch proxy prior to search cURL execution.
  - Automatically flag dead proxies via `reportFailure()` on HTTP failure.

- [ ] **4. Fix Developer Verification Script (High)**
  - Align `verify_mass.php` calling signatures with the real `SimpleHarvester` methods.
