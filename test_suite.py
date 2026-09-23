# -*- coding: utf-8 -*-
"""
Comprehensive Playwright Test Suite - B2B Outreach LAMP
Target: http://lookoverhere.xyz/b2b_outreach_lamp/

Coverage:
  1. Dashboard (index.php) - page load, stat cards, tabs, nav links
  2. API /api/stats.php          - JSON shape
  3. API /api/settings.php (GET) - JSON shape
  4. API /api/settings.php (PUT) - save round-trip
  5. API /api/leads.php          - returns list
  6. API /api/mass_tools.php?action=stats - proxy stats
  7. API /api/agent_chat.php     - POST with persona + context
  8. Agent Lab UI                - page load, dropdown not duplicated, form submit
  9. Mass Tools UI               - page load, harvest form visible, proxy form visible
 10. Influencer Scout UI         - page load, form elements
"""

import json
import time
import sys
import io

# Force UTF-8 output on Windows (avoids cp1252 UnicodeEncodeError)
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

from playwright.sync_api import sync_playwright

BASE = "http://lookoverhere.xyz/b2b_outreach_lamp"

PASS = "[PASS]"
FAIL = "[FAIL]"
WARN = "[WARN]"
INFO = "[INFO]"

results = []


def record(name, passed, detail=""):
    tag = PASS if passed else FAIL
    print(f"  {tag} {name}" + (f"  ->  {detail}" if detail else ""))
    results.append({"name": name, "passed": passed, "detail": detail})


def section(title):
    print(f"\n{'='*60}")
    print(f"  {title}")
    print(f"{'='*60}")


