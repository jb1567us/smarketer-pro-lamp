import ftplib

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

ftp.cwd("/public_html/b2b_outreach_lamp/api")
lines = []
ftp.retrlines('RETR trigger_task.php', lines.append)
for line in lines:
    print(line)
ftp.quit()
