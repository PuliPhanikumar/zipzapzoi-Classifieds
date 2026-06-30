import os
import glob

html_files = glob.glob('D:/zipzapzoi/ZIpZapZoi Codes/*.html')

for f in html_files:
    with open(f, 'r', encoding='utf-8') as file:
        content = file.read()
    
    modified = False

    # Standard navbar text-shadow and adding pure black
    old_shadow_1 = "text-shadow: 1px 1px 0 #999, 2px 2px 0 #555, 3px 3px 4px rgba(0,0,0,0.5);"
    new_shadow_1 = "color: #000; text-shadow: 2px 4px 6px rgba(0,0,0,0.4);"
    if old_shadow_1 in content:
        content = content.replace(old_shadow_1, new_shadow_1)
        modified = True

    # Index hero text-shadow and adding pure black
    old_shadow_2 = "text-shadow: 2px 2px 0 #ddd, 4px 4px 0 #888, 6px 6px 10px rgba(0,0,0,0.6);"
    new_shadow_2 = "color: #000; text-shadow: 4px 8px 12px rgba(0,0,0,0.5);"
    if old_shadow_2 in content:
        content = content.replace(old_shadow_2, new_shadow_2)
        modified = True

    if modified:
        with open(f, 'w', encoding='utf-8') as file:
            file.write(content)
        print(f"Updated shadows in {os.path.basename(f)}")

print("Finished updating text shadows.")
