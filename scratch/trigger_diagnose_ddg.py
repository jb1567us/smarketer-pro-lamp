import urllib.request

url = "http://lookoverhere.xyz/b2b_outreach_lamp/diagnose_ddg.php"
try:
    print("Triggering diagnose_ddg.php...")
    with urllib.request.urlopen(url, timeout=30) as response:
        body = response.read().decode("utf-8")
        print("Response:\n" + body)
except Exception as e:
    print(f"Error: {e}")
