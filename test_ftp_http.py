import ftplib
import os
import urllib.request
import time

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = os.environ.get("FTP_PASS", "")

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

ftp.cwd("/public_html/b2b_outreach_lamp/api")

# Read the file
with open("temp.php", "wb") as f:
    ftp.retrbinary("RETR trigger_task.php", f.write)

# Modify it
with open("temp.php", "rb") as f:
    content = f.read()

content = content.replace(b"<?php", b"<?php\necho 'HELLO_FROM_FTP_MODIFICATION';\n")

with open("temp_modified.php", "wb") as f:
    f.write(content)

# Upload it back
with open("temp_modified.php", "rb") as f:
    ftp.storbinary("STOR trigger_task.php", f)

ftp.quit()

print("Uploaded modified file, waiting 2s...")
time.sleep(2)

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
