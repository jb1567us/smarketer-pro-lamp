import ftplib
import os
import urllib.request
import urllib.error

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = os.environ.get("FTP_PASS", "")

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

directories = [
    "/",
    "/public_html",
    "/lookoverhere.xyz",
    "/lookuphere.xyz",
    "/b2b_outreach_lamp",
    "/public_html/b2b_outreach_lamp",
]

for dir_path in directories:
    try:
        ftp.cwd(dir_path)
        with open("find_root.php", "wb") as f:
            f.write(f"<?php echo 'ROOT_IS_{dir_path}'; ?>".encode('utf-8'))
        
        with open("find_root.php", "rb") as f:
            ftp.storbinary("STOR find_root.php", f)
        print(f"Uploaded to {dir_path}")
    except Exception as e:
        print(f"Failed to upload to {dir_path}: {e}")

ftp.quit()

print("\nTesting HTTP...")
urls = [
    "http://lookoverhere.xyz/find_root.php",
    "http://lookoverhere.xyz/b2b_outreach_lamp/find_root.php",
    "http://lookuphere.xyz/find_root.php"
]

for url in urls:
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        with urllib.request.urlopen(req) as r:
            print(f"{url} -> 200 OK: {r.read().decode('utf-8')}")
    except urllib.error.HTTPError as e:
        print(f"{url} -> {e.code}")
    except urllib.error.URLError as e:
        print(f"{url} -> URLError: {e.reason}")
