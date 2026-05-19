import ftplib

FTP_HOST = "ftp.lookoverhere.xyz"
FTP_USER = "root@lookoverhere.xyz"
FTP_PASS = "!Meimeialibe4r"

try:
    ftp = ftplib.FTP(FTP_HOST)
    ftp.login(FTP_USER, FTP_PASS)
    print("Logged in!")
    
    for base in ["/b2b_outreach_lamp", "/public_html/b2b_outreach_lamp"]:
        print(f"\n--- Checking {base} ---")
        try:
            ftp.cwd(base)
            files = ftp.nlst()
            print("Files in root:", files)
            for f in ['agent_lab_content.php', 'influencer_scout_content.php', 'mass_tools_content.php']:
                if f in files:
                    print(f"  [FOUND] {f}")
                else:
                    print(f"  [MISSING] {f}")
        except Exception as e:
            print(f"Failed to check {base}: {e}")
            
    ftp.quit()
except Exception as e:
    print("Error:", e)
