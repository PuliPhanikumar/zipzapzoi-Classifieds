import os
import glob
import re

html_files = glob.glob('D:/zipzapzoi/ZIpZapZoi Codes/*.html')

new_logo_html = """<a href="index.html" class="flex items-center gap-2 group cursor-pointer zzz-logo-link">
              <img src="images/infinity-only.png" alt="ZipZapZoi Logo" class="h-10 md:h-12 object-contain animate-logo-bouncy">
              <h2 class="tracking-tight flex items-baseline gap-1" style="margin: 0; padding: 0;">
                <span class="text-2xl md:text-3xl font-black" style="font-family: 'Fredoka One', cursive;">
                  <span style="color: #0066ff;">ZipZap</span><span style="color: #ff007f;">Zoi</span>
                </span>
                <span class="text-xl md:text-2xl text-black flex items-baseline" style="font-family: 'Righteous', cursive; text-shadow: 1px 1px 0 #999, 2px 2px 0 #555, 3px 3px 4px rgba(0,0,0,0.5);">
                  <span class="animate-bounce-in" style="animation-delay: 0.1s">C</span><span class="animate-bounce-in" style="animation-delay: 0.15s">l</span><span class="animate-bounce-in" style="animation-delay: 0.2s">a</span><span class="animate-bounce-in" style="animation-delay: 0.25s">s</span><span class="animate-bounce-in" style="animation-delay: 0.3s">s</span><span class="animate-bounce-in" style="animation-delay: 0.35s">i</span><span class="animate-bounce-in" style="animation-delay: 0.4s">f</span><span class="animate-bounce-in" style="animation-delay: 0.45s">i</span><span class="animate-bounce-in" style="animation-delay: 0.5s">e</span><span class="animate-bounce-in" style="animation-delay: 0.55s">d</span><span class="animate-bounce-in" style="animation-delay: 0.6s">s</span>
                </span>
              </h2>
            </a>"""

font_tag = '<link href="https://fonts.googleapis.com/css2?family=Righteous&display=swap" rel="stylesheet"/>'
font_tag2 = '<link href="https://fonts.googleapis.com/css2?family=Fredoka+One&display=swap" rel="stylesheet"/>'

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

    # Inject missing dependencies
    if 'family=Righteous' not in content and '</head>' in content:
        content = content.replace('</head>', f'{font_tag}\\n</head>')
        modified = True
    if 'Fredoka+One' not in content and '</head>' in content:
        content = content.replace('</head>', f'{font_tag2}\\n</head>')
        modified = True
        
    if 'zzz-logo-styles' not in content and '</head>' in content:
        content = content.replace('</head>', f'{new_style_block}\\n</head>')
        modified = True

    if 'index.html' not in f:
        # Match standard logo blocks that might not have been matched before
        # Case 1: <a> ... ZipZapZoi ... </a>
        pattern1 = r'<a[^>]*>[\s\n]*(?:<[^>]+>[\s\n]*)*(?:all_inclusive|zipzapzoi-logo\.png|classifieds-logo\.png|infinity-only\.png).*?ZipZapZoi.*?</a>'
        # Case 2: <div ...> ... ZipZapZoi ... </div>  like in dashboard.html
        pattern2 = r'<div[^>]*class="[^"]*flex items-center[^"]*"[^>]*>[\s\n]*<span[^>]*>all_inclusive</span>[\s\n]*<span[^>]*>ZipZapZoi</span>[\s\n]*</div>'

        # If it already has the exact new logo, don't replace
        if 'zzz-logo-link' not in content:
            # Let's replace whatever logo we find
            new_content = re.sub(pattern1, new_logo_html, content, flags=re.IGNORECASE | re.DOTALL)
            if new_content == content:
                new_content = re.sub(pattern2, new_logo_html, content, flags=re.IGNORECASE | re.DOTALL)
                
            if new_content != content:
                modified = True
                content = new_content

    if modified:
        with open(f, 'w', encoding='utf-8') as file:
            file.write(content)
        print(f"Updated {os.path.basename(f)}")

print("Finished updating all logos.")
