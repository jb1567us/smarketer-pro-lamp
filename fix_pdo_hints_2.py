import os

local_dir = r'D:\sandbox\b2b_outreach_lamp'

for root, dirs, files in os.walk(local_dir):
    if '.git' in root or 'vendor' in root:
        continue
    for file in files:
        if file.endswith('.php'):
            filepath = os.path.join(root, file)
            with open(filepath, 'r', encoding='utf-8') as f:
                content = f.read()
            
            original_content = content
            
            content = content.replace('(PDO $pdo', '(\\App\\PDO $pdo')
            content = content.replace(', PDO $pdo', ', \\App\\PDO $pdo')

            if content != original_content:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(content)
                print(f"Updated {filepath}")
