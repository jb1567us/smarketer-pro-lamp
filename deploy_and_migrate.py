import ftplib
import os
import urllib.request
import time

FTP_HOST = "ftp.lookoverhere.xyz"
FTP_USER = "root@lookoverhere.xyz"
FTP_PASS = "!Meimeialibe4r"

# Deploy to both paths to ensure web server maps it correctly
REMOTE_BASES = [
    "/b2b_outreach_lamp",
    "/public_html/b2b_outreach_lamp"
]

files_to_deploy = [
    "schema/001_jobs_queue.sql",
    "schema/run_migration.php",
    "cron_worker.php",
    "includes/ExtractionEngine.php",
    "includes/DorkLibrary.php",
    "includes/Routers/SmartLLMRouter.php",
    "api/mass_tools.php",
    "mass_tools_content.php",
    "includes/SimpleHarvester.php"
]

def make_dirs_recursive(ftp, path):
    """Ensure remote path directories exist on the FTP server."""
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
        for file in files_to_deploy:
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
    print("\n[FTP] All files uploaded successfully!")

    # Wait for file changes to persist
    print("\nWaiting 3 seconds for file changes to settle...")
    time.sleep(3)

    # Trigger remote DB migration script
    migration_url = "http://lookoverhere.xyz/b2b_outreach_lamp/schema/run_migration.php"
    print(f"\n[HTTP] Triggering remote migration at: {migration_url}")
    try:
        with urllib.request.urlopen(migration_url) as response:
            res_content = response.read().decode('utf-8')
            print("[HTTP] Migration Response:")
            print("-" * 50)
            print(res_content)
            print("-" * 50)
    except Exception as e:
        print(f"[HTTP] Failed to execute remote migration: {e}")

    # Remove temporary migration runner script from FTP for security
    print("\n[FTP] Cleaning up remote migration script for security...")
    try:
        ftp = ftplib.FTP(FTP_HOST)
        ftp.login(FTP_USER, FTP_PASS)
        for base in REMOTE_BASES:
            try:
                ftp.delete(f"{base}/schema/run_migration.php")
                print(f"[FTP] Deleted remote script from {base}/schema/run_migration.php")
            except Exception as ex:
                print(f"[FTP] Failed to delete remote script from {base}/schema/run_migration.php: {ex}")
        ftp.quit()
    except Exception as e:
        print(f"[FTP] Cleanup connection failed: {e}")

except Exception as e:
    print(f"\n[ERROR] Deployment failed: {e}")
