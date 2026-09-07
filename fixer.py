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

# 1. Fix unhandled fetch promises
def fix_fetch_catch(content):
    # This is a bit tricky with regex, we'll look for .then() chains that end with a semicolon or newline without .catch
    # Actually, simpler: replace all .then(r => r.json()); with .then(r => r.json()).catch(e => console.error(e));
    # and similar. But there are multiline fetches.
    # It's safer to use a regex that finds etch(...) without catch at the end of the statement.
    
    # We will do a generic approach: if etch is in line but .catch is not in the line and not in the next 3 lines,
    # maybe just inject a basic catch wrapper? No, it's safer to let manual check or targeted replace.
    pass

