import ftplib
import os
import urllib.request

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = os.environ.get("FTP_PASS", "")

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)
ftp.cwd("/b2b_outreach_lamp")
with open("D:/sandbox/b2b_outreach_lamp/includes/PDO.php", "rb") as f:
    ftp.storbinary("STOR includes/PDO.php", f)
with open("D:/sandbox/b2b_outreach_lamp/test_custom_pdo.php", "rb") as f:
    ftp.storbinary("STOR test_custom_pdo.php", f)
ftp.quit()

print("RESULT:", urllib.request.urlopen("http://lookoverhere.xyz/b2b_outreach_lamp/test_custom_pdo.php").read().decode("utf-8"))
