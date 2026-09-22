import ftplib
import os

FTP_HOST = "ftp.lookoverhere.xyz"
FTP_USER = "root@lookoverhere.xyz"
FTP_PASS = os.environ.get("FTP_PASS", "")
REMOTE_BASE = "/public_html/b2b_outreach_lamp"

files_to_upload = [
    ("agent_lab.php", "agent_lab.php"),
    ("api/agent_chat.php", "api/agent_chat.php"),
    ("includes/PDO.php", "includes/PDO.php")
]

try:
    ftp = ftplib.FTP(FTP_HOST)
    ftp.login(FTP_USER, FTP_PASS)

    for local_file, remote_file in files_to_upload:
        # Construct absolute remote path
        full_remote_path = f"{REMOTE_BASE}/{remote_file}"
        remote_dir = os.path.dirname(full_remote_path)
        
        # Change to the remote directory
        try:
            ftp.cwd(remote_dir)
        except ftplib.error_perm:
            ftp.mkd(remote_dir)
            ftp.cwd(remote_dir)
        
        with open(local_file, "rb") as f:
            ftp.storbinary(f"STOR {os.path.basename(remote_file)}", f)

    ftp.quit()
    print("Files uploaded successfully!")
except Exception as e:
    print("Error:", e)
