import os
import ftplib
import json
import urllib.request
import urllib.parse

# 1. FTP Sync
ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = os.environ.get("FTP_PASS", "")
local_dir = r"D:\sandbox\b2b_outreach_lamp"
remote_dir = "/b2b_outreach_lamp"

print("Connecting to FTP...")
try:
    ftp = ftplib.FTP(ftp_host)
    ftp.login(ftp_user, ftp_pass)
except Exception as e:
    print(f"FTP Connection failed: {e}")
    exit(1)

def ensure_remote_dir(ftp, remote_path):
    # Ensure directory exists
    parts = [p for p in remote_path.split('/') if p]
    current = ""
    for part in parts:
        current += "/" + part
        try:
            ftp.cwd(current)
        except ftplib.error_perm:
            try:
                ftp.mkd(current)
                ftp.cwd(current)
            except Exception as e:
                print(f"Failed to create dir {current}: {e}")

print("Syncing files...")
for root, dirs, files in os.walk(local_dir):
    if '.git' in root or 'vendor' in root:
        continue
    
    # Calculate relative path
    rel_path = os.path.relpath(root, local_dir).replace('\\', '/')
    if rel_path == '.':
        current_remote = remote_dir
    else:
        current_remote = f"{remote_dir}/{rel_path}"
    
    ensure_remote_dir(ftp, current_remote)
    
    for file in files:
        if file.endswith('.py') or file == 'refactor_db.php':
            continue
        local_file = os.path.join(root, file)
        remote_file = file
        
        try:
            with open(local_file, 'rb') as f:
                ftp.storbinary(f'STOR {remote_file}', f)
        except Exception as e:
            print(f"Failed to upload {local_file}: {e}")

ftp.quit()
print("Upload complete!")

# 2. Test Endpoint
url = 'http://lookoverhere.xyz/b2b_outreach_lamp/api/trigger_task.php'
print(f"\nTesting Endpoint: {url}")

payload = json.dumps({
    "lead_id": 1,
    "task_type": "Qualify"
}).encode('utf-8')

req = urllib.request.Request(url, data=payload, headers={'Content-Type': 'application/json'})

try:
    with urllib.request.urlopen(req) as response:
        resp_data = response.read().decode('utf-8')
        print(f"Response Status: {response.status}")
        print(f"Response Body: {resp_data}")
except urllib.error.URLError as e:
    print(f"Request failed: {e}")
    if hasattr(e, 'read'):
        print(f"Error Body: {e.read().decode('utf-8')}")

