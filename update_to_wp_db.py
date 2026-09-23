import os
old_db_pass = os.environ.get("OLD_DB_PASS", "")
new_db_pass = os.environ.get("NEW_DB_PASS", "")
if not old_db_pass or not new_db_pass:
    raise SystemExit("Set OLD_DB_PASS and NEW_DB_PASS env vars before running")
content = open(r'D:\sandbox\b2b_outreach_lamp\includes\Database.php', 'r', encoding='utf-8').read()
content = content.replace("'lookoverhere_b2b'", "'lookoverhere_wp947'") # From the previous test
content = content.replace("'" + old_db_pass + "'", "'" + new_db_pass + "'")
open(r'D:\sandbox\b2b_outreach_lamp\includes\Database.php', 'w', encoding='utf-8').write(content)

content = open(r'D:\sandbox\b2b_outreach_lamp\test_custom_pdo.php', 'r', encoding='utf-8').read()
content = content.replace("'lookoverhere_b2b'", "'lookoverhere_wp947'")
content = content.replace("'" + old_db_pass + "'", "'" + new_db_pass + "'")
open(r'D:\sandbox\b2b_outreach_lamp\test_custom_pdo.php', 'w', encoding='utf-8').write(content)
