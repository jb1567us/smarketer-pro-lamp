import ftplib
import os
import urllib.request
import json
import time

FTP_HOST = "ftp.lookoverhere.xyz"
FTP_USER = "root@lookoverhere.xyz"
FTP_PASS = os.environ.get("FTP_PASS", "")
REMOTE_BASES = ["/b2b_outreach_lamp", "/public_html/b2b_outreach_lamp"]

def main():
    print("=== Remote Job Queue Verification ===")
    
    # 1. Upload trigger_cron_worker.php
    print("\n[FTP] Uploading temporary cron worker trigger wrapper...")
    try:
        ftp = ftplib.FTP(FTP_HOST)
        ftp.login(FTP_USER, FTP_PASS)
        for base in REMOTE_BASES:
            ftp.cwd("/")
            ftp.cwd(base)
            with open("trigger_cron_worker.php", "rb") as f:
                ftp.storbinary("STOR trigger_cron_worker.php", f)
            print(f"[FTP] Uploaded to {base}/trigger_cron_worker.php")
        ftp.quit()
    except Exception as e:
        print(f"[FTP] Upload failed: {e}")
        return

    time.sleep(2)

    # 2. Submit async harvest job
    harvest_url = "http://lookoverhere.xyz/b2b_outreach_lamp/api/mass_tools.php?action=harvest"
    print(f"\n[HTTP] Submitting async harvest job to: {harvest_url}")
    payload = {
        "query": "site:linkedin.com \"Austin\" \"CEO\"",
        "async": True,
        "provider": "ddg"
    }
    req = urllib.request.Request(
        harvest_url,
        data=json.dumps(payload).encode("utf-8"),
        headers={"Content-Type": "application/json"}
    )
    
    job_id = None
    try:
        with urllib.request.urlopen(req) as response:
            res_content = response.read().decode("utf-8")
            print("[HTTP] Job Submission Response:")
            print(res_content)
            res_json = json.loads(res_content)
            if res_json.get("success") and res_json.get("job_ids"):
                job_id = res_json["job_ids"][0]
                print(f"--> Found Job ID: {job_id}")
            else:
                print("--> Job submission did not return job_ids!")
                return
    except Exception as e:
        print(f"[HTTP] Job submission failed: {e}")
        return

    time.sleep(2)

    # 3. Verify job is pending
    status_url = f"http://lookoverhere.xyz/b2b_outreach_lamp/api/mass_tools.php?action=job_status&ids={job_id}"
    print(f"\n[HTTP] Checking initial job status: {status_url}")
    try:
        with urllib.request.urlopen(status_url) as response:
            res_content = response.read().decode("utf-8")
            print("[HTTP] Status Response:")
            print(res_content)
    except Exception as e:
        print(f"[HTTP] Checking status failed: {e}")

    time.sleep(2)

    # 4. Trigger cron worker
    trigger_url = "http://lookoverhere.xyz/b2b_outreach_lamp/trigger_cron_worker.php"
    print(f"\n[HTTP] Remotely triggering cron worker to run pending jobs: {trigger_url}")
    try:
        # Give it plenty of time to process
        ctx = urllib.request.urlopen(trigger_url, timeout=30)
        with ctx as response:
            res_content = response.read().decode("utf-8")
            print("[HTTP] Cron Worker Log Output:")
            print("-" * 50)
            print(res_content)
            print("-" * 50)
    except Exception as e:
        print(f"[HTTP] Triggering cron worker failed: {e}")

    time.sleep(2)

    # 5. Check job status again
    print(f"\n[HTTP] Checking final job status: {status_url}")
    try:
        with urllib.request.urlopen(status_url) as response:
            res_content = response.read().decode("utf-8")
            print("[HTTP] Final Status Response:")
            print(res_content)
    except Exception as e:
        print(f"[HTTP] Checking final status failed: {e}")

    # 6. Cleanup trigger wrapper
    print("\n[FTP] Deleting remote temporary trigger wrapper for security...")
    try:
        ftp = ftplib.FTP(FTP_HOST)
        ftp.login(FTP_USER, FTP_PASS)
        for base in REMOTE_BASES:
            try:
                ftp.delete(f"{base}/trigger_cron_worker.php")
                print(f"[FTP] Deleted {base}/trigger_cron_worker.php")
            except Exception as ex:
                print(f"[FTP] Failed to delete from {base}: {ex}")
        ftp.quit()
    except Exception as e:
        print(f"[FTP] Cleanup failed: {e}")

if __name__ == "__main__":
    main()
