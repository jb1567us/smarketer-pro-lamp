import ftplib
import os
import urllib.request

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = os.environ.get("FTP_PASS", "")

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

ftp.cwd("/public_html/b2b_outreach_lamp")
with open(r"D:\sandbox\b2b_outreach_lamp\.user.ini", "rb") as f:
    ftp.storbinary("STOR .user.ini", f)
ftp.quit()

url = 'http://lookoverhere.xyz/b2b_outreach_lamp/api/trigger_task.php'
print(f"Testing {url}")
req = urllib.request.Request(url, data=b'{"lead_id": 1, "task_type": "Qualify"}', headers={'Content-Type': 'application/json'})
try:
    with urllib.request.urlopen(req) as response:
        print(response.read().decode('utf-8'))
except urllib.error.URLError as e:
    print(f"Error: {e}")
    if hasattr(e, 'read'):
        print(f"Error Body: {e.read().decode('utf-8')}")