# ---------------------------------------------------------------------------
# HTTP-level API tests (fast, no browser needed)
# ---------------------------------------------------------------------------
def test_api_endpoints(page):
    section("API Endpoint Tests (HTTP)")

    apis = [
        ("GET /api/stats.php",        f"{BASE}/api/stats.php",                     "GET",  None),
        ("GET /api/settings.php",     f"{BASE}/api/settings.php",                  "GET",  None),
        ("GET /api/leads.php",        f"{BASE}/api/leads.php",                     "GET",  None),
        ("GET /api/mass_tools proxy", f"{BASE}/api/mass_tools.php?action=stats",   "GET",  None),
        ("GET /api/campaigns.php",    f"{BASE}/api/campaigns.php",                 "GET",  None),
    ]

    for label, url, method, body in apis:
        try:
            if method == "GET":
                resp = page.request.get(url, timeout=15000)
            else:
                resp = page.request.post(url, data=body, timeout=15000)

            ok = resp.ok
            content_type = resp.headers.get("content-type", "")
            is_json = "json" in content_type
            try:
                data = resp.json()
                has_key = "success" in data
                record(label, ok and is_json and has_key,
                       f"status={resp.status} success={data.get('success')}")
            except Exception:
                record(label, False, f"status={resp.status} non-JSON: {resp.text()[:120]}")
        except Exception as e:
            record(label, False, str(e)[:120])

    # POST agent_chat (Researcher)
    try:
        resp = page.request.post(
            f"{BASE}/api/agent_chat.php",
            data=json.dumps({"persona": "Researcher", "context": "Acme Corp sells industrial cleaning supplies."}),
            headers={"Content-Type": "application/json"},
            timeout=30000,
        )
        ok = resp.ok
        data = resp.json()
        record("POST /api/agent_chat.php (Researcher)", ok and data.get("success") in [True, False],
               f"status={resp.status} success={data.get('success')} has_meta={'meta' in data}")
    except Exception as e:
        record("POST /api/agent_chat.php (Researcher)", False, str(e)[:120])

    # POST agent_chat (WordPress Expert - Staged Fallback check)
    try:
        resp = page.request.post(
            f"{BASE}/api/agent_chat.php",
            data=json.dumps({"persona": "WordPress Expert", "context": "Staged post testing context."}),
            headers={"Content-Type": "application/json"},
            timeout=30000,
        )
        ok = resp.ok
        data = resp.json()
        has_staged = False
        if ok and data.get("success"):
            response_text = data.get("data", {}).get("response", "")
            has_staged = "WordPress Agent Staged" in response_text or "Successfully Published" in response_text
        record("POST /api/agent_chat.php (WordPress Expert Staged)", ok and has_staged,
               f"status={resp.status} success={data.get('success')} response_preview='{data.get('data', {}).get('response', '')[:60]}...'")
    except Exception as e:
        record("POST /api/agent_chat.php (WordPress Expert Staged)", False, str(e)[:120])

    # POST agent_chat (Intent Analyst - Raw Text check)
    try:
        resp = page.request.post(
            f"{BASE}/api/agent_chat.php",
            data=json.dumps({"persona": "Intent Analyst", "context": "We are hiring STR managers in Austin TX. Contact us at jobs@rentals.com"}),
            headers={"Content-Type": "application/json"},
            timeout=30000,
        )
        ok = resp.ok
        data = resp.json()
        has_scoring = False
        if ok and data.get("success"):
            response_text = data.get("data", {}).get("response", "")
            has_scoring = "Intent Analysis Report" in response_text or "Calculated Score" in response_text
        record("POST /api/agent_chat.php (Intent Analyst Text)", ok and has_scoring,
               f"status={resp.status} success={data.get('success')} response_preview='{data.get('data', {}).get('response', '')[:60]}...'")
    except Exception as e:
        record("POST /api/agent_chat.php (Intent Analyst Text)", False, str(e)[:120])

    # POST agent_chat (Intent Analyst - JSON payload check)
    try:
        json_payload = {
            "tech_stack": ["shopify", "hubspot"],
            "content": "hiring expansion",
            "email": "partner@shop.com"
        }
        resp = page.request.post(
            f"{BASE}/api/agent_chat.php",
            data=json.dumps({"persona": "Intent Analyst", "context": json.dumps(json_payload)}),
            headers={"Content-Type": "application/json"},
            timeout=30000,
        )
        ok = resp.ok
        data = resp.json()
        has_score = False
        score_val = 0
        if ok and data.get("success"):
            response_text = data.get("data", {}).get("response", "")
            has_score = "Final Score" in response_text
            # Try to parse score from text e.g. "**80 / 100**" or similar
            if "80" in response_text:
                score_val = 80
        record("POST /api/agent_chat.php (Intent Analyst JSON)", ok and has_score,
               f"status={resp.status} score={score_val} success={data.get('success')}")
    except Exception as e:
        record("POST /api/agent_chat.php (Intent Analyst JSON)", False, str(e)[:120])


    # POST mass_tools harvest (expect it to at least not crash)
    try:
        resp = page.request.post(
            f"{BASE}/api/mass_tools.php?action=harvest",
            data=json.dumps({"keywords": "property management Texas"}),
            headers={"Content-Type": "application/json"},
            timeout=30000,
        )
        data = resp.json()
        record("POST /api/mass_tools harvest", resp.ok,
               f"status={resp.status} success={data.get('success')}")
    except Exception as e:
        record("POST /api/mass_tools harvest", False, str(e)[:120])

    # POST mass_tools add_proxies
    try:
        resp = page.request.post(
            f"{BASE}/api/mass_tools.php?action=add_proxies",
            data=json.dumps({"proxies": "127.0.0.1:8080\n192.168.1.1:3128"}),
            headers={"Content-Type": "application/json"},
            timeout=15000,
        )
        data = resp.json()
        record("POST /api/mass_tools add_proxies", resp.ok and data.get("success"),
               f"status={resp.status} count={data.get('count')}")
    except Exception as e:
        record("POST /api/mass_tools add_proxies", False, str(e)[:120])


