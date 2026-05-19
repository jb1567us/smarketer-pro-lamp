import ftplib
import os

FTP_HOST = "ftp.lookoverhere.xyz"
FTP_USER = "root@lookoverhere.xyz"
FTP_PASS = "!Meimeialibe4r"

REMOTE_BASES = [
    "/b2b_outreach_lamp",
    "/public_html/b2b_outreach_lamp"
]

files_to_upload = [
    "includes/Agents/ExtractionExpert.php",
    "includes/Agents/IntentAnalyst.php",
    "includes/Agents/CreativeAgent.php",
    "includes/Agents/VideoAgent.php",
    "includes/Agents/WordPressAgent.php",
    "api/agent_chat.php",
    "agent_lab_content.php",
    "test_diag.php",
    "test_traces.php",
    "verify_providers.php",
    "test_wordpress_agent.php",
    "index.php",
    "mass_tools.php",
    "includes/SimpleHarvester.php",
    "api/influencer_scout.php",
    "influencer_scout_content.php",
    "verify_mass.php"
]

def make_dirs_recursive(ftp, path):
    parts = path.strip("/").split("/")
    current = ""
    for part in parts:
        current += "/" + part
        try:
            ftp.cwd(current)
        except ftplib.error_perm:
            try:
                ftp.mkd(current)
                print(f"[FTP] Created directory: {current}")
            except Exception as ex:
                print(f"[FTP] Failed to create directory: {current} - {ex}")

try:
    print("[FTP] Connecting to FTP server...")
    ftp = ftplib.FTP(FTP_HOST)
    ftp.login(FTP_USER, FTP_PASS)
    print("[FTP] Logged in successfully.")

    for base in REMOTE_BASES:
        print(f"\n--- Deploying to Base Path: {base} ---")
        for file in files_to_upload:
            local_path = file
            remote_path = f"{base}/{file}"
            remote_dir = os.path.dirname(remote_path)
            
            # Ensure directories exist
            ftp.cwd("/")
            make_dirs_recursive(ftp, remote_dir)
            
            # Upload file
            ftp.cwd("/")
            ftp.cwd(remote_dir)
            filename = os.path.basename(file)
            print(f"[FTP] Uploading {local_path} -> {remote_path} ...")
            with open(local_path, "rb") as f:
                ftp.storbinary(f"STOR {filename}", f)
            print(f"[FTP] Successfully uploaded {filename}")

    ftp.quit()
    print("\n[FTP] All agent coherence refactored files deployed successfully to all bases!")

except Exception as e:
    print(f"\n[ERROR] Deployment failed: {e}")
