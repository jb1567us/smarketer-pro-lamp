import urllib.request
import urllib.error

url = 'http://lookoverhere.xyz/b2b_outreach_lamp/api/run_task.php'
req = urllib.request.Request(url)
try:
    urllib.request.urlopen(req)
except urllib.error.HTTPError as e:
    print(f"Status: {e.code}")
    print(e.headers)
