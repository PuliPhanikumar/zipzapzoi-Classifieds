
      let currentListing = null;
      let isFavorite     = false;
      // Gallery globals — must be accessible by openLightbox, pgNav, etc.
      let pgImages       = [];
      let pgCurrentIndex = 0;

      document.addEventListener('DOMContentLoaded', async () => {
        if (document.documentElement.classList.contains('dark')) document.getElementById('themeIcon').textContent = 'light_mode';

        const params = new URLSearchParams(window.location.search);
        const id = params.get('id');
        if (!id) { showToast('Listing not found', 'error'); window.location.href = 'classifieds.html'; return; }

        try {
          const res  = await fetch('/api/listings.php?id=' + id, { credentials: 'include' });
          const data = await res.json();

          if (!data.success) {
            showToast(data.error || 'Listing not found', 'error');
            window.location.href = 'classifieds.html';
            return;
          }

          currentListing = data.data;

          const viewer     = JSON.parse(localStorage.getItem('zzz_user') || 'null');
          const isAdminPreview = new URLSearchParams(window.location.search).get('admin_preview') === '1' || localStorage.getItem('adminToken') !== null;
          const canInspect = isAdminPreview || (viewer && (viewer.id === currentListing.user_id || viewer.role === 'admin' || viewer.role === 'super_admin'));
          
          if (currentListing.status !== 'active' && !canInspect) {
            alert('This listing is not currently active.');
            window.location.href = 'classifieds.html';
            return;
          }

          renderData();
          fetchSellerRating();

          // Non-blocking favourite state (only for logged-in users)
          if (viewer) {
            fetch('/api/favorites.php', { credentials: 'include' })
              .then(r => r.json())
              .then(d => {
                if (d.success) {
                  isFavorite = (d.data || []).some(f => f.id === currentListing.id);
                  updateFavIcon();
                }
              }).catch(() => {});
          }
        } catch(e) {
          console.error('[ZZZ] Listing load error:', e);
          alert('Error loading listing: ' + e.message);
          showToast('Failed to load listing. Please refresh or check your connection.', 'error');
        }
      });

      // ─── RENDER ──────────────────────────────────────────────────────────
      function renderData() {
        const l = currentListing;
        document.title = l.title + ' - ZipZapZoi';
        // Dynamic SEO & OpenGraph
        const siteUrl = "https://www.zipzapzoi.com";
        const metaTitle = document.querySelector('meta[property="og:title"]');
        const metaDesc = document.querySelector('meta[property="og:description"]');
        const metaImage = document.querySelector('meta[property="og:image"]');
        const metaUrl = document.querySelector('meta[property="og:url"]');
        const canonical = document.querySelector('link[rel="canonical"]');
        
        if (metaTitle) metaTitle.content = l.title + ' | ZipZapZoi';
        if (metaDesc) metaDesc.content = (l.description || '').substring(0, 150) + '...';
        if (metaImage && l.images && l.images.length > 0) metaImage.content = l.images[0].startsWith('http') ? l.images[0] : siteUrl + '/' + l.images[0];
        if (metaUrl) metaUrl.content = siteUrl + '/Listing%20Detail.html?id=' + l.id;
        if (canonical) canonical.href = siteUrl + '/Listing%20Detail.html?id=' + l.id;

        // Structured Data JSON-LD
        let jsonLdScript = document.getElementById('json-ld');
        if (!jsonLdScript) {
            jsonLdScript = document.createElement('script');
            jsonLdScript.id = 'json-ld';
            jsonLdScript.type = 'application/ld+json';
            document.head.appendChild(jsonLdScript);
        }
        jsonLdScript.textContent = JSON.stringify({
            "@context": "https://schema.org/",
            "@type": "Product",
            "name": l.title,
            "image": (l.images && l.images.length > 0) ? l.images : [siteUrl + "/images/default-share.png"],
            "description": l.description,
            "offers": {
                "@type": "Offer",
                "priceCurrency": "INR",
                "price": parseFloat(l.price || 0),
                "itemCondition": "https://schema.org/UsedCondition",
                "availability": "https://schema.org/InStock"
            }
        });

        // 🌟 Boosted/Promo badges
        const boostBadgeEl = document.getElementById('detailBoostBadge');
        if (boostBadgeEl) {
          let badgeHtml = '';
          if (l.is_urgent == 1) badgeHtml += '<span class="ml-2 text-xs font-black bg-gradient-to-r from-red-500 to-orange-600 text-white px-3 py-1 rounded-full uppercase tracking-wider shadow-md">🔥 Urgent</span>';
          if (l.is_top == 1) badgeHtml += '<span class="ml-2 text-xs font-black bg-gradient-to-r from-blue-500 to-indigo-600 text-white px-3 py-1 rounded-full uppercase tracking-wider shadow-md">🚀 Top Ad</span>';
          if (l.is_highlight == 1) badgeHtml += '<span class="ml-2 text-xs font-black bg-gradient-to-r from-yellow-400 to-orange-400 text-white px-3 py-1 rounded-full uppercase tracking-wider shadow-md">⭐ Highlighted</span>';
          
          if (badgeHtml !== '') {
            boostBadgeEl.outerHTML = badgeHtml;
          } else {
            const isBoosted = l.boosted == 1 && l.boosted_until && new Date(l.boosted_until) > new Date();
            boostBadgeEl.style.display = isBoosted ? 'inline-flex' : 'none';
          }
        }

        document.getElementById('detailTitle').textContent    = l.title || '';
        document.getElementById('detailCategory').textContent = l.subcategory || l.category || '';
        document.getElementById('detailPrice').innerHTML    =
          l.price_type === 'free' ? 'FREE' : '&#8377;' + parseFloat(l.price || 0).toLocaleString('en-IN');

          boostBadgeEl.style.display = isBoosted ? 'inline-flex' : 'none';
        }

        const locStr = [l.location_city, l.location_state].filter(Boolean).join(', ') || 'Local';
        document.getElementById('detailLocation').textContent = locStr;
        document.getElementById('detailTime').textContent     = timeAgo(l.created_at);
        
        // Gamified Views & Urgency Badge
        const viewsCount = Math.max(l.views || 1, Math.floor(Math.random() * 20) + 5);
        document.getElementById('detailViews').textContent    = viewsCount.toLocaleString('en-IN');
        
        const urgencyBadge = document.getElementById('urgencyBadge');
        if (urgencyBadge) {
          if (viewsCount > 15) {
            urgencyBadge.classList.remove('hidden');
            urgencyBadge.innerHTML = `🔥 ${viewsCount} people viewed this`;
          } else {
            urgencyBadge.classList.add('hidden');
          }
        }
        
        document.getElementById('detailDescription').textContent = l.description || '';


        // Seller
        document.getElementById('sellerName').textContent  = l.seller_name || 'Anonymous';
        document.getElementById('sellerInit').textContent  = (l.seller_name || 'A').charAt(0).toUpperCase();
        const vBadge = document.getElementById('sellerVerifiedBadge');
        if (l.seller_is_verified) { vBadge.classList.remove('hidden'); vBadge.classList.add('flex'); }
        else { vBadge.classList.add('hidden'); }
        
        const tBadge = document.getElementById('sellerTrustedBadge');
        if (l.seller_trusted) { tBadge.classList.remove('hidden'); tBadge.classList.add('flex'); }
        else { tBadge.classList.add('hidden'); }
        
        // Seller Responsiveness (Simulated/Calculated)
        const respBadge = document.getElementById('sellerResponsivenessBadge');
        const respText = document.getElementById('sellerResponseText');
        const respTimes = ['Usually replies within 15 mins', 'Usually replies within 1 hour', 'Active today'];
        // Use listing ID to deterministically seed the response time so it stays consistent per listing
        const respIndex = (l.id || 0) % respTimes.length;
        respText.textContent = respTimes[respIndex];
        respBadge.classList.remove('hidden');

        // Scarcity Banner Logic
        // Use total views + deterministic noise based on current hour to simulate 'active viewers'
        const currentHour = new Date().getHours();
        const deterministicActiveViewers = ((l.id || 1) + currentHour) % 7 + 2; // Returns 2 to 8 viewers
        if (l.views > 10 || deterministicActiveViewers > 3) {
            const scBanner = document.getElementById('scarcityBanner');
            const scText = document.getElementById('scarcityText');
            const viewerCount = l.views > 50 ? Math.floor(l.views / 10) : deterministicActiveViewers;
            scText.textContent = `👀 ${viewerCount} other people are viewing this right now.`;
            scBanner.classList.remove('hidden');
        }


        // Condition badge
        if (l.condition) document.getElementById('conditionBadge').textContent = l.condition.toUpperCase();
        else             document.getElementById('conditionBadge').style.display = 'none';

        // Check if expired
        const isExpired = l.expires_at && new Date(l.expires_at) < new Date();
        if (isExpired) {
          const badge = document.createElement('p');
          badge.className = 'text-xs font-bold bg-red-100 text-red-600 px-2 py-1 rounded inline-block ml-2';
          badge.textContent = 'EXPIRED';
          document.getElementById('conditionBadge').parentNode.appendChild(badge);
          
          const actions = document.getElementById('actionButtonsContainer');
          if (actions) {
            actions.innerHTML = '<div class="p-4 bg-red-50 border border-red-200 text-red-600 rounded-xl text-center font-bold">This listing has expired and is no longer available.</div>';
          }
        }

        // 📞 Dynamic Contact Buttons
        const actionsContainer = document.getElementById('actionButtonsContainer');
        if (actionsContainer && !isExpired) {
            let btnsHtml = `
                <button onclick="initiateChat()" class="w-full mb-3 bg-gray-900 dark:bg-gray-100 hover:bg-primary dark:hover:bg-primary text-white dark:text-gray-900 font-black py-4 rounded-2xl transition-all duration-300 hover:scale-[1.02] active:scale-95 hover:shadow-xl flex items-center justify-center gap-2 text-lg">
                  <span class="material-symbols-outlined text-[24px]">chat</span> Chat with Seller
                </button>
            `;
            
            if (l.contact_phone && l.hide_phone != 1) {
                btnsHtml += `
                <a href="tel:${l.contact_phone}" class="w-full mb-3 border-2 border-primary text-primary hover:bg-primary hover:text-white font-black py-4 rounded-2xl transition-all duration-300 hover:scale-[1.02] active:scale-95 hover:shadow-xl flex items-center justify-center gap-2 text-lg">
                  <span class="material-symbols-outlined text-[24px]">call</span> ${l.contact_phone}
                </a>`;
            }
            
            btnsHtml += `
                <button onclick="leaveReview()" class="w-full mb-4 border-2 border-gray-900 dark:border-gray-100 hover:border-primary dark:hover:border-primary text-gray-900 dark:text-gray-100 hover:text-primary dark:hover:text-primary font-black py-4 rounded-2xl transition-all duration-300 flex items-center justify-center gap-2 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                  <span class="material-symbols-outlined text-[20px]">star_rate</span> Leave a Review
                </button>
                <div class="flex gap-3 mb-3">
            `;
            
            if (l.allow_whatsapp == 1 && l.contact_phone) {
                const waNumber = l.contact_phone.replace(/[^0-9]/g, '');
                btnsHtml += `
                  <a href="https://wa.me/${waNumber}?text=Hi,%20I%20am%20interested%20in%20your%20listing:%20${encodeURIComponent(l.title)}" target="_blank" class="flex-1 py-3.5 rounded-xl bg-[#25D366] hover:bg-[#1ebd5a] text-white font-bold transition-all duration-300 hover:-translate-y-1 hover:shadow-lg flex items-center justify-center gap-2 text-sm">
                    <svg class="w-5 h-5 fill-current" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a12.8 12.8 0 0 0-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413z"/></svg>
                    WhatsApp
                  </a>
                `;
            }
            
            btnsHtml += `
                  <button onclick="shareListing()" class="flex-1 py-3.5 rounded-xl border-2 border-gray-200 dark:border-gray-700 bg-transparent hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold transition-all duration-300 hover:-translate-y-1 hover:shadow-md flex items-center justify-center gap-2 text-sm">
                    <span class="material-symbols-outlined text-[18px]">content_copy</span> Copy Link
                  </button>
                </div>
                <button onclick="generateSharePoster()" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-purple-500 to-indigo-600 hover:from-purple-600 hover:to-indigo-700 text-white font-bold transition-all duration-300 hover:-translate-y-1 hover:shadow-lg flex items-center justify-center gap-2 text-sm">
                  <span class="material-symbols-outlined text-[18px]">wallpaper</span> Download Share Poster
                </button>
            `;
            actionsContainer.innerHTML = btnsHtml;
        }

        // Dynamic fields
        const fields = l.fields || {};
        const fieldKeys = Object.keys(fields).filter(k => fields[k]);
        if (fieldKeys.length > 0) {
          document.getElementById('attributesGrid').innerHTML = fieldKeys.map(key => {
            const label = escapeHtml(key.replace(/_/g,' ').replace(/\\b\\w/g, c=>c.toUpperCase()));
            const val = escapeHtml(String(fields[key]));
            return `<div class="bg-gray-50 dark:bg-gray-800 p-4 rounded-xl border border-gray-100 dark:border-gray-700">
              <p class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">${label}</p>
              <p class="font-bold text-gray-900 dark:text-white text-sm truncate" title="${val}">${val}</p>
            </div>`;
          }).join('');
          document.getElementById('attributesSection').style.display = 'block';
        }

        // ── VANILLA GALLERY ─────────────────────────────────────────────
        pgImages = Array.isArray(l.images) ? l.images : [];
        pgCurrentIndex = 0;

        function pgSetImage(idx, dir) {
          if (!pgImages.length) return;
          pgCurrentIndex = (idx + pgImages.length) % pgImages.length;
          const src = pgImages[pgCurrentIndex];
          const mainImg = document.getElementById('pgMainImg');
          const blur    = document.getElementById('pgBlur');
          const counter = document.getElementById('pgCounter');
          // 3D bounce animation based on direction
          mainImg.classList.remove('anim-left', 'anim-right', 'fade-in');
          void mainImg.offsetWidth; // force reflow
          mainImg.src = src;
          if (dir === 1)       mainImg.classList.add('anim-right');
          else if (dir === -1) mainImg.classList.add('anim-left');
          else                 mainImg.classList.add('fade-in');
          blur.style.backgroundImage = `url('${src}')`;
          if (counter) counter.textContent = `${pgCurrentIndex + 1} / ${pgImages.length}`;
          // Update thumbnails active state
          document.querySelectorAll('.pg-thumb').forEach((t, i) => {
            t.classList.toggle('active', i === pgCurrentIndex);
          });
            // Scroll active thumb safely without scrolling the whole page
            const activeThumb = document.querySelectorAll('.pg-thumb')[pgCurrentIndex];
            const pgThumbs = document.getElementById('pgThumbs');
            if (activeThumb && pgThumbs) {
              const scrollLeft = activeThumb.offsetLeft - (pgThumbs.clientWidth / 2) + (activeThumb.clientWidth / 2);
              pgThumbs.scrollTo({ left: scrollLeft, behavior: 'smooth' });
            }
        }

        window.pgNav = function(dir) { pgSetImage(pgCurrentIndex + dir, dir); };

        // Swipe gesture support
        const pgMain = document.getElementById('pgMain');
        let pgTouchStartX = 0;
        pgMain.addEventListener('touchstart', e => { pgTouchStartX = e.changedTouches[0].clientX; }, { passive: true });
        pgMain.addEventListener('touchend', e => {
          const diff = pgTouchStartX - e.changedTouches[0].clientX;
          if (Math.abs(diff) > 40) pgNav(diff > 0 ? 1 : -1);
        }, { passive: true });

        // Render gallery
        if (l.is_story == 1 && l.video_url) {
            document.getElementById('pgMainImg').style.display = 'none';
            document.getElementById('pgThumbs').style.display = 'none';
            document.querySelector('.pg-prev').style.display = 'none';
            document.querySelector('.pg-next').style.display = 'none';
            document.getElementById('pgCounter').style.display = 'none';
            
            const videoEl = document.getElementById('pgMainVideo');
            videoEl.style.display = 'block';
            videoEl.src = escapeHtml(l.video_url);
            
            // Adjust container for portrait video typical of stories
            document.getElementById('pgWrap').style.aspectRatio = '9/16';
            document.getElementById('pgWrap').style.maxHeight = '80vh';
        } else if (pgImages.length > 0) {
          if (pgImages.length === 1) document.getElementById('pgWrap').classList.add('single');
          // Build thumbnails
          const thumbsEl = document.getElementById('pgThumbs');
          thumbsEl.innerHTML = pgImages.map((src, i) =>
            `<div class="pg-thumb${i===0?' active':''}" onclick="pgSetImage(${i})"><img src="${src}" loading="lazy" alt="Photo ${i+1}"></div>`
          ).join('');
          // Show first image
          pgSetImage(0);
          // Auto-advance (every 5s)
          if (pgImages.length > 1) setInterval(() => pgNav(1), 5000);
        } else {
          document.getElementById('pgMainImg').src = 'https://placehold.co/800x450/1e293b/6b7280?text=No+Image+Available';
          document.getElementById('pgWrap').classList.add('single');
        }


        // Map
        if (typeof L !== 'undefined') {
          if (typeof initListingMap === 'function') {
            initListingMap(l.lat, l.lng, l.title);
          } else {
            // Define it safely inline if it was lost from utils.js
            const mapEl = document.getElementById('listingMap');
            if (mapEl && l.lat && l.lng) {
              mapEl.style.height = '200px';
              mapEl.style.borderRadius = '12px';
              // Check if map already initialized
              if (!mapEl._leaflet_id) {
                const map = L.map('listingMap').setView([l.lat, l.lng], 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);
                L.marker([l.lat, l.lng]).addTo(map).bindPopup(escapeHtml(l.title || 'Location'));
              }
            } else if (mapEl) {
               mapEl.style.display = 'none';
               const mapParent = mapEl.closest('div.bg-white');
               if (mapParent && mapParent.querySelector('h2') && mapParent.querySelector('h2').textContent.includes('Location')) {
                   mapParent.style.display = 'none';
               }
            }
          }
        }

        // Similar Ads
        if (l.similar && l.similar.length > 0) {
          document.getElementById('similarAdsSection').style.display = 'block';
          document.getElementById('similarAdsGrid').innerHTML = l.similar.map(s => {
            const img = s.thumbnail || 'https://placehold.co/400x300/f0f4f8/9ca3af?text=No+Image';
            return `<a href="Listing Detail.html?id=${s.id}" class="block group bg-white dark:bg-card-dark rounded-2xl border border-gray-100 dark:border-gray-800 overflow-hidden hover:shadow-lg transition-all">
              <div class="aspect-video overflow-hidden bg-gray-100 dark:bg-gray-800">
                <img src="${img}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-500">
              </div>
              <div class="p-4">
                <h3 class="font-bold text-gray-900 dark:text-white truncate mb-1">${escapeHtml(s.title)}</h3>
                <p class="text-primary font-black">&#8377;${parseFloat(s.price||0).toLocaleString('en-IN')}</p>
                <p class="text-xs text-gray-400 mt-2 flex items-center gap-1"><span class="material-symbols-outlined text-[14px]">location_on</span> ${escapeHtml(s.location_city || 'Local')}</p>
              </div>
            </a>`;
          }).join('');
        }

        // Add to Recent Views
        let recents = JSON.parse(localStorage.getItem('zzz_recents') || '[]');
        recents = recents.filter(x => String(x.id) !== String(l.id));
        recents.unshift({
          id: l.id, title: l.title, price: l.price,
          img: pgImages[0] || 'https://placehold.co/400x300/f0f4f8/9ca3af?text=No+Image',
          city: l.location_city
        });
        if (recents.length > 6) recents.pop();
        localStorage.setItem('zzz_recents', JSON.stringify(recents));

        // Fade in
        document.getElementById('contentArea').style.opacity = '1';
      }

      // Lightbox - works with our vanilla gallery's pgImages
      let lbCurrent = 0;
      function openLightbox(idx) {
        if (!pgImages || !pgImages.length) return;
        lbCurrent = (idx || 0);
        document.getElementById('lightboxImg').src = pgImages[lbCurrent];
        document.getElementById('lbCounter').textContent = (lbCurrent + 1) + ' / ' + pgImages.length;
        document.getElementById('lightbox').classList.add('open');
      }
      function closeLightbox() { document.getElementById('lightbox').classList.remove('open'); }
      function lbStep(dir) {
        if (!pgImages || !pgImages.length) return;
        lbCurrent = (lbCurrent + dir + pgImages.length) % pgImages.length;
        document.getElementById('lightboxImg').src = pgImages[lbCurrent];
        document.getElementById('lbCounter').textContent = (lbCurrent + 1) + ' / ' + pgImages.length;
      }
      document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft') lbStep(-1);
        if (e.key === 'ArrowRight') lbStep(1);
      });

      // ─── FAVOURITES ──────────────────────────────────────────────────
      function updateFavIcon() {
        const btn = document.getElementById('favBtnHeader');
        if (!btn) return;
        if (isFavorite) {
          btn.classList.add('text-red-500'); btn.classList.remove('text-gray-600','dark:text-gray-400');
          btn.querySelector('span').style.fontVariationSettings = "'FILL' 1";
        } else {
          btn.classList.remove('text-red-500'); btn.classList.add('text-gray-600','dark:text-gray-400');
          btn.querySelector('span').style.fontVariationSettings = "'FILL' 0";
        }
      }

      async function toggleFav() {
        const user = JSON.parse(localStorage.getItem('zzz_user') || 'null');
        if (!user) {
          window.location.href = 'Login Page.html?redirect=' + encodeURIComponent('Listing Detail.html?id=' + currentListing.id);
          return;
        }
        try {
          const res  = await fetch('/api/favorites.php?action=toggle', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
            body: JSON.stringify({ listing_id: currentListing.id }),
          });
          const data = await res.json();
          if (data.success) {
            isFavorite = data.data.is_favorited;
            updateFavIcon();
            showToast(isFavorite ? '❤️ Saved to favourites' : 'Removed from favourites', 'success');
          }
        } catch { showToast('Network error.', 'error'); }
      }

      // ─── FETCH SELLER RATING ────────────────────────────────────────────
      async function fetchSellerRating() {
        if (!currentListing || !currentListing.user_id) return;
        try {
          const res = await fetch('/api/reviews.php?seller_id=' + currentListing.user_id);
          const data = await res.json();
          if (data.success && data.data && data.data.stats) {
            const stats = data.data.stats;
            const container = document.getElementById('sellerRatingContainer');
            const textEl = document.getElementById('sellerRatingText');
            if (stats.total > 0 && container && textEl) {
              textEl.textContent = `${stats.average.toFixed(1)} (${stats.total})`;
              container.classList.remove('hidden');
            }
          }
        } catch (e) {
          console.error('[ZZZ] Failed to fetch seller rating:', e);
        }
      }

      // ─── SHARE ──────────────────────────────────────────────────────────
      function shareListing() {
        const shareUrl = window.location.origin + `/share.php?id=${currentListing.id}`;
        navigator.clipboard.writeText(shareUrl).then(() => showToast('Link copied to clipboard! (Rich preview enabled)', 'success'));
      }

      // ─── REPORT LISTING ─────────────────────────────────────────────────
      function reportListing() {
        const user = JSON.parse(localStorage.getItem('zzz_user') || 'null');
        if (!user) {
          window.location.href = 'Login Page.html?redirect=' + encodeURIComponent('Listing Detail.html?id=' + currentListing.id);
          return;
        }
        if (String(user.id) === String(currentListing.user_id)) {
          showToast('You cannot report your own listing.', 'error');
          return;
        }
        document.getElementById('reportListingTitle').textContent = currentListing.title || 'this listing';
        document.getElementById('reportReasonSelect').value = '';
        document.getElementById('reportModal').classList.remove('hidden');
      }
      function closeReportModal() {
        document.getElementById('reportModal').classList.add('hidden');
      }
      async function submitReport() {
        const reason = document.getElementById('reportReasonSelect').value;
        if (!reason) { showToast('Please select a reason.', 'error'); return; }
        const btn = document.getElementById('reportSubmitBtn');
        btn.disabled = true;
        btn.textContent = 'Submitting…';
        try {
          const res  = await fetch('/api/reports.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ listing_id: currentListing.id, reason })
          });
          const data = await res.json();
          if (data.success) {
            closeReportModal();
            showToast('✅ Report submitted. Our team will review within 24 hours.', 'success');
          } else {
            showToast(data.error || 'Could not submit report.', 'error');
          }
        } catch(e) {
          showToast('Network error. Try again.', 'error');
        }
        btn.disabled = false;
        btn.textContent = 'Submit Report';
      }

      // ─── THEME ────────────────────────────────────────────────────────
      function toggleDarkMode() {
        const dark = document.documentElement.classList.toggle('dark');
        localStorage.setItem('zzz_theme', dark ? 'dark' : 'light');
        document.getElementById('themeIcon').textContent = dark ? 'light_mode' : 'dark_mode';
      }

      // ─── CHAT ──────────────────────────────────────────────────────────
      function initiateChat() {
        const user = JSON.parse(localStorage.getItem('zzz_user') || 'null');
        if (!user) { window.location.href = 'Login Page.html?redirect=' + encodeURIComponent('Listing Detail.html?id=' + currentListing.id); return; }
        if (String(user.id) === String(currentListing.user_id)) { showToast('You cannot chat with yourself!', 'error'); return; }
        openFloatingChat(currentListing.user_id, currentListing.seller_name || 'Seller');
      }

      // ─── OFFER ────────────────────────────────────────────────────────
      function makeOffer() {
        const user = JSON.parse(localStorage.getItem('zzz_user') || 'null');
        if (!user) { window.location.href = 'Login Page.html?redirect=' + encodeURIComponent('Listing Detail.html?id=' + currentListing.id); return; }
        if (String(user.id) === String(currentListing.user_id)) { showToast('You cannot offer on your own listing!', 'error'); return; }
        document.getElementById('modalListingTitle').textContent = currentListing.title;
        document.getElementById('modalAskingPrice').textContent  = '&#8377;' + parseFloat(currentListing.price||0).toLocaleString('en-IN');
        document.getElementById('offerAmountInput').value = '';
        document.getElementById('offerModal').classList.remove('hidden');
      }
      function closeOfferModal() { document.getElementById('offerModal').classList.add('hidden'); }
      function submitOffer() {
        const offer = document.getElementById('offerAmountInput').value.trim();
        if (!offer || isNaN(offer) || parseFloat(offer) <= 0) { showToast('Enter a valid offer amount.', 'error'); return; }
        closeOfferModal();
        openFloatingChat(currentListing.user_id, currentListing.seller_name || 'Seller');
        sendFloatingMessage('text', `Hi! I’d like to offer Rs. ${Number(offer).toLocaleString('en-IN')} for "${currentListing.title}". Is this acceptable?`);
      }

      // ─── FLOATING CHAT WIDGET ──────────────────────────────────────────
      let chatWidgetPartnerId   = null;
      let chatWidgetPartnerName = null;
      let chatPollTimer         = null;

      function openFloatingChat(partnerId, partnerName) {
        chatWidgetPartnerId   = partnerId;
        chatWidgetPartnerName = partnerName;
        document.getElementById('chatWidgetName').textContent   = partnerName;
        document.getElementById('chatWidgetAvatar').textContent = partnerName.charAt(0).toUpperCase();
        document.getElementById('floatingChatWidget').classList.remove('translate-y-[120%]');
        loadFloatingMessages();
        clearInterval(chatPollTimer);
        chatPollTimer = setInterval(loadFloatingMessages, 15000);
      }

      function closeFloatingChat() {
        clearInterval(chatPollTimer);
        document.getElementById('floatingChatWidget').classList.add('translate-y-[120%]');
      }

      async function loadFloatingMessages() {
        if (!chatWidgetPartnerId || !currentListing) return;
        const user = JSON.parse(localStorage.getItem('zzz_user') || 'null');
        if (!user) return;
        try {
          const p = new URLSearchParams({ thread: chatWidgetPartnerId, listing_id: currentListing.id });
          const res  = await fetch('/api/messages.php?' + p, { credentials: 'include' });
          const data = await res.json();
          if (data.success) renderFloatingMessages(data.data || []);
        } catch(e) {}
      }

      function renderFloatingMessages(msgs) {
        const user = JSON.parse(localStorage.getItem('zzz_user') || 'null');
        if (!user) return;
        const feed = document.getElementById('chatWidgetFeed');
        if (!msgs || msgs.length === 0) {
          feed.innerHTML = `<div class="text-center text-gray-500 text-xs mt-4">Say Hi to ${chatWidgetPartnerName}! 👋</div>`;
          return;
        }
        const san = s => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
        feed.innerHTML = msgs.map(m => {
          const isMe = String(m.from_user_id) === String(user.id);
          const side = isMe ? 'bg-bubble-me dark:bg-primary text-gray-800 dark:text-white rounded-tr-none' : 'bg-white dark:bg-card-dark text-gray-800 dark:text-white rounded-tl-none';
          const time = new Date(m.created_at).toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
          return `<div class="flex w-full ${isMe?'justify-end':'justify-start'}">
            <div class="max-w-[85%] px-3 py-2 rounded-xl shadow-sm text-sm ${side}">
              ${san(m.body || '')}
              <div class="text-[9px] opacity-70 text-right mt-1">${time}</div>
            </div>
          </div>`;
        }).join('');
        feed.scrollTop = feed.scrollHeight;
      }

      async function sendFloatingMessage(type, overrideText) {
        const user = JSON.parse(localStorage.getItem('zzz_user') || 'null');
        if (!user || !chatWidgetPartnerId) return;
        const inputEl = document.getElementById('chatWidgetInput');
        const txt = overrideText != null ? overrideText : (inputEl ? inputEl.value.trim() : '');
        if (!txt) return;
        if (inputEl) inputEl.value = '';

        // Optimistic bubble
        const feed = document.getElementById('chatWidgetFeed');
        const div  = document.createElement('div');
        div.className = 'flex w-full justify-end';
        const san = s => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
        div.innerHTML = `<div class="max-w-[85%] px-3 py-2 rounded-xl shadow-sm text-sm bg-bubble-me dark:bg-primary text-gray-800 dark:text-white rounded-tr-none">${san(txt)}<div class="text-[9px] opacity-70 text-right mt-1">Sending…</div></div>`;
        feed.appendChild(div);
        feed.scrollTop = feed.scrollHeight;

        try {
          const res  = await fetch('/api/messages.php', {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include',
            body: JSON.stringify({ to_user_id: chatWidgetPartnerId, listing_id: currentListing.id, body: txt, subject: 'Enquiry' }),
          });
          const data = await res.json();
          if (data.success) await loadFloatingMessages();
          else showToast(data.error || 'Send failed.', 'error');
        } catch { showToast('Network error.', 'error'); }
      }

      function openInInbox() {
        const user = JSON.parse(localStorage.getItem('zzz_user') || 'null');
        if (!user) { window.location.href = 'Login Page.html'; return; }
        localStorage.setItem('zzz_chat_intent', JSON.stringify({
          partnerId: chatWidgetPartnerId, partnerName: chatWidgetPartnerName,
          listingId: currentListing ? currentListing.id : null,
          listingTitle: currentListing ? currentListing.title : 'Listing'
        }));
        window.location.href = 'Inbox.html';
      }

      // ─── WHATSAPP SHARE ──────────────────────────────────────────────
      function shareToWhatsApp() {
        if (!currentListing) return;
        const title = currentListing.title || 'this listing';
        const price = currentListing.price ? `for Rs. ${Number(currentListing.price).toLocaleString('en-IN')}` : '';
        const text = `Check out ${title} ${price} on ZipZapZoi!\n\n`;
        const url = window.location.origin + `/share.php?id=${currentListing.id}`;
        window.open(`https://wa.me/?text=${encodeURIComponent(text + url)}`, '_blank');
      }
      
      // ─── DOWNLOAD SHARE POSTER ───────────────────────────────────────
      async function generateSharePoster() {
        if (!currentListing) return;
        
        const btn = document.querySelector('button[onclick="generateSharePoster()"]');
        const oldContent = btn.innerHTML;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">hourglass_empty</span> Generating Poster...';
        
        try {
          const canvas = document.createElement('canvas');
          const ctx = canvas.getContext('2d');
          canvas.width = 1080;
          canvas.height = 1080;
          
          // 1. Dark Premium Background
          ctx.fillStyle = '#0f172a'; // Tailwind slate-900
          ctx.fillRect(0, 0, 1080, 1080);
          
          // 2. Draw Main Image (Top 75%)
          const imgSrc = (currentListing.images && currentListing.images.length > 0) ? currentListing.images[0] : 'https://placehold.co/1080x810/1e293b/ffffff.png?text=ZipZapZoi';
            
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
            
            const img = await loadCanvasImage(imgSrc);
          
          // Cover crop exactly into 1080x810 area
          if (img) {
              const targetW = 1080;
          const targetH = 810;
          const scale = Math.max(targetW / img.width, targetH / img.height);
          const drawW = img.width * scale;
          const drawH = img.height * scale;
          const drawX = (targetW - drawW) / 2;
          const drawY = (targetH - drawH) / 2;
          
          ctx.save();
          ctx.beginPath();
          ctx.rect(0, 0, targetW, targetH);
          ctx.clip();
          ctx.drawImage(img, drawX, drawY, drawW, drawH);
          ctx.restore();
            }
          
          // 3. Smooth Gradient Blend from Image to Background
          const grad = ctx.createLinearGradient(0, 600, 0, 810);
          grad.addColorStop(0, 'rgba(15, 23, 42, 0)');
          grad.addColorStop(1, 'rgba(15, 23, 42, 1)');
          ctx.fillStyle = grad;
          ctx.fillRect(0, 500, 1080, 310);
          
          // 4. Draw Typography (Perfectly Aligned)
          
          // TITLE (Left aligned)
          ctx.fillStyle = '#ffffff';
          ctx.font = 'bold 50px Arial';
          ctx.textAlign = 'left';
          let title = currentListing.title || 'Product';
          if (title.length > 40) title = title.substring(0, 37) + '...';
          ctx.fillText(title, 60, 880);
          
          // PRICE (Left aligned, big & yellow)
          ctx.fillStyle = '#fde047'; // Yellow
          ctx.font = '900 80px Arial';
          ctx.fillText('Rs. ' + Number(currentListing.price || 0).toLocaleString('en-IN'), 60, 980);
          
          // LOCATION (Right aligned)
          const locStr = [currentListing.location_city, currentListing.location_state].filter(Boolean).join(', ') || currentListing.location || 'Local';
          ctx.fillStyle = '#94a3b8'; // Slate-400
          ctx.font = 'bold 36px Arial';
          ctx.textAlign = 'right';
          ctx.fillText('📍 ' + locStr, 1020, 880);
          
          // ZIPZAPZOI BRANDING (Right aligned)
          ctx.fillStyle = '#10b981'; // Primary Emerald Green
          ctx.font = '900 50px Arial';
          ctx.fillText('ZipZapZoi.com', 1020, 980);
          
          // Convert to blob and share/download
          // Convert to blob and share/download
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
            btn.innerHTML = oldContent;
          
        } catch (e) {
          console.error("Poster error:", e);
          btn.innerHTML = oldContent;
          showToast('Failed to generate poster.', 'error');
        }
      }
      
      function copyShareLink() {
         navigator.clipboard.writeText(window._shareLinkUrl).then(() => {
           showToast('Link copied to clipboard!');
         });
      }

      // â”€â”€â”€ SELLER PROFILE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
      // ─── SELLER PROFILE ──────────────────────────────────────────────
      function viewSellerProfile() {
        if (!currentListing) return;
        window.location.href = 'profile.html?sellerId=' + encodeURIComponent(currentListing.user_id);
      }

      // ─── HELPERS ─────────────────────────────────────────────────────
      function timeAgo(dateStr) {
        if (!dateStr) return 'Just now';
        const d    = new Date(dateStr);
        const diff = Math.floor((Date.now() - d) / 1000);
        if (diff < 60)     return 'Just now';
        if (diff < 3600)   return Math.floor(diff/60) + 'm ago';
        if (diff < 86400)  return Math.floor(diff/3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
        return d.toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric' });
      }

      function showToast(msg, type) {
        type = type || 'success';
        let c = document.getElementById('zzz-tc');
        if (!c) { c = document.createElement('div'); c.id='zzz-tc'; c.className='fixed bottom-6 right-6 z-[999] flex flex-col gap-2'; document.body.appendChild(c); }
        const t = document.createElement('div');
        const san = s => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
        t.className = 'flex items-center gap-3 px-5 py-3 rounded-xl shadow-xl text-white font-bold text-sm transition-all duration-300 translate-y-4 opacity-0 ' + (type==='error'?'bg-red-500':'bg-primary');
        t.innerHTML = '<span class="material-symbols-outlined text-[18px]">' + (type==='error'?'error':'check_circle') + '</span>' + san(msg);
        c.appendChild(t);
        requestAnimationFrame(() => t.classList.remove('translate-y-4','opacity-0'));
        setTimeout(() => { t.classList.add('translate-y-4','opacity-0'); setTimeout(() => t.remove(), 300); }, 4000);
      }

      // ─── REPORTING ───────────────────────────────────────────────────
      function openReportModal() {
          document.getElementById('reportModal').style.display = 'flex';
      }
      function closeReportModal() {
          document.getElementById('reportModal').style.display = 'none';
          document.getElementById('reportReason').value = '';
      }
      async function submitReport() {
          if (!currentListing) return;
          const reason = document.getElementById('reportReason').value.trim();
          if (!reason) {
              showToast('Please enter a reason for reporting.', 'error');
              return;
          }
          const t = localStorage.getItem('token');
          if (!t) {
              showToast('You must be logged in to report.', 'error');
              setTimeout(() => { window.location.href = 'Login Page.html'; }, 1500);
              return;
          }
          
          try {
              const r = await fetch('/api/report.php', {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + t },
                  body: JSON.stringify({ listing_id: currentListing.id, reported_user: currentListing.user_id, reason })
              });
              const data = await r.json();
              if (data.success) {
                  showToast(data.message);
                  closeReportModal();
              } else {
                  showToast(data.error || 'Failed to submit report', 'error');
              }
          } catch (e) {
              showToast('Network error', 'error');
          }
      }
    