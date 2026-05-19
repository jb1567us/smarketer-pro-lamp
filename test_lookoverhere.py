import ftplib
import urllib.request

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

try:
    ftp.cwd("/lookoverhere.xyz")
    with open("hello.php", "wb") as f:
        f.write(b"<?php echo 'HELLO_FROM_LOOKOVERHERE_DIR'; ?>")
    
    with open("hello.php", "rb") as f:
        ftp.storbinary("STOR hello.php", f)
except Exception as e:
    print(f"Error: {e}")

ftp.quit()

url = 'http://lookoverhere.xyz/hello.php'
print(f"Testing {url}")
try:
    with urllib.request.urlopen(url) as response:
        print(response.read().decode('utf-8'))
except urllib.error.URLError as e:
    print(f"Error: {e}")
    if hasattr(e, 'read'):
        print(f"Error Body: {e.read().decode('utf-8')}")
