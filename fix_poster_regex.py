import os
import re

file_path = 'D:/zipzapzoi/ZIpZapZoi Codes/Listing Detail.html'
with open(file_path, 'r', encoding='utf-8') as file:
    content = file.read()

# Replace Image loading block
pattern_img = r'const img = new Image\(\);\s*img\.crossOrigin = "Anonymous";\s*const imgSrc = .*?;\s*await new Promise\(\(res, rej\) => \{ img\.onload = res; img\.onerror = rej; img\.src = imgSrc; \}\);'

new_img = """const imgSrc = (currentListing.images && currentListing.images.length > 0) ? currentListing.images[0] : 'https://placehold.co/1080x810/1e293b/ffffff.png?text=ZipZapZoi';
            
            const loadCanvasImage = async (url) => {
              return new Promise((resolve) => {
                const img = new Image();
                img.crossOrigin = "Anonymous";
                img.onload = () => resolve(img);
                img.onerror = () => {
                  console.warn("CORS failed for main image, trying local fallback");
                  const fallback = new Image();
                  fallback.onload = () => resolve(fallback);
                  fallback.onerror = () => resolve(null);
                  fallback.src = 'images/infinity-only.png';
                };
                img.src = url;
              });
            };
            
            const img = await loadCanvasImage(imgSrc);"""

content = re.sub(pattern_img, new_img, content, flags=re.DOTALL)

# Also wrap the draw calls so they don't break if img is null
pattern_draw = r'(const targetW = 1080;.*?ctx\.restore\(\);)'
new_draw = """if (img) {
              \\1
            }"""
content = re.sub(pattern_draw, new_draw, content, flags=re.DOTALL)

# Replace the toBlob block
pattern_blob = r'canvas\.toBlob\(async \(blob\) => \{.*?(btn\.innerHTML = oldContent;)\s*\}, \'image/png\', 0\.9\);'

new_blob = """// Convert to blob and share/download
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png', 0.9));
            if (!blob) throw new Error("Canvas is tainted or blob creation failed");
            
            const file = new File([blob], `ZipZapZoi_${currentListing.id}.png`, { type: 'image/png' });
            
            // Try Native Mobile Share
            if (navigator.canShare && navigator.canShare({ files: [file] })) {
               try {
                 await navigator.share({
                   title: currentListing.title,
                   text: `Check out this listing on ZipZapZoi!`,
                   url: window.location.origin + `/Listing%20Detail.html?id=${currentListing.id}`,
                   files: [file]
                 });
               } catch (e) { console.log('Share canceled', e); }
            } else {
               // Fallback: Trigger Download automatically on Desktop
               const urlObj = URL.createObjectURL(blob);
               const a = document.createElement('a');
               a.href = urlObj;
               a.download = `ZipZapZoi_Poster_${currentListing.id}.png`;
               document.body.appendChild(a);
               a.click();
               document.body.removeChild(a);
               
               // Copy link to clipboard for convenience
               let titleForCopy = currentListing.title || 'Product';
               const link = window.location.origin + `/Listing%20Detail.html?id=${currentListing.id}`;
               navigator.clipboard.writeText(`Check out ${titleForCopy} on ZipZapZoi: ` + link).catch(()=>{});
               showToast('Poster downloading! Link copied to clipboard.', 'success');
            }
            btn.innerHTML = oldContent;"""

content = re.sub(pattern_blob, new_blob, content, flags=re.DOTALL)

with open(file_path, 'w', encoding='utf-8') as file:
    file.write(content)

print("Regex patched Listing Detail successfully.")
