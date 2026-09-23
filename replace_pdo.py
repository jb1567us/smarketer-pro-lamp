import os
import glob

files = glob.glob('D:/sandbox/b2b_outreach_lamp/**/*.php', recursive=True)
c = 0
for f in files:
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    if 'PDO::' in content and '\\App\\PDO::' not in content:
        # Avoid replacing already replaced ones if any, though the check above handles it mostly
        content = content.replace('PDO::', '\\App\\PDO::')
        
        # But wait, in includes/PDO.php itself, we have PDO::FETCH_ASSOC.
        # So we should exclude includes/PDO.php from replacement
        if not f.endswith('PDO.php'):
            with open(f, 'w', encoding='utf-8') as file:
                file.write(content)
            c += 1
            print(f'Updated {f}')

print(f'Total {c} files')
