import re

with open('Seller Dashboard.html', 'r', encoding='utf-8') as f:
    html = f.read()

# Fix mutated rupee symbol
html = html.replace(',1${Number', '&#8377;${Number')
html = html.replace(',1', '&#8377;')
html = html.replace(',1${Number', '&#8377;${Number')

# Add escapeHtml if missing
if 'function escapeHtml' not in html:
    html = html.replace('</script>\n</body>', '''
    function escapeHtml(str) {
        if (!str) return "";
        return str.toString().replace(/[&<>"']/g, function(m) {
            return {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                "\\"": "&quot;",
                "\\'": "&#39;"
            }[m];
        });
    }
</script>
</body>''')

with open('Seller Dashboard.html', 'w', encoding='utf-8') as f:
    f.write(html)
