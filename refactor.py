import os
import re

directory = r'D:\sandbox\b2b_outreach_lamp'

for root, dirs, files in os.walk(directory):
    if 'includes' in root or 'vendor' in root:
        continue
    for file in files:
        if file.endswith('.php') and file != 'refactor_db.php' and file != 'refactor.py':
            filepath = os.path.join(root, file)
            with open(filepath, 'r', encoding='utf-8') as f:
                content = f.read()
            
            original = content
            
            # Replace db.php require
            is_sub = ('api' in root or 'cron' in root)
            prefix = '../' if is_sub else ''
            
            db_pattern = re.compile(r"require_once\s+['\"].*?includes/db\.php['\"];")
            repl = f"require_once __DIR__ . '/{prefix}includes/autoload.php';\n$pdo = \\\\App\\\\Database::getConnection();"
            content = db_pattern.sub(repl, content)
            
            # Replace runner.php require
            runner_pattern = re.compile(r"require_once\s+['\"].*?includes/runner\.php['\"];")
            content = runner_pattern.sub("", content)
            
            # Replace runner usage
            runner_init = re.compile(r"\$runner\s*=\s*new\s+OutreachRunner\(\$pdo\);", re.IGNORECASE)
            content = runner_init.sub("$processor = new \\\\App\\\\Domain\\\\TaskProcessor($pdo, new \\\\App\\\\Routers\\\\SmartLLMRouter($pdo));", content)
            
            runner_proc = re.compile(r"\$runner->processTask\((.*?)\);", re.IGNORECASE)
            content = runner_proc.sub(r"$processor->processTask(\1);", content)
            
            if content != original:
                with open(filepath, 'w', encoding='utf-8') as f:
                    f.write(content)
                print(f"Updated: {filepath}")
