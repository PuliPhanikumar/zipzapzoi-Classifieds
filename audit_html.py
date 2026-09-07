import os
import re

html_files = [
    'index.html',
    'classifieds.html',
    'Listing Detail.html',
    'Post Listing.html',
    'Seller Dashboard.html',
    'Login Page.html',
    'User Registration.html',
    'profile.html',
    'Inbox.html',
    'favorites.html',
    'wanted.html',
    'Payment.html',
    'dashboard.html'
]

for file in html_files:
    if not os.path.exists(file):
        print(f"MISSING: {file}")
        continue
    
    with open(file, 'r', encoding='utf-8') as f:
        content = f.read()

    print(f"--- {file} ---")
    
    # Check for fetch without catch
    fetches = re.findall(r'fetch\([^)]+\)[\s\S]*?(?=\n\s*[;}])', content)
    for fetch in fetches:
        if '.catch' not in fetch:
            print(f"Fetch without catch: {fetch.strip()[:100]}")
    
    # Check for hardcoded domains in fetch
    hardcoded = re.findall(r'fetch\([\'"]https?://', content)
    if hardcoded:
        print(f"Hardcoded fetch domains: {len(hardcoded)}")

    # Check localStorage keys
    ls_keys = set(re.findall(r"localStorage\.(?:getItem|setItem)\(['\"]([^'\"]+)['\"]", content))
    if ls_keys:
        print(f"LocalStorage keys: {ls_keys}")
        
    # check if timeAgo is defined
    if 'timeAgo(' in content and 'function timeAgo' not in content and 'const timeAgo' not in content:
        print("timeAgo called but not defined")
        
    # Check if formatPrice is defined
    if 'formatPrice(' in content and 'function formatPrice' not in content and 'const formatPrice' not in content:
        print("formatPrice called but not defined")

