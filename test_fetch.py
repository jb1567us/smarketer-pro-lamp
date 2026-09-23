import urllib.request
import urllib.error
try:
    print(urllib.request.urlopen('http://lookoverhere.xyz/b2b_outreach_lamp/test_custom_pdo.php').read().decode('utf-8'))
except urllib.error.HTTPError as e:
    print('HTTP ERROR:', e.code)
    print(e.read().decode('utf-8'))
