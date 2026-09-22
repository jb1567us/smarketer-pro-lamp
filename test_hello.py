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

with open("hello.php", "w") as f:
    f.write("<?php echo 'HELLO_WORLD'; ?>")

with open("hello.php", "rb") as f:
    ftp.storbinary("STOR hello.php", f)

ftp.quit()

time.sleep(2)
url = 'http://lookoverhere.xyz/b2b_outreach_lamp/api/hello.php'
print(f"Testing {url}")
try:
    with urllib.request.urlopen(url) as response:
        print(response.read().decode('utf-8'))
except urllib.error.URLError as e:
    print(f"Error: {e}")
    if hasattr(e, 'read'):
        print(f"Error Body: {e.read().decode('utf-8')}")
