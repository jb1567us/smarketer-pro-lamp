import ftplib

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)
ftp.cwd("/b2b_outreach_lamp")
with open("D:/sandbox/b2b_outreach_lamp/phpinfo.php", "rb") as f:
    ftp.storbinary("STOR phpinfo.php", f)
ftp.quit()
