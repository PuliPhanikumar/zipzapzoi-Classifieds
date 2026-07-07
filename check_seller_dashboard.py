import re, subprocess, tempfile, os

with open('Seller Dashboard.html', 'r', encoding='utf-8') as f:
    content = f.read()

scripts = re.findall(r'<script[^>]*>(.*?)</script>', content, re.DOTALL | re.IGNORECASE)
for i, s in enumerate(scripts):
    if not s.strip(): continue
    with tempfile.NamedTemporaryFile(mode='w', suffix='.js', delete=False, encoding='utf-8') as f:
        f.write(s)
        temp_name = f.name
    
    res = subprocess.run(['node', '-c', temp_name], capture_output=True, text=True)
    if res.returncode != 0:
        print(f"Error in script {i}: {res.stderr}")
    else:
        print(f"Script {i} OK")
    os.remove(temp_name)