# ---------------------------------------------------------------------------
# Dashboard UI tests
# ---------------------------------------------------------------------------
def test_dashboard(page):
    section("Dashboard (index.php)")

    page.goto(f"{BASE}/", wait_until="networkidle")

    # Page title
    title = page.title()
    record("Page title contains 'Smarketer'", "Smarketer" in title, f"title='{title}'")

    # Stat cards loaded
    stat_ids = ["stat-total_leads", "stat-qualified", "stat-contacted", "stat-converted"]
    for sid in stat_ids:
        el = page.locator(f"#{sid}")
        el.wait_for(timeout=8000)
        text = el.inner_text().strip()
        record(f"Stat card #{sid} renders", el.is_visible(), f"value='{text}'")

    # Navigation tabs exist
    for tab in ["tab-leads-btn", "tab-campaigns-btn", "tab-settings-btn"]:
        visible = page.locator(f"#{tab}").is_visible()
        record(f"Tab button #{tab} present", visible)

    # Leads tab is active by default
    leads_tab = page.locator("#leads-tab")
    record("Leads tab visible by default", leads_tab.is_visible())

    # Click Campaigns tab
    page.click("#tab-campaigns-btn")
    page.wait_for_timeout(800)
    record("Campaigns tab shows after click",
           page.locator("#campaigns-tab").is_visible())

    # Click Settings tab
    page.click("#tab-settings-btn")
    page.wait_for_timeout(500)
    record("Settings tab shows after click",
           page.locator("#settings-tab").is_visible())

    # Settings: operational mode select exists
    mode_sel = page.locator("#setting-operational_mode")
    record("Settings: operational mode dropdown present", mode_sel.is_visible())

    # Header navigation links
    for href, label in [("index.php?tab=agent", "Agent Lab"), ("index.php?tab=mass", "Mass Tools"), ("index.php?tab=influencer", "Scout")]:
        link = page.locator(f"a[href='{href}']")
        record(f"Nav link '{label}' present", link.count() > 0)

    # New Campaign button
    record("New Campaign button present",
           page.locator("button:has-text('New Campaign')").is_visible())

    # Import Leads button
    record("Import Leads button present",
           page.locator("button:has-text('Import Leads')").is_visible())

    # Leads body table rendered – navigate back to leads tab first
    page.click("#tab-leads-btn")
    page.wait_for_timeout(500)
    leads_body = page.locator("#leads-body")
    record("Leads table body rendered", leads_body.is_visible())


