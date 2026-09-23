import ftplib
import os

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = os.environ.get("FTP_PASS", "")

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

ftp.cwd("/public_html/b2b_outreach_lamp/api")
with open("remote_trigger_task.php", "wb") as f:
    ftp.retrbinary("RETR trigger_task.php", f.write)

ftp.quit()

with open("remote_trigger_task.php", "r") as f:
    print(f.read())
