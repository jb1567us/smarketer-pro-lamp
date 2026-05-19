content = open(r'D:\sandbox\b2b_outreach_lamp\test_custom_pdo.php', 'r', encoding='utf-8').read()
content = content.replace("'lookover_b2b'", "'lookoverhere_b2b'")
open(r'D:\sandbox\b2b_outreach_lamp\test_custom_pdo.php', 'w', encoding='utf-8').write(content)
print("Updated test_custom_pdo.php to lookoverhere_b2b")
