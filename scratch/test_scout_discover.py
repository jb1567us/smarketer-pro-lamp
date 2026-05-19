import urllib.request
import json

url = "http://lookoverhere.xyz/b2b_outreach_lamp/api/influencer_scout.php?action=discover"
payload = {
    "niche": "Real Estate",
    "location": "Austin",
    "platform": "linkedin"
}

headers = {
    "Content-Type": "application/json"
}

req = urllib.request.Request(url, data=json.dumps(payload).encode("utf-8"), headers=headers, method="POST")

try:
    print(f"Sending discover request for LinkedIn...")
    with urllib.request.urlopen(req, timeout=30) as response:
        status = response.status
        body = response.read().decode("utf-8")
        print(f"Status Code: {status}")
        try:
            parsed = json.loads(body)
            print("Response parsed as JSON successfully!")
            print(json.dumps(parsed, indent=2)[:1000])
        except Exception as je:
            print("Response is NOT valid JSON!")
            print(body[:2000])
except Exception as e:
    print(f"Error calling discover endpoint: {e}")
