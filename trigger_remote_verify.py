import urllib.request
import sys

sys.stdout.reconfigure(encoding='utf-8')

urls = [
    "http://lookoverhere.xyz/b2b_outreach_lamp/verify_providers.php",
    "http://lookoverhere.xyz/b2b_outreach_lamp/cron/process_queue.php"
]

for url in urls:
    print(f"\n[HTTP] Triggering: {url} ...")
    try:
        with urllib.request.urlopen(url) as response:
            print(f"[HTTP] Status: {response.status}")
            print("[HTTP] Output:")
            print("-" * 60)
            print(response.read().decode('utf-8'))
            print("-" * 60)
    except Exception as e:
        print(f"[ERROR] Failed to trigger {url}: {e}")
