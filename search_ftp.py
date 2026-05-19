import ftplib

ftp_host = "ftp.lookoverhere.xyz"
ftp_user = "root@lookoverhere.xyz"
ftp_pass = "!Meimeialibe4r"

ftp = ftplib.FTP(ftp_host)
ftp.login(ftp_user, ftp_pass)

def search_dir(path):
    try:
        ftp.cwd(path)
        items = ftp.nlst()
        for item in items:
            if item in ['.', '..']: continue
            full_path = f"{path}/{item}" if path != '/' else f"/{item}"
            if item == "trigger_task.php":
                print(f"FOUND: {full_path}")
            elif '.' not in item:
                search_dir(full_path)
    except Exception as e:
        pass

search_dir('/')
ftp.quit()
