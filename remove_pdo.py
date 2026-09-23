import os
import glob

files = glob.glob('D:/sandbox/b2b_outreach_lamp/**/*.php', recursive=True)
c = 0
for f in files:
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    modified = False
    if 'use PDO;' in content:
        content = content.replace('use PDO;\n', '')
        modified = True
    if 'use PDOException;' in content:
        content = content.replace('use PDOException;\n', '')
        modified = True
        
    if modified:
        with open(f, 'w', encoding='utf-8') as file:
            file.write(content)
        c += 1
        print(f'Updated {f}')

print(f'Total {c} files')
