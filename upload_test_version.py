import ftplib
import urllib.request

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)
ftp.cwd("/b2b_outreach_lamp")
with open("D:/sandbox/b2b_outreach_lamp/test_version.php", "rb") as f:
    ftp.storbinary("STOR test_version.php", f)
ftp.quit()

print("VERSION:", urllib.request.urlopen("http://lookoverhere.xyz/b2b_outreach_lamp/test_version.php").read().decode("utf-8"))
