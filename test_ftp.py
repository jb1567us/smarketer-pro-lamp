import ftplib
import re
ftp = ftplib.FTP('ftp.lookoverhere.xyz')
ftp.login('root@lookoverhere.xyz', '!Meimeialibe4r')
ftp.cwd('/public_html/b2b_outreach_lamp')
lines = []
ftp.retrlines('RETR agent_lab.php', lines.append)
content = '\n'.join(lines)
matches = re.findall(r'<select id=.agent-persona.', content)
print('Matches in public_html:', len(matches))
ftp.cwd('/b2b_outreach_lamp')
lines2 = []
ftp.retrlines('RETR agent_lab.php', lines2.append)
content2 = '\n'.join(lines2)
matches2 = re.findall(r'<select id=.agent-persona.', content2)
print('Matches in root:', len(matches2))
ftp.quit()