# ---------------------------------------------------------------------------
# Agent Lab UI tests
# ---------------------------------------------------------------------------
def test_agent_lab(page):
    section("Agent Lab (agent_lab.php)")

    page.goto(f"{BASE}/agent_lab.php", wait_until="networkidle")

    # Page title
    title = page.title()
    record("Agent Lab page title present", len(title) > 0, f"title='{title}'")

    # CRITICAL: Only ONE select with id="agent-persona" must exist
    persona_count = page.locator("#agent-persona").count()
    record("agent-persona select NOT duplicated (exactly 1)", persona_count == 1,
           f"count={persona_count}")

    # Dropdown has options
    option_count = page.locator("#agent-persona option").count()
    record("agent-persona has selectable options", option_count > 0,
           f"option_count={option_count}")

    # Context textarea exists
    record("Context textarea present",
           page.locator("#agent-context").is_visible())

    # Instruction textarea exists
    record("Instruction textarea present",
           page.locator("#agent-instruction").is_visible())

    # Run Agent button exists
    run_btn = page.locator("#run-btn")
    record("Run Agent button present", run_btn.is_visible())

    # Mode indicator loads (should say PRODUCTION or SIMULATION, not 'Loading...')
    mode_el = page.locator("#lab-mode-indicator")
    page.wait_for_timeout(3000)  # allow async fetch
    mode_text = mode_el.inner_text().strip()
    record("Mode indicator loaded (not stuck 'Loading...')",
           "loading" not in mode_text.lower(),
           f"mode='{mode_text}'")

    # Simulate picking a persona and providing context
    page.select_option("#agent-persona", "Researcher")
    selected = page.eval_on_selector("#agent-persona", "el => el.value")
    record("Can select 'Researcher' persona", selected == "Researcher",
           f"selected='{selected}'")

    page.fill("#agent-context", "Test company: Acme Corp, B2B industrial supplier.")

    # Click Run Agent and wait for response (allow up to 30s for LLM)
    print(f"  {INFO} Submitting agent request (may take up to 30s)...")
    run_btn.click()
    try:
        output_area = page.locator("#output-area")
        output_area.wait_for(state="visible", timeout=35000)
        result_text = page.locator("#agent-result").inner_text()
        record("Agent output area appeared after submit", True,
               f"result_len={len(result_text)} chars")

        # Check meta span has persona info
        meta_text = page.locator("#output-meta").inner_text()
        record("Output meta shows 'Researcher'", "Researcher" in meta_text,
               f"meta='{meta_text}'")
    except Exception as e:
        # Check if an alert/error appeared instead
        record("Agent output area appeared after submit", False,
               f"timeout or error: {str(e)[:100]}")

    # Now simulate picking 'WordPress Expert' and run
    try:
        print(f"  {INFO} Selecting 'WordPress Expert' and running staged publish...")
        page.select_option("#agent-persona", "WordPress Expert")
        page.fill("#agent-context", "Special customized staged outreach content context for E2E.")
        page.fill("#agent-instruction", "Draft mode please.")
        run_btn.click()
        
        output_area = page.locator("#output-area")
        output_area.wait_for(state="visible", timeout=35000)
        result_text = page.locator("#agent-result").inner_text()
        
        is_staged = "WordPress Agent Staged" in result_text or "Successfully Published" in result_text
        record("Agent lab E2E: WordPress Expert output valid", is_staged,
               f"result_len={len(result_text)} chars")
    except Exception as e:
        record("Agent lab E2E: WordPress Expert output valid", False, str(e)[:120])



# ---------------------------------------------------------------------------
# Mass Tools UI tests
# ---------------------------------------------------------------------------
def test_mass_tools(page):
    section("Mass Tools (mass_tools.php)")

    page.goto(f"{BASE}/mass_tools.php", wait_until="networkidle")

    title = page.title()
    record("Mass Tools page loads", len(title) > 0, f"title='{title}'")

    # Harvest section
    record("Harvest keyword input present",
           page.locator("#queries, #harvest-keywords, #keywords, textarea").count() > 0)

    # Start Harvest button
    harvest_btn = page.locator("button:has-text('Harvest'), button:has-text('Start Harvest'), button:has-text('Deploy')")
    record("Start Harvest button present", harvest_btn.count() > 0)

    # Proxy list textarea
    proxy_list = page.locator("#proxy_list, #proxy-list")
    record("Proxy list textarea present", proxy_list.count() > 0)

    # Save Proxies button
    save_proxy_btn = page.locator("button:has-text('Save'), button:has-text('Add Proxy')")
    record("Save Proxies button present", save_proxy_btn.count() > 0)

    # Proxy stats section
    proxy_stats = page.locator("#proxy-health-score, #proxy-stats, #stats")
    record("Proxy stats section present", proxy_stats.count() > 0)

    # Trigger a harvest with a keyword
    kw_inputs = page.locator("#queries, #harvest-keywords, #keywords")
    if kw_inputs.count() > 0:
        kw_inputs.first.fill("vacation rental management Austin TX")
        harvest_btn.first.click()
        page.wait_for_timeout(5000)
        results_area = page.locator("#status-container, #harvest-results, #harvest-output")
        is_vis = results_area.count() > 0
        record("Harvest results area exists after submit", is_vis)


