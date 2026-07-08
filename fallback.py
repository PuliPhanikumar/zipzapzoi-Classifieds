import re

html = open('Post Listing.html', 'r', encoding='utf-8').read()
old = 'console.error("CRITICAL: Schema categories are totally empty!");'
new = '''console.error("CRITICAL: Schema categories are totally empty!");
        document.getElementById('catSection').innerHTML = <div class="bg-red-50 dark:bg-red-900/20 p-8 rounded-[2rem] border border-red-200 dark:border-red-800 text-center shadow-xl"><span class="material-symbols-outlined text-red-500 text-5xl mb-4">error</span><h3 class="text-red-700 dark:text-red-400 font-bold text-xl">Service Unavailable</h3><p class="text-red-600 dark:text-red-300 mt-2">Could not load the category system. Please refresh the page or try again later.</p></div>;'''
open('Post Listing.html', 'w', encoding='utf-8').write(html.replace(old, new))
