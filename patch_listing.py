import sys
path = r'd:\zipzapzoi\ZIpZapZoi Codes\Post Listing.html'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# CHANGE 1: Add styles
css_insertion = '''
    /* === GEN Z POST LISTING === */
    /* AI Title Suggestions dropdown */
    #titleSuggestions {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: white;
      border: 2px solid #019863;
      border-top: none;
      border-radius: 0 0 12px 12px;
      z-index: 50;
      box-shadow: 0 8px 24px rgba(0,0,0,0.12);
      overflow: hidden;
    }
    html.dark #titleSuggestions { background: #1e293b; border-color: #059669; }
    .suggestion-item {
      padding: 10px 14px;
      cursor: pointer;
      transition: background 0.15s;
      border-bottom: 1px solid #f0f4f8;
      font-size: 0.875rem;
      font-weight: 600;
    }
    html.dark .suggestion-item { border-color: #334155; color: #e2e8f0; }
    .suggestion-item:hover { background: #f0fdf4; }
    html.dark .suggestion-item:hover { background: rgba(1,152,99,0.15); }
    .suggestion-item:last-child { border-bottom: none; }
    .suggestion-price { font-size: 0.75rem; color: #019863; font-weight: 800; margin-left: 8px; }

    /* Drag-drop upload zone */
    .upload-zone {
      border: 2.5px dashed #d1d5db;
      border-radius: 16px;
      transition: all 0.25s;
      cursor: pointer;
    }
    html.dark .upload-zone { border-color: #374151; }
    .upload-zone:hover, .upload-zone.drag-over {
      border-color: #019863;
      background: #f0fdf4;
      transform: scale(1.01);
    }
    html.dark .upload-zone:hover, html.dark .upload-zone.drag-over {
      background: rgba(1,152,99,0.08);
    }
    .upload-zone.drag-over { box-shadow: 0 0 20px rgba(1,152,99,0.3); }

    /* Urgency toggle pills */
    .urgency-toggle {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 16px;
      border-radius: 9999px;
      border: 2px solid #e5e7eb;
      cursor: pointer;
      transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
      font-weight: 700;
      font-size: 0.85rem;
      user-select: none;
      background: white;
      color: #374151;
    }
    html.dark .urgency-toggle { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    .urgency-toggle input { display: none; }
    .urgency-toggle.checked {
      border-color: #019863;
      background: linear-gradient(135deg, #f0fdf4, #dcfce7);
      color: #166534;
    }
    html.dark .urgency-toggle.checked { background: rgba(1,152,99,0.2); color: #86efac; }
    .urgency-toggle:hover { transform: scale(1.04); }

    /* Price comparison hint */
    #priceHint {
      transition: all 0.3s;
    }
</style>
'''
content = content.replace('</style>\n</head>', css_insertion + '</head>')
if '</style>\n</head>' not in content and css_insertion not in content:
    content = content.replace('</style>\n\n\n\n<link href="https://fonts.googleapis.com/css2?family=Righteous&display=swap" rel="stylesheet"/>\n</head>', css_insertion + '\n\n<link href="https://fonts.googleapis.com/css2?family=Righteous&display=swap" rel="stylesheet"/>\n</head>')


# CHANGE 2: Title input wrapper
title_original = '<input type="text" id="postTitle" class="w-full rounded-xl border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 py-3 px-4" placeholder="e.g. iPhone 14 Pro Max 256GB - Brand New" required>'
title_replaced = '''<div class="relative">
              <input type="text" id="postTitle" class="w-full rounded-xl border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 py-3 px-4" placeholder="e.g. iPhone 14 Pro Max 256GB - Brand New" required oninput="fetchTitleSuggestions(this.value)" onfocus="fetchTitleSuggestions(this.value)">
              <div id="titleSuggestions" class="hidden"></div>
            </div>'''
content = content.replace(title_original, title_replaced)

# CHANGE 3: Price hint
price_original = '<input type="number" id="postPrice" class="w-full pl-8 rounded-xl border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 py-3 px-4" placeholder="0">'
price_replaced = '''<input type="number" id="postPrice" class="w-full pl-8 rounded-xl border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 py-3 px-4" placeholder="0" oninput="checkPriceHint(this.value)">
              </div>
              <div id="priceHint" class="hidden mt-2 px-3 py-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl text-xs font-bold text-amber-700 dark:text-amber-400">
                💡 <span id="priceHintText"></span>'''
content = content.replace(price_original, price_replaced)

# CHANGE 4: Urgency toggles
urgency_insertion = '''            <!-- ⚡ URGENCY TOGGLES -->
            <div class="mt-6 mb-6">
              <label class="block text-sm font-bold text-gray-500 dark:text-gray-400 mb-3 uppercase tracking-wide">Boost Options</label>
              <div class="flex flex-wrap gap-3">
                <label class="urgency-toggle" id="urgentToggle" onclick="this.classList.toggle('checked')">
                  <input type="checkbox" name="is_urgent" value="1"> ⚡ Mark as Urgent
                </label>
                <label class="urgency-toggle" id="negotiableToggle" onclick="this.classList.toggle('checked')">
                  <input type="checkbox" name="is_negotiable" value="1"> 💬 Price Negotiable
                </label>
                <label class="urgency-toggle" id="whatsappToggle" onclick="this.classList.toggle('checked')">
                  <input type="checkbox" name="allow_whatsapp" value="1"> 📲 WhatsApp Contact
                </label>
              </div>
            </div>
'''
submit_div_original = '<div class="flex flex-col sm:flex-row items-center justify-between gap-4 mt-6">\n          <button type="button" onclick="goToStep3()" class="text-gray-600 dark:text-gray-300 font-bold px-6 py-3 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-xl transition-all w-full sm:w-auto">Back: Media</button>'
if submit_div_original in content:
    content = content.replace(submit_div_original, urgency_insertion + submit_div_original)
