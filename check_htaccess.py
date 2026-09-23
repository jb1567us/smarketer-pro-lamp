import ftplib
import os

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = os.environ.get("FTP_PASS", "")

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

try:
    ftp.cwd("/public_html")
    lines = []
    ftp.retrlines('LIST -la', lines.append)
    for line in lines:
        if "htaccess" in line:
            print(line)
except Exception as e:
    print(f"Error: {e}")

ftp.quit()