# ---------------------------------------------------------------------------
# Influencer Scout UI tests
# ---------------------------------------------------------------------------
def test_influencer_scout(page):
    section("Influencer Scout (influencer_scout.php)")

    page.goto(f"{BASE}/influencer_scout.php", wait_until="networkidle")

    title = page.title()
    record("Influencer Scout page loads", len(title) > 0, f"title='{title}'")

    # Some form controls should be present
    inputs = page.locator("input, textarea, select").count()
    record("Scout page has form controls", inputs > 0, f"controls={inputs}")

    buttons = page.locator("button").count()
    record("Scout page has buttons", buttons > 0, f"buttons={buttons}")


# ---------------------------------------------------------------------------
# Settings save/load round-trip test
# ---------------------------------------------------------------------------
def test_settings_roundtrip(page):
    section("Settings Save / Load Round-trip")

    try:
        # Read current settings
        resp = page.request.get(f"{BASE}/api/settings.php", timeout=10000)
        data = resp.json()
        record("Settings GET returns success", data.get("success"), f"keys={list(data.get('data', {}).keys())}")

        # PUT a new SMTP host
        test_value = f"smtp.test-{int(time.time())}.example.com"
        put_resp = page.request.fetch(
            f"{BASE}/api/settings.php",
            method="PUT",
            data=json.dumps({"smtp_host": test_value}),
            headers={"Content-Type": "application/json"},
        )
        put_data = put_resp.json()
        record("Settings PUT returns success", put_data.get("success"),
               f"response={json.dumps(put_data)[:80]}")

        # Read back and verify
        verify = page.request.get(f"{BASE}/api/settings.php", timeout=10000)
        v_data = verify.json()
        saved = v_data.get("data", {}).get("smtp_host", "")
        record("Settings PUT value persisted on GET", saved == test_value,
               f"expected='{test_value}' got='{saved}'")
    except Exception as e:
        record("Settings round-trip", False, str(e)[:120])


# ---------------------------------------------------------------------------
# Console error scan (runs after each page visit)
# ---------------------------------------------------------------------------
def test_console_errors(page, url, label):
    errors = []
    page.on("console", lambda msg: errors.append(msg) if msg.type == "error" else None)
    page.on("pageerror", lambda err: errors.append(err))
    page.goto(url, wait_until="networkidle")
    page.wait_for_timeout(2000)
    record(f"No console errors on {label}",
           len(errors) == 0,
           f"{len(errors)} error(s): " + "; ".join(str(e)[:60] for e in errors[:3]))


# ---------------------------------------------------------------------------
# Main runner
# ---------------------------------------------------------------------------
def main():
    print("\n" + "="*60)
    print("  B2B Outreach LAMP - Full Test Suite")
    print(f"  Target: {BASE}")
    print("="*60)

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True)
        context = browser.new_context()
        page = context.new_page()

        try:
            test_api_endpoints(page)
            test_dashboard(page)
            test_agent_lab(page)
            test_mass_tools(page)
            test_influencer_scout(page)
            test_settings_roundtrip(page)

            # Console error sweeps
            section("Console Error Sweep")
            pages_to_scan = [
                (f"{BASE}/",                    "index.php"),
                (f"{BASE}/agent_lab.php",       "agent_lab.php"),
                (f"{BASE}/mass_tools.php",      "mass_tools.php"),
                (f"{BASE}/influencer_scout.php", "influencer_scout.php"),
            ]
            for url, label in pages_to_scan:
                scan_page = context.new_page()
                test_console_errors(scan_page, url, label)
                scan_page.close()

        finally:
            browser.close()

    # Summary
    section("TEST SUMMARY")
    total  = len(results)
    passed = sum(1 for r in results if r["passed"])
    failed = total - passed

    for r in results:
        if not r["passed"]:
            print(f"  {FAIL} {r['name']}" + (f"  ->  {r['detail']}" if r["detail"] else ""))

    print(f"\n  Total: {total}  |  {PASS} {passed}  |  {FAIL} {failed}")

    if failed:
        print(f"\n  {failed} test(s) failed - see details above.\n")
        sys.exit(1)
    else:
        print(f"\n  All tests passed!\n")


if __name__ == "__main__":
    main()
