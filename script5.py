import sys
import re

file_path = "D:\\zipzapzoi\\ZIpZapZoi Codes\\classifieds.html"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# Extract the demand section
demand_pattern = re.compile(r"(\s*<!-- ACTIVE BUYER DEMAND SECTION -->\s*<div class=\"w-full mt-0 mb-8\">.*?buyerDemandContainer.*?</div>\s*)", re.DOTALL)
demand_match = demand_pattern.search(content)

if demand_match:
    demand_html = demand_match.group(1)
    # Remove it from its current place
    content = content.replace(demand_html, "\n")
    
    # Let's change the tag back to <section> and fix classes for full-width layout
    demand_html_tweaked = demand_html.replace('<div class="w-full mt-0 mb-8">', '<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-12 mb-8">')
    # Use rsplit to safely replace the last </div> with </section>
    parts = demand_html_tweaked.rsplit('</div>', 1)
    if len(parts) == 2:
        demand_html_tweaked = '</section>'.join(parts)
    
    # Inject it right before </main>
    target_pattern = r"(</main>)"
    new_content = demand_html_tweaked + "\n" + r"\1"
    content = re.sub(target_pattern, new_content, content, count=1)
    
    with open(file_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Successfully moved demand section down.")
else:
    print("Could not find demand section. Pattern might be mismatched.")
