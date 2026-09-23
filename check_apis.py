import urllib.request, urllib.error, json

endpoints = [
    ('stats',     'http://lookoverhere.xyz/b2b_outreach_lamp/api/stats.php'),
    ('settings',  'http://lookoverhere.xyz/b2b_outreach_lamp/api/settings.php'),
    ('leads',     'http://lookoverhere.xyz/b2b_outreach_lamp/api/leads.php'),
    ('campaigns', 'http://lookoverhere.xyz/b2b_outreach_lamp/api/campaigns.php'),
    ('mass_proxy','http://lookoverhere.xyz/b2b_outreach_lamp/api/mass_tools.php?action=stats'),
]

for name, url in endpoints:
    try:
        r = urllib.request.urlopen(url, timeout=10)
        body = r.read(500).decode('utf-8', errors='replace')
        try:
            data = json.loads(body)
            ok = data.get('success')
            print('[OK]   ' + name + ': success=' + str(ok))
        except Exception:
            print('[WARN] ' + name + ': non-JSON: ' + body[:100])
    except urllib.error.HTTPError as e:
        body = e.read(300).decode('utf-8', errors='replace')
        print('[FAIL] ' + name + ' HTTP ' + str(e.code) + ': ' + body[:150])
    except Exception as e:
        print('[FAIL] ' + name + ': ' + str(e))
