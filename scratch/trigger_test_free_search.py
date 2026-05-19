import urllib.request
import sys
import io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

url = "http://lookoverhere.xyz/b2b_outreach_lamp/test_free_search.php"
try:
    print("Triggering test_free_search.php...")
    with urllib.request.urlopen(url, timeout=30) as response:
        body = response.read().decode("utf-8")
        print("Response:\n" + body)
except Exception as e:
    print(f"Error: {e}")
