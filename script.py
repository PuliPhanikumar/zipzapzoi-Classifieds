import sys
import re

file_path = "D:\\zipzapzoi\\ZIpZapZoi Codes\\classifieds.html"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# 1. Replace the Wanted Board CTA
banner_pattern = re.compile(r"<!-- WANTED BOARD CTA -->.*?</section>", re.DOTALL)
new_banner = """<!-- WANTED BOARD CTA -->
        <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
          <div class="bg-gradient-to-r from-purple-600 to-indigo-600 rounded-xl p-3 flex flex-row items-center justify-between text-white shadow-sm relative overflow-hidden group">
            <div class="flex items-center gap-2 md:gap-4 relative z-10 w-full justify-between">
              <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-xl md:text-2xl animate-pulse">campaign</span>
                <div>
                  <h2 class="text-sm md:text-base font-black flex items-center gap-2">The Wanted Board</h2>
                  <p class="text-purple-100 text-[10px] md:text-xs hidden md:block">Can't find what you're looking for? Post a request!</p>
                </div>
              </div>
              <a href="wanted.html" class="bg-white text-purple-600 font-bold py-1.5 px-4 rounded-lg shadow hover:bg-gray-50 hover:scale-105 transition-all flex items-center gap-1 text-xs md:text-sm whitespace-nowrap ml-2">
                <span class="material-symbols-outlined text-[16px]">post_add</span> Post Request
              </a>
            </div>
          </div>
        </section>"""
content = banner_pattern.sub(new_banner, content)


# 2. Inject Wanted Fetch logic
fetch_pattern = re.compile(r"let filtered = res\.data \? res\.data\.listings : \(res\.listings \|\| \[\]\);\s*populateTrending\(filtered\);")
new_fetch = """let filtered = res.data ? res.data.listings : (res.listings || []);
            
            // Inject Wanted Ads
            try {
              let wParams = new URLSearchParams();
              if (activeCategory !== 'All') wParams.append('category', activeCategory);
              const wRes = await (window.ZZZ && window.ZZZ.api ? window.ZZZ.api.fetch(`/api/wanted.php?` + wParams.toString()) : fetch(`/api/wanted.php?` + wParams.toString()).then(r=>r.json()));
              if (wRes && wRes.success && wRes.data && wRes.data.wanted_ads) {
                  const wantedAds = wRes.data.wanted_ads.map(w => ({ ...w, _isWanted: true }));
                  let mixed = [];
                  let wIdx = 0;
                  for (let i = 0; i < filtered.length; i++) {
                      mixed.push(filtered[i]);
                      if ((i + 1) % 4 === 0 && wIdx < wantedAds.length) {
                          mixed.push(wantedAds[wIdx++]);
                      }
                  }
                  while (wIdx < wantedAds.length) { mixed.push(wantedAds[wIdx++]); }
                  filtered = mixed;
              }
            } catch(e) { console.error('Wanted fetch error', e); }

            populateTrending(filtered.filter(l => !l._isWanted));"""

content = fetch_pattern.sub(new_fetch, content)


# 3. Card map replacement
card_pattern = re.compile(r"const htmlCards = filtered\.map\(\(l, index\) => \{.*?\n            \}\)\.join\(''\);", re.DOTALL)

