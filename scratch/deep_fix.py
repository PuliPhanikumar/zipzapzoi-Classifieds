import re

with open('classifieds.html', 'r', encoding='utf-8') as f:
    html = f.read()

new_js = """      // =====================================
      // 5. 3D GRID RENDER (API WIRED)
      // =====================================
      async function renderGrid() {
        const grid = document.getElementById('listingsGrid');

        // Show Skeletons
        let skeletons = '';
        for(let i=0; i<6; i++) {
            skeletons += `
            <div class="card-3d bg-white dark:bg-card-dark rounded-3xl overflow-hidden border border-gray-100 dark:border-gray-800 p-4 animate-pulse">
                <div class="h-48 w-full bg-gray-200 dark:bg-gray-800 rounded-2xl mb-4"></div>
                <div class="h-4 w-3/4 bg-gray-200 dark:bg-gray-800 rounded mb-2"></div>
                <div class="h-6 w-1/2 bg-gray-200 dark:bg-gray-800 rounded mb-4"></div>
                <div class="h-3 w-1/3 bg-gray-200 dark:bg-gray-800 rounded"></div>
            </div>`;
        }
        grid.innerHTML = skeletons;

        // Build API query parameters
        const params = new URLSearchParams();
        params.append('status', 'active');
        
        if (activeCategory !== 'All') params.append('category', activeCategory);
        
        const q = document.getElementById('searchQuery').value.trim();
        if (q) params.append('search', q);
        
        const loc = document.getElementById('searchLoc').value.trim();
        if (loc) params.append('city', loc);
        
        const minP = document.getElementById('minPrice').value;
        if (!isNaN(parseFloat(minP)) && minP !== "") params.append('min_price', minP);
        
        const maxP = document.getElementById('maxPrice').value;
        if (!isNaN(parseFloat(maxP)) && maxP !== "") params.append('max_price', maxP);
        
        const sort = document.getElementById('sortFilter').value;
        params.append('sort', sort);

        // Fetch from API
        try {
          const res = await (window.ZZZ && window.ZZZ.api ? window.ZZZ.api.fetch(`/api/listings.php?${params.toString()}`) : fetch(`/api/listings.php?${params.toString()}`).then(r => r.json()));
          
          let filtered = res.data ? res.data.listings : (res.listings || []);
          
          // Apply local condition filter since API doesn't support condition filter natively yet
          const cond = document.getElementById('conditionFilter').value;
          if(cond !== 'all') {
             filtered = filtered.filter(l => (l.condition || 'used').toLowerCase() === cond);
          }

          // Filter badge + result count
          let activeFilters = 0;
          if (q) activeFilters++;
          if (loc) activeFilters++;
          if (minP !== '') activeFilters++;
          if (maxP !== '') activeFilters++;
          if (cond !== 'all') activeFilters++;
          if (sort !== 'newest') activeFilters++;
          if (activeCategory !== 'All') activeFilters++;
          
          const badge = document.getElementById('filterBadge');
          if (badge) {
            if (activeFilters > 0) { badge.textContent = activeFilters + (activeFilters === 1 ? ' filter' : ' filters'); badge.classList.remove('hidden'); }
            else { badge.classList.add('hidden'); }
          }
          const rc = document.getElementById('resultCount');
          if (rc) {
            if (activeFilters > 0) { rc.textContent = filtered.length + ' listing' + (filtered.length !== 1 ? 's' : '') + ' found'; rc.classList.remove('hidden'); }
            else { rc.classList.add('hidden'); }
          }

          // Render
          if(filtered.length === 0) {
            grid.innerHTML = `
              <div class="col-span-full flex flex-col items-center justify-center py-20 text-center animate-pop">
                <span class="material-symbols-outlined text-6xl text-gray-300 dark:text-gray-600 mb-4">search_off</span>
                <h3 class="text-xl font-bold text-gray-800 dark:text-white">No results found</h3>
                <p class="text-gray-500 dark:text-gray-400 mt-2">Try adjusting your filters or search query.</p>
                <button onclick="clearFilters()" class="mt-6 font-bold text-primary hover:underline">Clear Filters</button>
              </div>
            `;
            return;
          }

          const favs = window.ZZZ ? window.ZZZ.getFavoriteIds() : JSON.parse(localStorage.getItem('zzz_favorites') || '[]');

          grid.innerHTML = filtered.map((l, index) => {
            const isFav = favs.includes(l.id);
            const timeDelay = index * 0.05; // Cascade animation

            return `
              <div class="card-3d bg-white dark:bg-card-dark rounded-3xl overflow-hidden border border-gray-100 dark:border-gray-800 cursor-pointer relative group opacity-0 animate-pop" style="animation-delay: ${timeDelay}s" onclick="window.location.href='Listing Detail.html?id=${l.id}'">
                
                <!-- Image Area -->
                <div class="relative h-56 overflow-hidden bg-gray-100 dark:bg-gray-900">
                  <img src="${l.images?.[0] || 'https://via.placeholder.com/400x300?text=No+Image'}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110">
                  
                  <!-- Category Badge -->
                  <div class="absolute top-4 left-4 bg-white/90 dark:bg-black/70 backdrop-blur-sm px-3 py-1 rounded-full text-[10px] font-bold text-gray-800 dark:text-white uppercase tracking-wider card-image-wrap">
                    ${l.category}
                  </div>

                  <!-- Favorite Button -->
                  <button onclick="event.stopPropagation(); toggleFav('${l.id}', this)" class="fav-btn absolute top-4 right-4 w-10 h-10 rounded-full flex items-center justify-center card-image-wrap ${isFav ? 'active' : ''}">
                    <span class="material-symbols-outlined text-xl">favorite</span>
                  </button>
                </div>

                <!-- Details Area -->
                <div class="p-6">
                  <div class="flex justify-between items-start mb-2">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white truncate pr-4">${l.title}</h3>
                    <p class="text-xl font-black text-primary">₹${Number(l.price).toLocaleString()}</p>
                  </div>
                  
                  <p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-2 mb-4">${l.description}</p>
                  
                  <div class="flex items-center gap-4 text-xs font-bold text-gray-400 dark:text-gray-500 pt-4 border-t border-gray-100 dark:border-gray-800">
                    <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">location_on</span> ${l.location_city || l.location || 'Local'}</span>
                    <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">schedule</span> ${l.created_at ? new Date(l.created_at).toLocaleDateString() : 'Just now'}</span>
                  </div>
                </div>

              </div>
            `;
          }).join('');
        } catch(e) {
          console.error('Error fetching listings:', e);
          grid.innerHTML = '<div class="col-span-full text-center text-red-500 py-10">Error loading listings. Please try again later.</div>';
        }
      }

      // =====================================
      // 6. ACTIONS
      // =====================================
      // Live debounced search
      let _searchDebounce = null;
      function executeSearch() {
        if(_searchDebounce) clearTimeout(_searchDebounce);
        _searchDebounce = setTimeout(() => {
            renderGrid();
            const grid = document.getElementById('listingsGrid');
            if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 400);
      }

      function clearFilters() {
        document.getElementById('minPrice').value = '';
        document.getElementById('maxPrice').value = '';
        document.getElementById('conditionFilter').value = 'all';
        document.getElementById('searchQuery').value = '';
        document.getElementById('searchLoc').value = '';
        document.getElementById('sortFilter').value = 'newest';
        activeCategory = 'All';
        document.getElementById('gridTitle').textContent = 'Fresh Recommendations';
        renderCategories();
        renderGrid();
      }

      async function toggleFav(id, btn) {
        if(!currentUser) {
          window.location.href = `Login Page.html?redirect=classifieds.html`;
          return;
        }
        
        let isFav = btn.classList.contains('active');
        
        // Optimistic UI update
        if(!isFav) {
          btn.classList.add('active');
        } else {
          btn.classList.remove('active');
        }

        // API call
        if(window.ZZZ && window.ZZZ.api) {
          try {
            await window.ZZZ.api.toggleFavorite(id);
          } catch(e) {
            console.error('Fav error:', e);
            // Revert UI on failure
            if(!isFav) btn.classList.remove('active');
            else btn.classList.add('active');
          }
        }
      }"""

# Inject api.js inclusion
html = html.replace('<script src="js/utils.js"></script>', '<script src="js/utils.js"></script>\n      <script src="js/api.js"></script>')

# Replace the block from "5. 3D GRID RENDER" down to just before "// ─── QUOTA-AWARE"
pattern = re.compile(r'// =====================================\s*// 5\. 3D GRID RENDER.*?function toggleFav\(id, btn\) \{.*?(?=// ─── QUOTA-AWARE)', re.DOTALL)
html = pattern.sub(new_js, html)

with open('classifieds.html', 'w', encoding='utf-8') as f:
    f.write(html)
