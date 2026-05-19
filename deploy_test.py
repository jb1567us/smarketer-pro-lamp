import ftplib
import urllib.request

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

ftp.cwd("/public_html/b2b_outreach_lamp")
with open(r"D:\sandbox\b2b_outreach_lamp\test_pdo.php", "rb") as f:
    ftp.storbinary("STOR test_pdo.php", f)
ftp.quit()

url = 'http://lookoverhere.xyz/b2b_outreach_lamp/test_pdo.php'
print(f"Testing {url}")
try:
    with urllib.request.urlopen(url) as response:
        print(response.read().decode('utf-8'))
except Exception as e:
    print(f"Error: {e}")
