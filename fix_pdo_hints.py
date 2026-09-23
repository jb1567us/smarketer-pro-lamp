import os
import re

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
            
            # Add use App\PDO if PDO is used without namespace and we are in a namespace
            # Or just replace type hints
            content = re.sub(r'\(PDO\s+\$pdo\)', r'(\\App\\PDO $pdo)', content)
            content = re.sub(r'private\s+PDO\s+\$pdo', r'private \\App\\PDO $pdo', content)
            content = re.sub(r':\s*PDO\b', r': \\App\\PDO', content)
            content = re.sub(r'@var\s+PDO\b', r'@var \\App\\PDO', content)
            content = re.sub(r'@param\s+PDO\b', r'@param \\App\\PDO', content)
            content = re.sub(r'@return\s+PDO\b', r'@return \\App\\PDO', content)

            if content != original_content:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(content)
                print(f"Updated {filepath}")