new_card_code = """const htmlCards = filtered.map((l, index) => {
              const timeDelay = index * 0.05; // Cascade animation
              
              if (l._isWanted) {
                  return `
                    <div class="card-3d bg-purple-50 dark:bg-purple-900/10 rounded-3xl overflow-hidden border-2 border-purple-200 dark:border-purple-800 cursor-pointer relative group opacity-0 animate-pop" style="animation-delay: ${timeDelay}s" onclick="window.location.href='Wanted Detail.html?id=${l.id}'">
                      <div class="p-6 h-full flex flex-col justify-between">
                        <div>
                          <div class="flex items-center justify-between mb-3">
                            <div class="bg-purple-200 dark:bg-purple-800 text-purple-800 dark:text-purple-200 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider flex items-center gap-1 shadow-sm border border-purple-300 dark:border-purple-600">
                                <span class="material-symbols-outlined text-[14px]">person_search</span> IN SEARCH
                            </div>
                            <span class="text-[10px] font-bold text-purple-500 uppercase">${escapeHtml(l.category || 'General')}</span>
                          </div>
                          <h3 class="text-lg font-black text-gray-900 dark:text-white truncate pr-4 mt-2">${escapeHtml(l.title)}</h3>
                          <p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-2 mt-2">${escapeHtml(l.description || '')}</p>
                        </div>
                        <div class="mt-4 pt-4 border-t border-purple-100 dark:border-purple-800/50">
                          <div class="text-[10px] font-bold text-purple-400 uppercase tracking-wide mb-1">Buyer Budget</div>
                          <div class="text-xl font-black text-purple-700 dark:text-purple-400 mb-2 tracking-tight">&#8377;${Number(l.budget_min).toLocaleString()} - &#8377;${Number(l.budget_max).toLocaleString()}</div>
                          <div class="flex items-center justify-between text-xs font-bold text-gray-400 pt-2">
                              <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">location_on</span> ${escapeHtml(l.location_city || 'Local')}</span>
                              <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">schedule</span> ${l.created_at ? new Date(l.created_at).toLocaleDateString('en-IN', { day:'numeric', month:'short' }) : 'Just now'}</span>
                          </div>
                        </div>
                      </div>
                    </div>
                  `;
              }
              
              const favs = window.ZZZ ? window.ZZZ.getFavoriteIds() : JSON.parse(localStorage.getItem('zzz_favorites') || '[]');
              const isFav = favs.includes(l.id);

              return `
                <div class="card-3d bg-white dark:bg-card-dark rounded-3xl overflow-hidden border border-gray-100 dark:border-gray-800 cursor-pointer relative group opacity-0 animate-pop" style="animation-delay: ${timeDelay}s" onclick="window.location.href='Listing Detail.html?id=${l.id}'">
                  
                  <!-- Image Area -->
                  <div class="relative h-56 overflow-hidden bg-gray-100 dark:bg-gray-900" 
                       onmouseenter="startHoverGallery(this, '${encodeURIComponent(JSON.stringify(l.images || []))}')"
                       onmouseleave="stopHoverGallery(this, '${l.images?.[0] || 'https://via.placeholder.com/400x300?text=No+Image'}')">
                    <img src="${l.images?.[0] || 'https://via.placeholder.com/400x300?text=No+Image'}" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110">
                    
                    <!-- Progress Dots -->
                    <div class="absolute bottom-2 left-0 right-0 flex justify-center gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity duration-300 gallery-dots"></div>
                    <!-- Category Badge -->
                    <div class="absolute top-4 left-4 bg-white/90 dark:bg-black/70 backdrop-blur-sm px-3 py-1 rounded-full text-[10px] font-bold text-gray-800 dark:text-white uppercase tracking-wider card-image-wrap">
                      ${escapeHtml(l.category || 'General')}
                    </div>
  
                    <!-- Boosted Badge -->
                    ${l.is_boosted_active ? `<div class="absolute top-4 left-1/2 -translate-x-1/2 bg-gradient-to-r from-yellow-400 to-orange-400 text-white text-[10px] font-black px-3 py-1 rounded-full shadow-lg flex items-center gap-1 animate-pulse card-image-wrap">Featured</div>` : ''}
  
                    <!-- Favorite Button -->
                    <button onclick="event.stopPropagation(); toggleFav('${l.id}', this)" class="fav-btn absolute top-4 right-4 w-10 h-10 rounded-full flex items-center justify-center card-image-wrap ${isFav ? 'active' : ''}">
                      <span class="material-symbols-outlined text-xl">favorite</span>
                    </button>
                  </div>
  
                  <!-- Details Area -->
                  <div class="p-6">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white truncate pr-4 flex items-center gap-1">
                      ${escapeHtml(l.title)}
                      ${l.seller_trusted ? '<span class="material-symbols-outlined text-yellow-500 text-[18px]" title="ZipZapZoi Trusted Seller">local_police</span>' : ''}
                    </h3>
                    <div class="text-2xl font-black text-primary my-2 tracking-tight">&#8377;${Number(l.price).toLocaleString()}</div>
                    
                    <p class="text-sm text-gray-500 dark:text-gray-400 line-clamp-2 mb-4">${escapeHtml(l.description || '')}</p>
                    
                    <div class="flex items-center gap-4 text-xs font-bold text-gray-400 dark:text-gray-500 pt-4 border-t border-gray-100 dark:border-gray-800">
                      <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">location_on</span> ${escapeHtml(l.location_city || l.location || 'Local')}</span>
                      <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[16px]">schedule</span> ${l.created_at ? new Date(l.created_at).toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric' }) : 'Just now'}</span>
                    </div>
                  </div>
  
                </div>
              `;
            }).join('');"""

content = card_pattern.sub(new_card_code, content)

with open(file_path, "w", encoding="utf-8") as f:
    f.write(content)

print("Done")
