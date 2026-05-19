import urllib.request
import sys
import io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

url = "http://lookoverhere.xyz/b2b_outreach_lamp/verify_providers.php"
try:
    print("Triggering verify_providers.php...")
    with urllib.request.urlopen(url, timeout=30) as response:
        body = response.read().decode("utf-8")
        print("Response:\n" + body)
except Exception as e:
    print(f"Error: {e}")
