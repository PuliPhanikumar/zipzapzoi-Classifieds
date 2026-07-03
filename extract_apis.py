import os, re

html_files = [
    'D:/zipzapzoi/ZIpZapZoi Codes/classifieds.html',
    'D:/zipzapzoi/ZIpZapZoi Codes/index.html',
    'D:/zipzapzoi/ZIpZapZoi Codes/Listing Detail.html',
    'D:/zipzapzoi/ZIpZapZoi Codes/Login Page.html',
    'D:/zipzapzoi/ZIpZapZoi Codes/profile.html',
    'D:/zipzapzoi/ZIpZapZoi Codes/Seller Dashboard.html',
]
endpoints = set()
for f in html_files:
    if os.path.exists(f):
        with open(f, 'r', encoding='utf-8') as fp:
            txt = fp.read()
        matches = re.findall(r"/api/[a-zA-Z0-9_./-]+", txt)
        endpoints.update(matches)
for e in sorted(endpoints):
    print(e)
