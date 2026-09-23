content = open(r'D:\sandbox\b2b_outreach_lamp\includes\Database.php', 'r', encoding='utf-8').read()
content = content.replace("'lookover_b2b'", "'root'")
open(r'D:\sandbox\b2b_outreach_lamp\includes\Database.php', 'w', encoding='utf-8').write(content)

content = open(r'D:\sandbox\b2b_outreach_lamp\test_custom_pdo.php', 'r', encoding='utf-8').read()
content = content.replace("'lookover_b2b'", "'root'")
open(r'D:\sandbox\b2b_outreach_lamp\test_custom_pdo.php', 'w', encoding='utf-8').write(content)

print("Updated Database.php and test_custom_pdo.php")
