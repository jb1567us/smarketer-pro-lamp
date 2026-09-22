import ftplib
import os
import urllib.request

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = os.environ.get("FTP_PASS", "")

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)
ftp.cwd("/b2b_outreach_lamp")
with open("D:/sandbox/b2b_outreach_lamp/test_mysqli_execute.php", "rb") as f:
    ftp.storbinary("STOR test_mysqli_execute.php", f)
ftp.quit()

print("RESULT:", urllib.request.urlopen("http://lookoverhere.xyz/b2b_outreach_lamp/test_mysqli_execute.php").read().decode("utf-8"))
