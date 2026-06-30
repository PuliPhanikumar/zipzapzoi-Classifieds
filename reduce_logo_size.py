import os
import glob
import re

html_files = glob.glob('D:/zipzapzoi/ZIpZapZoi Codes/*.html')

for f in html_files:
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    modified = False

    # Navbar logo replacements
    replacements = [
        ('class="h-10 md:h-12 object-contain animate-logo-bouncy"', 'class="h-7 md:h-9 object-contain animate-logo-bouncy"'),
        ('class="text-2xl md:text-3xl font-black"', 'class="text-xl md:text-2xl font-black"'),
        ('class="text-xl md:text-2xl text-black flex items-baseline"', 'class="text-lg md:text-xl text-black flex items-baseline"'),
        # Index hero logo replacements
        ('class="h-24 md:h-32 object-contain animate-logo-bouncy"', 'class="h-16 md:h-20 object-contain animate-logo-bouncy"'),
        ('class="text-6xl md:text-8xl text-3d-vibrant"', 'class="text-5xl md:text-6xl text-3d-vibrant"'),
        ('class="text-4xl md:text-6xl text-black flex items-baseline"', 'class="text-3xl md:text-4xl text-black flex items-baseline"')
    ]

    for old_str, new_str in replacements:
        if old_str in content:
            content = content.replace(old_str, new_str)
            modified = True

    if modified:
        with open(f, 'w', encoding='utf-8') as file:
            file.write(content)
        print(f"Updated sizes in {os.path.basename(f)}")

print("Finished reducing logo sizes.")
