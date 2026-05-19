import ftplib

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

print("--- /public_html ---")
try:
    ftp.cwd("/public_html")
    ftp.retrlines("LIST -a")
except Exception as e:
    print(e)

print("\n--- /public_html/b2b_outreach_lamp ---")
try:
    ftp.cwd("/public_html/b2b_outreach_lamp")
    ftp.retrlines("LIST -a")
except Exception as e:
    print(e)

ftp.quit()
