"""Deploy patched files to both FTP directories."""
import ftplib
import io
import os

FILES = [
    ('includes/PDO.php',            'includes/PDO.php'),
    ('includes/SimpleHarvester.php', 'includes/SimpleHarvester.php'),
    ('api/stats.php',               'api/stats.php'),
    ('api/agent_chat.php',          'api/agent_chat.php'),
    ('agent_lab.php',               'agent_lab.php'),
]

REMOTE_BASES = [
    '/b2b_outreach_lamp',
    '/public_html/b2b_outreach_lamp',
]

ftp = ftplib.FTP('ftp.lookoverhere.xyz')
ftp.login('root@lookoverhere.xyz', '!Meimeialibe4r')

for local_rel, remote_rel in FILES:
    with open(local_rel, 'rb') as f:
        data = f.read()
    
    for base in REMOTE_BASES:
        remote_path = base + '/' + remote_rel
        remote_dir = '/'.join(remote_path.split('/')[:-1])
        filename = remote_path.split('/')[-1]
        
        try:
            ftp.cwd('/')
            ftp.cwd(remote_dir)
            res = ftp.storbinary('STOR ' + filename, io.BytesIO(data))
            print('[OK]  ' + remote_path + '  ' + res)
        except Exception as e:
            print('[FAIL] ' + remote_path + ': ' + str(e))

ftp.quit()
print('\nDeploy complete.')
