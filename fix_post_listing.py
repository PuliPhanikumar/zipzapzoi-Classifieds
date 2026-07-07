import re

with open('Post Listing.html', 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update the overall main background and card styles
content = content.replace(
    'class="bg-card-light dark:bg-card-dark rounded-2xl shadow-bouncy p-6 border border-border-light dark:border-border-dark"',
    'class="bg-white/80 dark:bg-slate-900/80 backdrop-blur-2xl rounded-[2rem] shadow-2xl p-8 border border-white/20 dark:border-white/5 relative overflow-hidden"'
)

# 2. Update category cards in JS initial render
old_div_class = 'div.className = `cursor-pointer flex flex-col items-center justify-center p-4 rounded-xl border-2 border-transparent bg-gray-50 dark:bg-gray-800 hover:border-secondary hover:bg-white dark:bg-card-dark dark:hover:bg-gray-700 transition-all gap-2 group`;'
new_div_class = 'div.className = `cursor-pointer flex flex-col items-center justify-center p-6 rounded-3xl border-2 border-transparent bg-gray-50/50 dark:bg-gray-800/50 hover:border-secondary hover:bg-white dark:bg-card-dark dark:hover:bg-gray-700 hover:shadow-xl hover:scale-[1.02] transition-all duration-300 gap-3 group`;'
content = content.replace(old_div_class, new_div_class)

# 3. Update category cards in JS fetch render
old_fetch_class = "div.className = 'cursor-pointer flex flex-col items-center justify-center p-4 rounded-xl border-2 border-transparent bg-gray-50 dark:bg-gray-800 hover:border-secondary hover:bg-white dark:bg-card-dark dark:hover:bg-gray-700 transition-all gap-2 group';"
new_fetch_class = "div.className = 'cursor-pointer flex flex-col items-center justify-center p-6 rounded-3xl border-2 border-transparent bg-gray-50/50 dark:bg-gray-800/50 hover:border-secondary hover:bg-white dark:bg-card-dark dark:hover:bg-gray-700 hover:shadow-xl hover:scale-[1.02] transition-all duration-300 gap-3 group';"
content = content.replace(old_fetch_class, new_fetch_class)

# 4. Make the fetch logic bulletproof
old_fetch = """      // Clear the local schema so it exactly matches the DB schema (admin settings)
      schema = {};
      
      brain.categories.forEach(function(cat) {
        var mySubs = (brain.subcategories||[]).filter(function(s){ return s.categoryId === cat.id; });
        schema[cat.id] = {
          label: cat.label, icon: cat.icon,
          color: cat.color || 'text-gray-500 dark:text-gray-400',
          priceLabel: cat.priceLabel, hidePrice: cat.hidePrice,
          photoLimit: cat.photoLimit, note: cat.note,
          subs: mySubs.map(function(s){ return s.label; }),
          subIds: mySubs, fields: []
        };
      });
      // Re-render category grid if user hasn't selected a category yet
      if (!currentCategoryKey) {
        var grid = document.getElementById('categoryGrid');
        if (grid) {
          grid.innerHTML = '';
          Object.keys(schema).forEach(function(key) {
            var cat = schema[key];
            var div = document.createElement('div');
            div.className = 'cursor-pointer flex flex-col items-center justify-center p-4 rounded-xl border-2 border-transparent bg-gray-50 dark:bg-gray-800 hover:border-secondary hover:bg-white dark:bg-card-dark dark:hover:bg-gray-700 transition-all gap-2 group';
            div.innerHTML = '<span class="material-symbols-outlined text-4xl ' + cat.color + ' group-hover:scale-110 transition-transform">' + (cat.icon || 'category') + '</span><span class="text-sm font-bold text-center">' + cat.label + '</span>';
            div.onclick = (function(k){ return function(){ selectCategory(k); }; })(key);
            grid.appendChild(div);
          });
        }
      }"""

new_fetch = """      // Bulletproof fetch logic
      try {
        let newSchema = {};
        brain.categories.forEach(function(cat) {
          if(!cat || !cat.id) return;
          var mySubs = (brain.subcategories||[]).filter(function(s){ return s.categoryId === cat.id; });
          newSchema[cat.id] = {
            label: cat.label || 'Unknown', 
            icon: cat.icon || 'category',
            color: cat.color || 'text-gray-500 dark:text-gray-400',
            priceLabel: cat.priceLabel, 
            hidePrice: cat.hidePrice,
            photoLimit: cat.photoLimit, 
            note: cat.note,
            subs: mySubs.map(function(s){ return s.label; }),
            subIds: mySubs, 
            fields: []
          };
        });
        
        // Only override if we parsed successfully
        if (Object.keys(newSchema).length > 0) {
          schema = newSchema;
        }

        // Re-render category grid if user hasn't selected a category yet
        if (!currentCategoryKey) {
          var grid = document.getElementById('categoryGrid');
          if (grid) {
            grid.innerHTML = '';
            Object.keys(schema).forEach(function(key) {
              var cat = schema[key];
              var div = document.createElement('div');
              div.className = 'cursor-pointer flex flex-col items-center justify-center p-6 rounded-3xl border-2 border-transparent bg-gray-50/50 dark:bg-gray-800/50 hover:border-secondary hover:bg-white dark:bg-card-dark dark:hover:bg-gray-700 hover:shadow-xl hover:scale-[1.02] transition-all duration-300 gap-3 group';
              div.innerHTML = '<span class="material-symbols-outlined text-4xl ' + (cat.color||'') + ' group-hover:scale-110 transition-transform">' + (cat.icon || 'category') + '</span><span class="text-sm font-bold text-center">' + (cat.label||'Unknown') + '</span>';
              div.onclick = (function(k){ return function(){ selectCategory(k); }; })(key);
              grid.appendChild(div);
            });
          }
        }
      } catch(e) {
        console.error("Critical error in re-rendering fetched schema:", e);
      }"""

content = content.replace(old_fetch, new_fetch)

# 5. Fix body classes for rich aesthetic
content = content.replace(
    '<body class="font-display text-text-light dark:text-text-dark antialiased bg-background-light dark:bg-background-dark text-gray-800 dark:text-gray-100 transition-colors duration-300">',
    '<body class="font-display text-text-light dark:text-text-dark antialiased bg-slate-50 dark:bg-slate-950 text-gray-800 dark:text-gray-100 transition-colors duration-300 bg-[url(\\\'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCI+CjxwYXRoIGQ9Ik0wIDBoNDB2NDBIMHoiIGZpbGw9Im5vbmUiLz4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMSIgZmlsbD0icmdiYSgwLDAsMCwwLjA1KSIvPgo8L3N2Zz4=\\\')] dark:bg-[url(\\\'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0MCIgaGVpZ2h0PSI0MCI+CjxwYXRoIGQ9Ik0wIDBoNDB2NDBIMHoiIGZpbGw9Im5vbmUiLz4KPGNpcmNsZSBjeD0iMjAiIGN5PSIyMCIgcj0iMSIgZmlsbD0icmdiYSgyNTUsMjU1LDI1NSwwLjA1KSIvPgo8L3N2Zz4=\\\')]">'
)

with open('Post Listing.html', 'w', encoding='utf-8') as f:
    f.write(content)
