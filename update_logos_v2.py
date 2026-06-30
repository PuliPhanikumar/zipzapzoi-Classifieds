import os
import glob
import re

html_files = glob.glob('D:/zipzapzoi/ZIpZapZoi Codes/*.html')

new_logo_html = """<a href="index.html" class="flex items-center gap-2 group cursor-pointer">
              <img src="images/classifieds-logo.png" alt="ZipZapZoi Logo" class="h-10 md:h-12 object-contain animate-logo-bouncy-shiny">
              <h2 class="tracking-tight flex items-baseline gap-1">
                <span class="text-2xl md:text-3xl font-black bg-gradient-to-r from-secondary to-primary bg-clip-text text-transparent" style="font-family: 'Fredoka One', cursive;">ZipZapZoi</span>
                <span class="text-xl md:text-2xl text-black animate-blinking-3d" style="font-family: 'Righteous', cursive; text-shadow: 1px 1px 0 #999, 2px 2px 0 #555, 3px 3px 4px rgba(0,0,0,0.5);">Classifieds</span>
              </h2>
            </a>"""

idx_replace = """<div class="flex items-center justify-center gap-4 group cursor-pointer animate-logo-in animate-logo-pulse" style="animation-delay: 0.2s, 1.4s;">
            <img src="images/classifieds-logo.png" alt="ZipZapZoi" class="h-24 md:h-32 object-contain animate-logo-bouncy-shiny">
            <h1 class="flex flex-col items-start">
              <span class="text-6xl md:text-8xl text-white text-3d-vibrant" style="font-family: 'Fredoka One', cursive;">ZipZapZoi</span>
              <span class="text-4xl md:text-6xl text-black animate-blinking-3d" style="font-family: 'Righteous', cursive; text-shadow: 2px 2px 0 #ddd, 4px 4px 0 #888, 6px 6px 10px rgba(0,0,0,0.6);">Classifieds</span>
            </h1>
          </div>"""

font_tag = '<link href="https://fonts.googleapis.com/css2?family=Righteous&display=swap" rel="stylesheet"/>'

new_style_block = """
<style id="zzz-logo-styles">
  @keyframes blink3d {
    0%, 100% { opacity: 1; transform: scale(1) translateY(0); filter: brightness(1); }
    50% { opacity: 0.8; transform: scale(1.05) translateY(-2px); filter: brightness(1.2); }
  }
  @keyframes bounce-gentle {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
  }
  @keyframes shine {
    0% { filter: drop-shadow(0 0 2px rgba(0, 200, 255, 0.4)); }
    50% { filter: drop-shadow(0 0 10px rgba(255, 0, 255, 0.7)) brightness(1.1); }
    100% { filter: drop-shadow(0 0 2px rgba(0, 200, 255, 0.4)); }
  }
  .animate-blinking-3d {
    animation: blink3d 1.5s infinite alternate ease-in-out;
    display: inline-block;
  }
  .animate-logo-bouncy-shiny {
    animation: bounce-gentle 2s infinite ease-in-out, shine 3s infinite alternate ease-in-out;
  }
</style>
"""

for f in html_files:
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    modified = False

    # 1. Inject Righteous Font if missing
    if 'family=Righteous' not in content and '</head>' in content:
        content = content.replace('</head>', f'{font_tag}\n</head>')
        modified = True
        
    # 2. Replace old style block with new style block
    # Remove the old style block if it exists
    old_style_pattern = r'<style>\s*@keyframes blink3d.*?\.animate-blinking-3d.*?</style>'
    if re.search(old_style_pattern, content, re.DOTALL):
        content = re.sub(old_style_pattern, new_style_block, content, flags=re.DOTALL)
        modified = True
    elif 'zzz-logo-styles' not in content and '</head>' in content:
        content = content.replace('</head>', f'{new_style_block}\n</head>')
        modified = True

    # 3. Replace typical standard logos
    pattern1 = r'<a[^>]*href="index.html"[^>]*>[\s\n]*<img[^>]*Assets/zipzapzoi-logo\.png[^>]*>[\s\n]*<h2[^>]*>[\s\n]*<span[^>]*>ZipZapZoi</span>[\s\n]*<span[^>]*>Classifieds</span>[\s\n]*</h2>[\s\n]*</a>'
    
    new_content = re.sub(pattern1, new_logo_html, content, flags=re.IGNORECASE | re.DOTALL)
    if new_content != content:
        modified = True
        content = new_content
        
    if 'index.html' in f:
        # Replace the big text-3d-vibrant button in index.html
        idx_pattern = r'<div class="flex items-center justify-center gap-4 group cursor-pointer animate-logo-in animate-logo-pulse"[^>]*>[\s\n]*<img[^>]*Assets/zipzapzoi-logo\.png[^>]*>[\s\n]*<h1[^>]*>[\s\n]*<span[^>]*>ZipZapZoi</span>[\s\n]*<span[^>]*>Classifieds</span>[\s\n]*</h1>[\s\n]*</div>'
        new_content = re.sub(idx_pattern, idx_replace, content, flags=re.IGNORECASE | re.DOTALL)
        if new_content != content:
            modified = True
            content = new_content

    if modified:
        with open(f, 'w', encoding='utf-8') as file:
            file.write(content)
        print(f"Updated {os.path.basename(f)}")

print("Finished updating all logos.")
