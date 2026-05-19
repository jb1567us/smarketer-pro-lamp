import re
content = open(r'D:\sandbox\b2b_outreach_lamp\includes\Database.php', 'r', encoding='utf-8').read()

content = re.sub(r"\$dbname\s*=\s*getenv\('DB_NAME'\)\s*\?:\s*'[^']*'", "$dbname = getenv('DB_NAME') ?: 'lookoverhere_wp947'", content)
content = re.sub(r"\$username\s*=\s*getenv\('DB_USER'\)\s*\?:\s*'[^']*'", "$username = getenv('DB_USER') ?: 'lookoverhere_wp947'", content)
content = re.sub(r"\$password\s*=\s*getenv\('DB_PASS'\)\s*\?:\s*'[^']*'", "$password = getenv('DB_PASS') ?: ']Vg6[y)1)5SYp]0Z'", content)

open(r'D:\sandbox\b2b_outreach_lamp\includes\Database.php', 'w', encoding='utf-8').write(content)
print("Updated Database.php correctly")