else:
    print("Could not find submit div")
    
# Upload zone
upload_original = '<div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl p-8 text-center hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-800 transition-colors cursor-pointer relative">'
upload_replaced = '<div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl p-8 text-center hover:bg-gray-50 dark:bg-gray-800 dark:hover:bg-gray-800 transition-colors cursor-pointer relative upload-zone">'
content = content.replace(upload_original, upload_replaced)

# CHANGE 5: JS
js_insertion = '''
  // === AI TITLE SUGGESTIONS ===
  let _suggDebounce = null;
  window.fetchTitleSuggestions = async function(val) {
    const box = document.getElementById('titleSuggestions');
    if (!box) return;
    if (!val || val.length < 3) { box.classList.add('hidden'); return; }
    clearTimeout(_suggDebounce);
    _suggDebounce = setTimeout(async () => {
      try {
        const cat = document.getElementById('category')?.value || document.getElementById('adCategory')?.value || '';
        const res = await fetch(`/api/listings.php?action=similar_titles&q=${encodeURIComponent(val)}&category=${encodeURIComponent(cat)}`);
        const data = await res.json();
        const titles = (data.data && data.data.titles) ? data.data.titles : [];
        if (!titles.length) { box.classList.add('hidden'); return; }
        box.innerHTML = titles.map(t => `
          <div class="suggestion-item" onclick="applySuggestion('${t.title.replace(/'/g, "\\\\'")}')">
            ${t.title}
            <span class="suggestion-price">₹${Number(t.price).toLocaleString('en-IN')}</span>
            <span class="text-xs text-gray-400 ml-1">(${t.category})</span>
          </div>`).join('');
        box.classList.remove('hidden');
      } catch(e) { box.classList.add('hidden'); }
    }, 350);
  };

  window.applySuggestion = function(title) {
    const input = document.getElementById('postTitle');
    if (input) { input.value = title; input.dispatchEvent(new Event('input')); }
    document.getElementById('titleSuggestions')?.classList.add('hidden');
  };

  document.addEventListener('click', e => {
    if (!e.target.closest('#titleSuggestions') && !e.target.closest('#postTitle')) {
      document.getElementById('titleSuggestions')?.classList.add('hidden');
    }
  });

  // === PRICE HINT ===
  window.checkPriceHint = async function(val) {
    const price = parseFloat(val);
    const hint = document.getElementById('priceHint');
    const hintText = document.getElementById('priceHintText');
    if (!hint || !hintText || !price || price <= 0) { hint?.classList.add('hidden'); return; }
    
    try {
      const cat = document.getElementById('category')?.value || document.getElementById('adCategory')?.value || '';
      const title = document.getElementById('postTitle')?.value || '';
      if (!title || title.length < 3) return;
      const res = await fetch(`/api/listings.php?action=similar_titles&q=${encodeURIComponent(title)}&category=${encodeURIComponent(cat)}`);
      const data = await res.json();
      const titles = (data.data && data.data.titles) ? data.data.titles : [];
      if (titles.length < 2) { hint.classList.add('hidden'); return; }

      const prices = titles.map(t => parseFloat(t.price)).filter(p => p > 0).sort((a,b) => a-b);
      if (!prices.length) return;
      const minP = prices[0], maxP = prices[prices.length - 1];
      const avgP = prices.reduce((a,b) => a+b, 0) / prices.length;

      if (price > avgP * 1.5) {
        hintText.textContent = `Similar items sell for ₹${Math.round(minP).toLocaleString()}–₹${Math.round(maxP).toLocaleString()}. Your price might be high.`;
      } else if (price < avgP * 0.5) {
        hintText.textContent = `Great deal! Similar items go for ₹${Math.round(avgP).toLocaleString()} on average.`;
      } else {
        hintText.textContent = `Competitive price! Similar items: ₹${Math.round(minP).toLocaleString()}–₹${Math.round(maxP).toLocaleString()}.`;
      }
      hint.classList.remove('hidden');
    } catch(e) {}
  };

  // === DRAG & DROP UPLOAD ZONE ===
  function initDragDrop() {
    const zone = document.querySelector('.upload-zone');
    if (!zone) return;
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('drag-over');
      const files = e.dataTransfer.files;
      if (files.length) {
        const input = document.getElementById('fileInput') || document.querySelector('input[type=file]');
        if (input) {
          const dt = new DataTransfer();
          Array.from(files).forEach(f => dt.items.add(f));
          input.files = dt.files;
          input.dispatchEvent(new Event('change'));
        }
      }
    });
  }
  document.addEventListener('DOMContentLoaded', initDragDrop);
</script>
'''
if '</script>\n</body>' in content:
    content = content.replace('</script>\n</body>', js_insertion + '</body>')
else:
    content = content.replace('</script>\n</html>', js_insertion + '</html>')

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)

print("Python script finished.")
