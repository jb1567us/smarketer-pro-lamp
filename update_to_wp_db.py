content = open(r'D:\sandbox\b2b_outreach_lamp\includes\Database.php', 'r', encoding='utf-8').read()
content = content.replace("'lookoverhere_b2b'", "'lookoverhere_wp947'") # From the previous test
content = content.replace("'!Meimeialibe4r'", "']Vg6[y)1)5SYp]0Z'")
open(r'D:\sandbox\b2b_outreach_lamp\includes\Database.php', 'w', encoding='utf-8').write(content)

content = open(r'D:\sandbox\b2b_outreach_lamp\test_custom_pdo.php', 'r', encoding='utf-8').read()
content = content.replace("'lookoverhere_b2b'", "'lookoverhere_wp947'")
content = content.replace("'!Meimeialibe4r'", "']Vg6[y)1)5SYp]0Z'")
open(r'D:\sandbox\b2b_outreach_lamp\test_custom_pdo.php', 'w', encoding='utf-8').write(content)
