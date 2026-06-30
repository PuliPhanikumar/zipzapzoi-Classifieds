import os
import glob
import re

html_files = glob.glob('D:/zipzapzoi/ZIpZapZoi Codes/*.html')

new_logo_html = """<a href="index.html" class="flex items-center gap-2 group cursor-pointer">
              <img src="Assets/zipzapzoi-logo.png" alt="ZipZapZoi" class="h-10 md:h-12 object-contain group-hover:scale-105 transition-transform">
              <h2 class="tracking-tight flex items-baseline gap-1" style="font-family: 'Fredoka One', cursive;">
                <span class="text-2xl md:text-3xl bg-gradient-to-r from-secondary to-primary bg-clip-text text-transparent">ZipZapZoi</span>
                <span class="text-xl md:text-2xl text-primary animate-blinking-3d" style="text-shadow: 1px 1px 0 #005f3e, 2px 2px 0 #003a25, 3px 3px 4px rgba(0,0,0,0.4);">Classifieds</span>
              </h2>
            </a>"""

font_tag = '<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&display=swap" rel="stylesheet"/>'

style_block = """
<style>
  @keyframes blink3d {
    0%, 100% { opacity: 1; transform: scale(1) translateY(0); filter: brightness(1); }
    50% { opacity: 0.8; transform: scale(1.05) translateY(-2px); filter: brightness(1.2); }
  }
  .animate-blinking-3d {
    animation: blink3d 1.5s infinite alternate ease-in-out;
    display: inline-block;
  }
</style>
"""

for f in html_files:
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    modified = False

    # 1. Inject Font if missing
    if 'Fredoka+One' not in content and '</head>' in content:
        content = content.replace('</head>', f'{font_tag}\n</head>')
        modified = True
        
    # 2. Inject Style if missing
    if 'animate-blinking-3d' not in content and '</head>' in content:
        content = content.replace('</head>', f'{style_block}\n</head>')
        modified = True

    # 3. Replace typical standard logos
    # Pattern looks for <a ...> ... <span ...>all_inclusive</span> ... <h2 ...>ZipZapZoi</h2> ... </a>
    pattern1 = r'<a[^>]*href=[^>]*>[\s\n]*<span[^>]*>all_inclusive</span>[\s\n]*<h[1-6][^>]*>ZipZapZoi</h[1-6]>[\s\n]*</a>'
    
    new_content = re.sub(pattern1, new_logo_html, content, flags=re.IGNORECASE | re.DOTALL)
    if new_content != content:
        modified = True
        content = new_content
        
    # What about index.html which has the big button?
    # The user said "in all pages beside attached image", meaning replace all logos.
    if 'index.html' in f:
        # Replace the big text-3d-vibrant button in index.html
        idx_pattern = r'<button class="text-8xl[^>]*>ZipZapZoi</button>'
        idx_replace = """<div class="flex items-center justify-center gap-4 group cursor-pointer animate-logo-in animate-logo-pulse" style="animation-delay: 0.2s, 1.4s;">
            <img src="Assets/zipzapzoi-logo.png" alt="ZipZapZoi" class="h-24 md:h-32 object-contain group-hover:scale-110 transition-transform duration-300">
            <h1 class="flex flex-col items-start" style="font-family: 'Fredoka One', cursive;">
              <span class="text-6xl md:text-8xl text-white text-3d-vibrant">ZipZapZoi</span>
              <span class="text-4xl md:text-6xl text-[#ffd700] animate-blinking-3d" style="text-shadow: 2px 2px 0 #b38f00, 4px 4px 0 #806600, 6px 6px 8px rgba(0,0,0,0.5);">Classifieds</span>
            </h1>
          </div>"""
        new_content = re.sub(idx_pattern, idx_replace, content, flags=re.IGNORECASE)
        if new_content != content:
            modified = True
            content = new_content

    if modified:
        with open(f, 'w', encoding='utf-8') as file:
            file.write(content)
        print(f"Updated {os.path.basename(f)}")

print("Finished updating all logos.")
