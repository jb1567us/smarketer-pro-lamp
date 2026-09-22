import ftplib
import os
import urllib.request
import time

FTP_HOST = "ftp.lookoverhere.xyz"
FTP_USER = "root@lookoverhere.xyz"
FTP_PASS = os.environ.get("FTP_PASS", "")

REMOTE_BASES = [
    "/b2b_outreach_lamp",
    "/public_html/b2b_outreach_lamp"
]

files_to_deploy = [
    "includes/PDO.php",
    "includes/Routers/SmartRotationManager.php",
    "includes/Routers/SmartLLMRouter.php",
    "includes/Routers/SmartEmailRouter.php",
    "includes/SimpleHarvester.php"
]

# Write temporary migration script
migration_code = """<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/autoload.php';

$pdo = \\App\\Database::getConnection();

$sql = "
CREATE TABLE IF NOT EXISTS api_usage_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_name VARCHAR(50) NOT NULL,
    api_key_masked VARCHAR(100) NOT NULL,
    status ENUM('success', 'failed') DEFAULT 'success',
    error_message TEXT DEFAULT NULL,
    timestamp INT NOT NULL,
    INDEX idx_service_key_time (service_name, api_key_masked, timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
";

try {
    $pdo->exec($sql);
    echo "Migration Success: api_usage_logs table verified/created.\\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\\n";
}
"""

with open("migration_temp.php", "w") as f:
    f.write(migration_code)

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
        
        # Upload main files
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

        # Upload temporary migration script
        ftp.cwd("/")
        ftp.cwd(base)
        print(f"[FTP] Uploading migration_temp.php to {base} ...")
        with open("migration_temp.php", "rb") as f:
            ftp.storbinary("STOR migration_temp.php", f)
        print(f"[FTP] Successfully uploaded migration_temp.php")

        print(f"[FTP] Uploading check_logs.php to {base} ...")
        with open("check_logs.php", "rb") as f:
            ftp.storbinary("STOR check_logs.php", f)
        print(f"[FTP] Successfully uploaded check_logs.php")

    ftp.quit()
    print("\n[FTP] All files uploaded successfully!")

    print("\nWaiting 2 seconds for changes to settle...")
    time.sleep(2)

    # Trigger remote migration
    migration_url = "http://lookoverhere.xyz/b2b_outreach_lamp/migration_temp.php"
    print(f"\n[HTTP] Triggering migration at: {migration_url}")
    try:
        with urllib.request.urlopen(migration_url) as response:
            res_content = response.read().decode('utf-8')
            print("[HTTP] Response:\n" + "-"*40 + "\n" + res_content + "\n" + "-"*40)
    except Exception as e:
        print(f"[HTTP] Warning: Trigger failed: {e}")

    # Trigger check_logs
    logs_url = "http://lookoverhere.xyz/b2b_outreach_lamp/check_logs.php"
    print(f"\n[HTTP] Triggering check_logs at: {logs_url}")
    try:
        with urllib.request.urlopen(logs_url) as response:
            res_content = response.read().decode('utf-8')
            print("[HTTP] Response:\n" + "-"*40 + "\n" + res_content + "\n" + "-"*40)
    except Exception as e:
        print(f"[HTTP] Warning: Check logs failed: {e}")

    # Remote cleanup
    print("\n[FTP] Cleaning up scripts from remote...")
    try:
        ftp = ftplib.FTP(FTP_HOST)
        ftp.login(FTP_USER, FTP_PASS)
        for base in REMOTE_BASES:
            try:
                ftp.delete(f"{base}/migration_temp.php")
                print(f"[FTP] Deleted {base}/migration_temp.php")
            except Exception as e:
                print(f"[FTP] FAILED to delete {base}/migration_temp.php: {e}")
            try:
                ftp.delete(f"{base}/check_logs.php")
                print(f"[FTP] Deleted {base}/check_logs.php")
            except Exception as e:
                print(f"[FTP] FAILED to delete {base}/check_logs.php: {e}")
        ftp.quit()
    except Exception as e:
        print(f"[FTP] Cleanup failed: {e}")

    # Local cleanup
    if os.path.exists("migration_temp.php"):
        os.remove("migration_temp.php")
        print("[LOCAL] Cleaned up migration_temp.php")
    if os.path.exists("check_logs.php"):
        os.remove("check_logs.php")
        print("[LOCAL] Cleaned up check_logs.php")

except Exception as e:
    print(f"\n[ERROR] Deployment process failed: {e}")
