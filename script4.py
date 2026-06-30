import sys
import re

file_path = "D:\\zipzapzoi\\ZIpZapZoi Codes\\classifieds.html"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# Extract the demand section
demand_pattern = re.compile(r"(\s*<!-- ACTIVE BUYER DEMAND SECTION -->\s*<section.*?buyerDemandContainer.*?</section>\s*)", re.DOTALL)
demand_match = demand_pattern.search(content)

if demand_match:
    demand_html = demand_match.group(1)
    # Remove it from its original place
    content = content.replace(demand_html, "\n")
    
    # Let's tweak margins for inner placement
    demand_html_tweaked = demand_html.replace('mt-6 mb-4', 'mt-0 mb-8')
    # Change section tag and max-w constraints since it's now in a flex child
    demand_html_tweaked = demand_html_tweaked.replace('<section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8', '<div class="w-full')
    demand_html_tweaked = demand_html_tweaked.replace('</section>', '</div>')
    
    # Inject it inside the right column: <div class="flex-1 min-w-0">
    target_pattern = r"(<div class=\"flex-1 min-w-0\">)"
    new_content = r"\1\n" + demand_html_tweaked
    content = re.sub(target_pattern, new_content, content, count=1)
    
    with open(file_path, "w", encoding="utf-8") as f:
        f.write(content)
    print("Successfully moved demand section.")
else:
    print("Could not find demand section.")
