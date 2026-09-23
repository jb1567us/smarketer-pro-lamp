import urllib.request

try:
    url = "http://lookoverhere.xyz/b2b_outreach_lamp/index.php?tab=agent"
    html = urllib.request.urlopen(url).read().decode('utf-8')
    print("HTML Length:", len(html))
    
    idx = html.find('id="agent-tab"')
    if idx != -1:
        print("Found 'id=\"agent-tab\"' at index", idx)
        snippet = html[idx:idx+1000]
        print("Snippet:\n", snippet.encode('ascii', 'ignore').decode('ascii'))
    else:
        print("Could not find 'id=\"agent-tab\"' in HTML!")
        
except Exception as e:
    print("Error:", e)
