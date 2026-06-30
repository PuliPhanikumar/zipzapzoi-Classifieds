import os

file_path = 'D:/zipzapzoi/ZIpZapZoi Codes/Listing Detail.html'
with open(file_path, 'r', encoding='utf-8') as file:
    content = file.read()

# Replace image loader
old_image_loader = """            // 2. Draw Main Image (Top 75%)
            const img = new Image();
            img.crossOrigin = "Anonymous";
            const imgSrc = (currentListing.images && currentListing.images.length > 0) ? currentListing.images[0] : 'https://via.placeholder.com/1080x810.png?text=ZipZapZoi';
            await new Promise((res, rej) => { img.onload = res; img.onerror = rej; img.src = imgSrc; });"""

new_image_loader = """            // 2. Draw Main Image (Top 75%)
            const imgSrc = (currentListing.images && currentListing.images.length > 0) ? currentListing.images[0] : 'https://placehold.co/1080x810/1e293b/ffffff.png?text=ZipZapZoi';
            
            const loadCanvasImage = async (url) => {
              return new Promise((resolve, reject) => {
                const img = new Image();
                img.crossOrigin = "Anonymous";
                img.onload = () => resolve(img);
                img.onerror = () => {
                  console.warn("CORS failed for", url, "- attempting fallback placeholder.");
                  const fallback = new Image();
                  fallback.crossOrigin = "Anonymous";
                  fallback.onload = () => resolve(fallback);
                  fallback.onerror = reject;
                  fallback.src = 'https://placehold.co/1080x810/1e293b/ffffff.png?text=ZipZapZoi';
                };
                img.src = url;
              });
            };
            
            const img = await loadCanvasImage(imgSrc);"""

content = content.replace(old_image_loader, new_image_loader)


# Replace toBlob block
old_toblob = """            // Convert to blob and share/download
            canvas.toBlob(async (blob) => {
              if (!blob) throw new Error("Blob failed");
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
                 const link = window.location.origin + `/Listing%20Detail.html?id=${currentListing.id}`;
                 navigator.clipboard.writeText(`Check out ${title} on ZipZapZoi: ` + link).catch(()=>{});
                 showToast('Poster downloading! Link copied to clipboard.', 'success');
              }
              btn.innerHTML = oldContent;
            }, 'image/png', 0.9);"""


new_toblob = """            // Convert to blob and share/download
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

content = content.replace(old_toblob, new_toblob)

with open(file_path, 'w', encoding='utf-8') as file:
    file.write(content)

print("Listing Detail patched successfully.")
