import ftplib
import urllib.request

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

ftp.cwd("/public_html/b2b_outreach_lamp/api")
with open(r"D:\sandbox\b2b_outreach_lamp\clear_cache.php", "rb") as f:
    ftp.storbinary("STOR clear_cache.php", f)
ftp.quit()

url = 'http://lookoverhere.xyz/b2b_outreach_lamp/api/clear_cache.php'
print(f"Testing {url}")
try:
    with urllib.request.urlopen(url) as response:
        print(response.read().decode('utf-8'))
except Exception as e:
    print(f"Error: {e}")
