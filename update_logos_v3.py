import os
import glob
import re

html_files = glob.glob('D:/zipzapzoi/ZIpZapZoi Codes/*.html')

new_logo_html = """<a href="index.html" class="flex items-center gap-2 group cursor-pointer">
              <img src="images/classifieds-logo.png" alt="ZipZapZoi Logo" class="h-10 md:h-12 object-contain animate-logo-bouncy">
              <h2 class="tracking-tight flex items-baseline gap-1">
                <span class="text-2xl md:text-3xl font-black" style="font-family: 'Fredoka One', cursive;">
                  <span style="color: #0066ff;">ZipZap</span><span style="color: #ff007f;">Zoi</span>
                </span>
                <span class="text-xl md:text-2xl text-black flex items-baseline" style="font-family: 'Righteous', cursive; text-shadow: 1px 1px 0 #999, 2px 2px 0 #555, 3px 3px 4px rgba(0,0,0,0.5);">
                  <span class="animate-bounce-in" style="animation-delay: 0.1s">C</span><span class="animate-bounce-in" style="animation-delay: 0.15s">l</span><span class="animate-bounce-in" style="animation-delay: 0.2s">a</span><span class="animate-bounce-in" style="animation-delay: 0.25s">s</span><span class="animate-bounce-in" style="animation-delay: 0.3s">s</span><span class="animate-bounce-in" style="animation-delay: 0.35s">i</span><span class="animate-bounce-in" style="animation-delay: 0.4s">f</span><span class="animate-bounce-in" style="animation-delay: 0.45s">i</span><span class="animate-bounce-in" style="animation-delay: 0.5s">e</span><span class="animate-bounce-in" style="animation-delay: 0.55s">d</span><span class="animate-bounce-in" style="animation-delay: 0.6s">s</span>
                </span>
              </h2>
            </a>"""

idx_replace = """<div class="flex items-center justify-center gap-4 group cursor-pointer animate-logo-in" style="animation-delay: 0.2s;">
            <img src="images/classifieds-logo.png" alt="ZipZapZoi" class="h-24 md:h-32 object-contain animate-logo-bouncy">
            <h1 class="flex flex-col items-start">
              <span class="text-6xl md:text-8xl text-3d-vibrant" style="font-family: 'Fredoka One', cursive;">
                  <span style="color: #0066ff;">ZipZap</span><span style="color: #ff007f;">Zoi</span>
              </span>
              <span class="text-4xl md:text-6xl text-black flex items-baseline" style="font-family: 'Righteous', cursive; text-shadow: 2px 2px 0 #ddd, 4px 4px 0 #888, 6px 6px 10px rgba(0,0,0,0.6);">
                  <span class="animate-bounce-in" style="animation-delay: 0.1s">C</span><span class="animate-bounce-in" style="animation-delay: 0.15s">l</span><span class="animate-bounce-in" style="animation-delay: 0.2s">a</span><span class="animate-bounce-in" style="animation-delay: 0.25s">s</span><span class="animate-bounce-in" style="animation-delay: 0.3s">s</span><span class="animate-bounce-in" style="animation-delay: 0.35s">i</span><span class="animate-bounce-in" style="animation-delay: 0.4s">f</span><span class="animate-bounce-in" style="animation-delay: 0.45s">i</span><span class="animate-bounce-in" style="animation-delay: 0.5s">e</span><span class="animate-bounce-in" style="animation-delay: 0.55s">d</span><span class="animate-bounce-in" style="animation-delay: 0.6s">s</span>
              </span>
            </h1>
          </div>"""

new_style_block = """
<style id="zzz-logo-styles">
  @keyframes bounce-gentle {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-4px); }
  }
  @keyframes bounce-in-letter {
    0% { opacity: 0; transform: translateY(-15px) scale(0.9); }
    50% { opacity: 1; transform: translateY(3px) scale(1.05); }
    75% { transform: translateY(-1px) scale(0.98); }
    100% { opacity: 1; transform: translateY(0) scale(1); }
  }
  .animate-bounce-in {
    opacity: 0;
    display: inline-block;
    animation: bounce-in-letter 0.5s cubic-bezier(0.28, 0.84, 0.42, 1) forwards;
  }
  .animate-logo-bouncy {
    animation: bounce-gentle 3s infinite ease-in-out;
  }
</style>
"""

for f in html_files:
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    modified = False

    # Replace old style block with new style block
    old_style_pattern = r'<style id="zzz-logo-styles">.*?</style>'
    if re.search(old_style_pattern, content, re.DOTALL):
        content = re.sub(old_style_pattern, new_style_block, content, flags=re.DOTALL)
        modified = True

    # Replace typical standard logos (the ones I injected in v2)
    pattern1 = r'<a[^>]*href="index.html"[^>]*>[\s\n]*<img[^>]*images/classifieds-logo\.png[^>]*>[\s\n]*<h2[^>]*>[\s\n]*<span[^>]*>ZipZapZoi</span>[\s\n]*<span[^>]*>Classifieds</span>[\s\n]*</h2>[\s\n]*</a>'
    
    new_content = re.sub(pattern1, new_logo_html, content, flags=re.IGNORECASE | re.DOTALL)
    if new_content != content:
        modified = True
        content = new_content
        
    if 'index.html' in f:
        # Replace the big text-3d-vibrant button in index.html (the one from v2)
        idx_pattern = r'<div class="flex items-center justify-center gap-4 group cursor-pointer animate-logo-in animate-logo-pulse"[^>]*>[\s\n]*<img[^>]*images/classifieds-logo\.png[^>]*>[\s\n]*<h1[^>]*>[\s\n]*<span[^>]*>ZipZapZoi</span>[\s\n]*<span[^>]*>Classifieds</span>[\s\n]*</h1>[\s\n]*</div>'
        new_content = re.sub(idx_pattern, idx_replace, content, flags=re.IGNORECASE | re.DOTALL)
        if new_content != content:
            modified = True
            content = new_content

    if modified:
        with open(f, 'w', encoding='utf-8') as file:
            file.write(content)
        print(f"Updated {os.path.basename(f)}")

print("Finished updating all logos.")
