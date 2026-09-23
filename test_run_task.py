import urllib.request
import urllib.error

url = 'http://lookoverhere.xyz/b2b_outreach_lamp/api/run_task.php'
req = urllib.request.Request(url)
try:
    with urllib.request.urlopen(req) as r:
        print(f"Status: {r.getcode()}")
        print(r.read().decode())
except urllib.error.HTTPError as e:
    print(f"Status: {e.code}")
    print(e.read().decode())
    print(e.headers)
