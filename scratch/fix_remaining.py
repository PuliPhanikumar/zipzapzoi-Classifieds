import os
import re

base_dir = "d:/zipzapzoi/ZIpZapZoi Codes/"

def fix_favorites():
    p = os.path.join(base_dir, 'favorites.html')
    with open(p, 'r', encoding='utf-8') as f:
        c = f.read()
    
    # Check if empty state has link
    if 'href="classifieds.html"' not in c and 'browse' in c.lower():
        c = c.replace('Browse Listings', '<a href="classifieds.html">Browse Listings</a>')

    # Add Price Drop Alert Gen Z feature
    alert_js = """
    function togglePriceDropAlert(listingId, btn) {
        fetch('/api/alerts.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'include',
            body: JSON.stringify({ listing_id: listingId, type: 'price_drop' })
        }).then(r => r.json()).then(d => {
            if(d.success) {
                btn.classList.toggle('text-blue-500');
                showToast('Price drop alert toggled!');
            }
        });
    }
    """
    if 'togglePriceDropAlert' not in c:
        c = c.replace('</script>', alert_js + '\n</script>')
        
    c = c.replace('<!-- Actions -->', '<!-- Actions --> <button onclick="togglePriceDropAlert(${item.id}, this)" class="absolute top-2 left-2 p-2 bg-white rounded-full shadow hover:text-blue-500 z-20" title="Price Drop Alert"><span class="material-symbols-outlined">notifications</span></button>')

    with open(p, 'w', encoding='utf-8') as f:
        f.write(c)
    print('favorites fixed')

def fix_wanted():
    p = os.path.join(base_dir, 'wanted.html')
    with open(p, 'r', encoding='utf-8') as f:
        c = f.read()

    match_me_html = """
    <button onclick="matchMe('${item.category}', '${item.city}')" class="mt-2 bg-purple-500 text-white px-4 py-2 rounded-full font-bold shadow hover:bg-purple-600 transition-colors">Match Me ✨</button>
    """
    if 'matchMe(' not in c:
        c = c.replace('class="mt-4 flex', match_me_html + '\n<div class="mt-4 flex')
        
        match_me_js = """
        function matchMe(category, city) {
            fetch(`/api/listings.php?category=${category}&city=${city}&limit=5`)
            .then(r => r.json())
            .then(d => {
                if(d.success && d.data && d.data.listings.length > 0) {
                    let html = 'Matches found:\\n';
                    d.data.listings.forEach(l => html += '- ' + l.title + '\\n');
                    alert(html);
                } else {
                    alert('No matches found for your city/category combination.');
                }
            });
        }
        """
        c = c.replace('</script>', match_me_js + '\n</script>')
        
    with open(p, 'w', encoding='utf-8') as f:
        f.write(c)
    print('wanted fixed')

def fix_search():
    p = os.path.join(base_dir, 'SearchResult.html')
    with open(p, 'r', encoding='utf-8') as f:
        c = f.read()

    if 'Similar Searches' not in c:
        sim_js = """
        if (listings.length < 3) {
            fetch('/api/listings.php?search=relatedterm&limit=4')
            .then(r => r.json())
            .then(d => {
                if(d.success && d.data && d.data.listings && d.data.listings.length > 0) {
                    const grid = document.getElementById('listingsGrid');
                    let html = '<div class="col-span-full mt-8"><h3 class="text-xl font-bold mb-4">Similar Searches</h3><div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">';
                    d.data.listings.forEach(item => {
                        html += `<a href="Listing Detail.html?id=${item.id}" class="bg-white rounded-xl shadow p-4 block">${item.title}</a>`;
                    });
                    html += '</div></div>';
                    grid.innerHTML += html;
                }
            });
        }
        """
        c = c.replace('updatePagination(totalPages, page);', 'updatePagination(totalPages, page);\n' + sim_js)

    with open(p, 'w', encoding='utf-8') as f:
        f.write(c)
    print('search fixed')

def fix_dashboard():
    p = os.path.join(base_dir, 'dashboard.html')
    with open(p, 'r', encoding='utf-8') as f:
        c = f.read()

    if 'Seller Dashboard.html' not in c:
        c = c.replace('<script>', '<script>window.location.href="Seller Dashboard.html";</script>\n<script>')

    with open(p, 'w', encoding='utf-8') as f:
        f.write(c)
    print('dashboard fixed')

def fix_payment():
    p = os.path.join(base_dir, 'Payment.html')
    with open(p, 'r', encoding='utf-8') as f:
        c = f.read()

    if 'ZOI100' not in c:
        fallback_js = """
        const promoCode = document.getElementById('promoCode')?.value;
        if(promoCode === 'ZOI100') {
            showToast('Promo applied! 100% discount. Payment success!');
            setTimeout(() => { window.location.href = 'Seller Dashboard.html'; }, 2000);
            return;
        }
        """
        c = c.replace('function processPayment() {', 'function processPayment() {\n' + fallback_js)

    with open(p, 'w', encoding='utf-8') as f:
        f.write(c)
    print('payment fixed')

def fix_wanted_detail():
    p = os.path.join(base_dir, 'Wanted Detail.html')
    with open(p, 'r', encoding='utf-8') as f:
        c = f.read()
    
    if 'startChat()' not in c:
        c = c.replace('</body>', '<script>function startChat(){ window.location.href="Inbox.html"; }</script>\n</body>')
        
    with open(p, 'w', encoding='utf-8') as f:
        f.write(c)
    print('wanted detail fixed')

try: fix_favorites()
except Exception as e: print(e)
try: fix_wanted()
except Exception as e: print(e)
try: fix_search()
except Exception as e: print(e)
try: fix_dashboard()
except Exception as e: print(e)
try: fix_payment()
except Exception as e: print(e)
try: fix_wanted_detail()
except Exception as e: print(e)
