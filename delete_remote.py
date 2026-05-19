import ftplib

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

files_to_delete = [
    "/public_html/b2b_outreach_lamp/includes/db.php",
    "/public_html/b2b_outreach_lamp/includes/runner.php"
]

for file in files_to_delete:
    try:
        ftp.delete(file)
        print(f"Deleted {file}")
    except Exception as e:
        print(f"Could not delete {file}: {e}")

ftp.quit()
