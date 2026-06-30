import sys
import re

file_path = "D:\\zipzapzoi\\ZIpZapZoi Codes\\classifieds.html"
with open(file_path, "r", encoding="utf-8") as f:
    content = f.read()

# 1. Replace the WANTED BOARD CTA with the new ACTIVE BUYER DEMAND section
# Note: I need to replace from `<!-- WANTED BOARD CTA -->` up to `<!-- MAIN CONTENT -->`
banner_pattern = re.compile(r"<!-- WANTED BOARD CTA -->.*?(?=<!-- MAIN CONTENT -->)", re.DOTALL)

new_banner = """<!-- ACTIVE BUYER DEMAND SECTION -->
      <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6 mb-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-4">
          <h2 class="text-xl md:text-2xl font-black text-gray-900 dark:text-white flex items-center gap-2">
            <span class="material-symbols-outlined text-purple-600 animate-pulse text-3xl">campaign</span> 
            Active Buyer Demand
          </h2>
          <a href="wanted.html" class="bg-purple-600 text-white font-bold py-2 px-5 rounded-full shadow hover:bg-purple-700 hover:scale-105 transition-all flex items-center gap-2 text-sm whitespace-nowrap self-start sm:self-auto">
            <span class="material-symbols-outlined text-[18px]">post_add</span> Post Request
          </a>
        </div>
        <div class="relative w-full overflow-hidden">
          <div id="buyerDemandContainer" class="flex gap-4 overflow-x-auto pb-4 snap-x hide-scrollbar" style="scroll-behavior: smooth;">
            <!-- Populated by JS -->
          </div>
        </div>
      </section>

      """
content = banner_pattern.sub(new_banner, content)

# 2. Remove Wanted Injection from renderGrid
injection_pattern = re.compile(r"// Inject Wanted Ads\s+try \{.*?\}\s*catch\(e\) \{.*?\}\s*populateTrending\(filtered\.filter\(l => !l\._isWanted\)\);", re.DOTALL)
new_injection = "populateTrending(filtered);"
content = injection_pattern.sub(new_injection, content)

# 3. Redesign fetchWantedAds() to populate the new horizontal section instead of the marquee line.
fetch_wanted_pattern = re.compile(r"// Populate Demand Marquee.*?async function fetchWantedAds\(\) \{.*?\n        \}", re.DOTALL)

new_fetch_wanted = """// Populate Active Buyer Demand Horizontal Section
      async function fetchWantedAds() {
        const container = document.getElementById('buyerDemandContainer');
        if (!container) return;
        
        try {
          const cat = activeCategory === 'All' ? '' : activeCategory;
          const res = await (window.ZZZ && window.ZZZ.api ? window.ZZZ.api.fetch(`/api/wanted.php?category=${encodeURIComponent(cat)}`) : fetch(`/api/wanted.php?category=${encodeURIComponent(cat)}`).then(r => r.json()));
          
          const ads = res.data && res.data.wanted_ads ? res.data.wanted_ads : [];
          
          if (ads.length === 0) {
            container.parentElement.parentElement.style.display = 'none';
            return;
          }
          
          container.parentElement.parentElement.style.display = 'block';
          
          container.innerHTML = ads.map((l, index) => {
            const timeDelay = index * 0.05;
            return `
              <div class="card-3d shrink-0 w-[280px] md:w-[320px] snap-start bg-gradient-to-br from-purple-50 to-indigo-50 dark:from-purple-900/20 dark:to-indigo-900/20 rounded-3xl overflow-hidden border border-purple-100 dark:border-purple-800/50 cursor-pointer relative group opacity-0 animate-pop flex flex-col justify-between" style="animation-delay: ${timeDelay}s; min-height: 200px;" onclick="window.location.href='Wanted Detail.html?id=${l.id}'">
                <div class="p-5 h-full flex flex-col justify-between">
                  <div>
                    <div class="flex items-center justify-between mb-3">
                      <div class="bg-purple-200 dark:bg-purple-800 text-purple-800 dark:text-purple-200 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-wider flex items-center gap-1 shadow-sm border border-purple-300 dark:border-purple-600">
                          <span class="material-symbols-outlined text-[14px]">person_search</span> IN SEARCH
                      </div>
                      <span class="text-[10px] font-bold text-purple-500 uppercase truncate max-w-[100px]">${escapeHtml(l.category || 'General')}</span>
                    </div>
                    <h3 class="text-base font-black text-gray-900 dark:text-white line-clamp-2 mt-2 leading-tight">${escapeHtml(l.title)}</h3>
                  </div>
                  <div class="mt-4 pt-4 border-t border-purple-200 dark:border-purple-800/50">
                    <div class="text-[10px] font-bold text-purple-500 uppercase tracking-wide mb-1">Buyer Budget</div>
                    <div class="text-lg font-black text-purple-700 dark:text-purple-400 mb-2 tracking-tight">&#8377;${Number(l.budget_min).toLocaleString()} - &#8377;${Number(l.budget_max).toLocaleString()}</div>
                    <div class="flex items-center justify-between text-[11px] font-bold text-gray-400 pt-1">
                        <span class="flex items-center gap-1 truncate max-w-[120px]"><span class="material-symbols-outlined text-[14px]">location_on</span> ${escapeHtml(l.location_city || 'Local')}</span>
                        <span class="flex items-center gap-1 shrink-0"><span class="material-symbols-outlined text-[14px]">schedule</span> ${l.created_at ? new Date(l.created_at).toLocaleDateString('en-IN', { day:'numeric', month:'short' }) : 'Just now'}</span>
                    </div>
                  </div>
                </div>
              </div>
            `;
          }).join('');
        } catch(e) {
          console.error('Error fetching wanted ads', e);
          container.parentElement.parentElement.style.display = 'none';
        }
      }"""

content = fetch_wanted_pattern.sub(new_fetch_wanted, content)

with open(file_path, "w", encoding="utf-8") as f:
    f.write(content)

print("Done")
