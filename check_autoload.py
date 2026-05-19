import ftplib

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

ftp.cwd("/public_html/b2b_outreach_lamp/includes")
with open("remote_autoload.php", "wb") as f:
    ftp.retrbinary("RETR autoload.php", f.write)

ftp.quit()

with open("remote_autoload.php", "r") as f:
    print(f.read())
