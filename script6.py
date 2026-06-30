import re

file_path = "D:\\zipzapzoi\\ZIpZapZoi Codes\\Post Listing.html"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# 1. Add existingUrlsArray to globals
content = content.replace(
    "let selectedFilesArray = []; // Array to natively store selected File objects",
    "let selectedFilesArray = []; // Array to natively store selected File objects\n    let existingUrlsArray = []; // Existing images from server"
)

# 2. Populate existingUrlsArray on edit load
fetch_target = "document.getElementById('postTitle').value = item.title || '';"
fetch_replacement = """if(item.images && item.images.length > 0) {
                      existingUrlsArray = item.images;
                  } else if (item.thumbnail) {
                      existingUrlsArray = [item.thumbnail];
                  }
                  renderPhotos();
                  
                  document.getElementById('postTitle').value = item.title || '';"""
content = content.replace(fetch_target, fetch_replacement)

# 3. Update renderPhotos to display BOTH existing and new files
render_target = """function renderPhotos() {
    photoPreview.innerHTML = '';
    uploadedImages = [];"""

render_replacement = """window.removeExistingPhoto = function(index) {
        existingUrlsArray.splice(index, 1);
        renderPhotos();
    };

    function renderPhotos() {
    photoPreview.innerHTML = '';
    uploadedImages = [];
    const WARN_BYTES = 2 * 1024 * 1024;
    const MAX_BYTES  = 5 * 1024 * 1024;

    // Render existing images first
    existingUrlsArray.forEach((url, index) => {
        const div = document.createElement('div');
        div.className = 'aspect-square rounded-lg bg-cover bg-center border border-gray-300 relative group overflow-hidden shadow-sm';
        div.style.backgroundImage = `url(${url})`;
        
        let hoverControls = `<div class="absolute inset-0 bg-black/50 hidden group-hover:flex flex-col items-center justify-center text-white gap-2 transition-all">`;
        hoverControls += `<button type="button" onclick="window.removeExistingPhoto(${index})" class="text-[10px] font-bold bg-red-500/80 hover:bg-red-500 px-2 py-1 rounded-full flex items-center gap-1"><span class="material-symbols-outlined text-[12px]">delete</span> Delete</button>`;
        hoverControls += `</div>`;
        
        div.innerHTML = `${hoverControls}<div class="absolute top-0 right-0 bg-purple-500 text-white text-[9px] px-1 font-bold">Existing</div>`;
        photoPreview.appendChild(div);
    });"""
content = content.replace(render_target, render_replacement)

# 4. Limit check in fileInput change listener
limit_target = """const newFiles = Array.from(this.files);
    const max = (currentCategory && currentCategory.photoLimit) ? currentCategory.photoLimit : 8;
    
    // Add new files until we hit the max limit
    for(let file of newFiles) {
        if(selectedFilesArray.length < max) {"""
limit_replacement = """const newFiles = Array.from(this.files);
    const max = (currentCategory && currentCategory.photoLimit) ? currentCategory.photoLimit : 8;
    
    // Add new files until we hit the max limit
    for(let file of newFiles) {
        if((selectedFilesArray.length + existingUrlsArray.length) < max) {"""
content = content.replace(limit_target, limit_replacement)

# 5. Fix submit logic to combine existing + new
submit_target = """        let imageUrls = [];
      const files = selectedFilesArray;

      if (files.length > 0) {"""
submit_replacement = """        let imageUrls = [...existingUrlsArray];
      const files = selectedFilesArray;

      if (files.length > 0) {"""
content = content.replace(submit_target, submit_replacement)

submit_merge_target = """          try {
          const upRes  = await fetch('/api/uploads.php', {
            method: 'POST',
            credentials: 'include',
            body: formData,
          }).then(r => r.json());
          if (upRes.success && upRes.urls) {
            imageUrls = upRes.urls;
          }"""
submit_merge_replacement = """          try {
          const upRes  = await fetch('/api/uploads.php', {
            method: 'POST',
            credentials: 'include',
            body: formData,
          }).then(r => r.json());
          if (upRes.success && upRes.urls) {
            imageUrls = imageUrls.concat(upRes.urls);
          }"""
content = content.replace(submit_merge_target, submit_merge_replacement)

with open(file_path, "w", encoding="utf-8") as f:
    f.write(content)
print("Updated Post Listing.html to fix image vanishing bug.")
