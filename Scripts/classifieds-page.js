/**
 * classifieds-page.js
 * ZipZapZoi Classifieds – UI logic for listings on classifieds.html
 */

class ClassifiedsPage {
  constructor() {
    this.currentPage = 1;
    this.itemsPerPage = 12;
    this.listings = [];
    this.filteredListings = [];
    this.sortBy = 'newest';

    this.filters = {
      search: '',
      category: '',
      city: ''
    };

    this.init();
  }
loadSchemaCategories() {
  try {
    const raw = localStorage.getItem('zzz_classifieds_schema');
    if (!raw) return;
    const schema = JSON.parse(raw);
    const categories = schema.categories || [];
    const select = document.getElementById('filterCategory');
    if (!select) return;

    // Clear existing (keep the first "All" / placeholder option if you have one)
    const first = select.firstElementChild;
    select.innerHTML = '';
    if (first) select.appendChild(first);

    categories.forEach(cat => {
      const opt = document.createElement('option');
      opt.value = cat.id;          // important: use id, not label
      opt.textContent = cat.label; // what user sees
      select.appendChild(opt);
    });
  } catch (e) {
    console.error('Failed to load schema categories', e);
  }
}
  init() {
  console.log('ClassifiedsPage initialized');
  this.setupEventListeners();
  this.loadSchemaCategories();   // NEW: build category dropdown from AdminConsoleV2
  this.loadListings();
}

  setupEventListeners() {
   const categoryCards = document.querySelectorAll('[data-category]');
categoryCards.forEach(card => {
  card.addEventListener('click', () => {
    const cat = card.getAttribute('data-category') || '';
    this.filters.category = cat;
    const categorySelect = document.getElementById('filterCategory');
    if (categorySelect) categorySelect.value = cat;
    this.applyFilters();
    const grid = document.getElementById('listingsGrid');
    if (grid) grid.scrollIntoView({ behavior: 'smooth' });
  });
});
    const searchInput = document.getElementById('searchQuery');
    const cityInput = document.getElementById('filterCity');
    const btnSearch = document.getElementById('btnSearch');
    const categorySelect = document.getElementById('filterCategory');
    const sortSelect = document.getElementById('sortBy');

    if (btnSearch) {
      btnSearch.addEventListener('click', () => this.applyFilters());
    }

    if (searchInput) {
      searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') this.applyFilters();
      });
    }

    if (cityInput) {
      cityInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') this.applyFilters();
      });
    }

    if (categorySelect) {
      categorySelect.addEventListener('change', (e) => {
        this.filters.category = e.target.value;
        this.applyFilters();
      });
    }

    if (sortSelect) {
      sortSelect.addEventListener('change', (e) => {
        this.sortBy = e.target.value;
        this.sortListings();
        this.renderListings();
      });
    }
  }

  showPostSuccessIfNeeded() {
    const params = new URLSearchParams(window.location.search);
    if (params.get('posted') === '1') {
      const toast = document.getElementById('postSuccessToast');
      if (toast) {
        toast.classList.remove('hidden');
        setTimeout(() => toast.classList.add('hidden'), 4000);
      }
    }
  }

  loadListings() {
    const mockListings = [
      {
        id: 1,
        title: 'Classic Cruiser Bicycle',
        description: 'Vintage-style bicycle in great condition, perfect for city rides.',
        price: 150,
        category: 'vehicles',
        city: 'Brooklyn, NY',
        image: 'https://images.unsplash.com/photo-1485965120184-e220f721d03e?auto=format&fit=crop&w=600&q=80',
        seller: { name: 'Alex Rider', rating: 4.7 },
        views: 120,
        featured: true,
        verified: false,
        postedAt: new Date('2024-06-01T10:00:00')
      },
      {
        id: 2,
        title: 'Smartphone Pro X',
        description: 'Flagship smartphone with high-end camera and display.',
        price: 799,
        category: 'electronics',
        city: 'San Francisco, CA',
        image: 'https://images.unsplash.com/photo-1511707171634-5f897ff02aa9?auto=format&fit=crop&w=600&q=80',
        seller: { name: 'Tech World', rating: 4.9 },
        views: 210,
        featured: true,
        verified: true,
        postedAt: new Date('2024-06-10T12:00:00')
      },
      {
        id: 3,
        title: 'Limited Edition Sneakers',
        description: 'Rare drop, lightly used, original box included.',
        price: 220,
        category: 'fashion',
        city: 'Los Angeles, CA',
        image: 'https://images.unsplash.com/photo-1595950653106-6c9ebd614d3a?auto=format&fit=crop&w=600&q=80',
        seller: { name: 'Sneaker Hub', rating: 4.8 },
        views: 180,
        featured: false,
        verified: true,
        postedAt: new Date('2024-06-05T09:00:00')
      },
      {
        id: 4,
        title: 'Mid-Century Armchair',
        description: 'Comfortable leather armchair, perfect for living room.',
        price: 450,
        category: 'property',
        city: 'Chicago, IL',
        image: 'https://images.unsplash.com/photo-1503602642458-232111445657?auto=format&fit=crop&w=600&q=80',
        seller: { name: 'Home Studio', rating: 4.6 },
        views: 95,
        featured: false,
        verified: false,
        postedAt: new Date('2024-05-28T15:00:00')
      }
    ];

    const userPosts = JSON.parse(localStorage.getItem('zzz_listings') || '[]');
    
    // Ensure active status and map fields if needed
    const activeUserPosts = userPosts.filter(p => p.status !== 'sold').map(p => ({
        id: p.id,
        title: p.title,
        description: p.description,
        price: p.price,
        category: (p.category || '').toLowerCase(), // normalize
        city: p.city || p.location || 'Unknown',
        image: p.image || 'https://via.placeholder.com/600',
        seller: { name: p.userName || 'User', rating: 5.0 },
        postedAt: p.time === 'Just now' ? new Date() : new Date(p.time || Date.now())
    }));

    this.listings = [...activeUserPosts, ...mockListings];
    this.applyFilters();
  }
  applyFilters() {
    const searchInput = document.getElementById('searchQuery');
    const cityInput = document.getElementById('filterCity');

    this.filters.search = (searchInput?.value || '').trim().toLowerCase();
    const rawCity = (cityInput?.value || '').trim().toLowerCase();

    this.filteredListings = this.listings.filter((listing) => {
      if (this.filters.search) {
        const haystack = (listing.title + ' ' + listing.description).toLowerCase();
        if (!haystack.includes(this.filters.search)) return false;
      }

      if (this.filters.category && listing.category !== this.filters.category) {
        return false;
      }

      if (rawCity) {
        if (!listing.city.toLowerCase().includes(rawCity)) return false;
      }

      return true;
    });

    this.currentPage = 1;
    this.sortListings();
    this.renderListings();
  }

  sortListings() {
    const sort = this.sortBy;
    this.filteredListings.sort((a, b) => {
      if (sort === 'price-low') return a.price - b.price;
      if (sort === 'price-high') return b.price - a.price;
      return new Date(b.postedAt) - new Date(a.postedAt);
    });
  }

  renderListings() {
    const grid = document.getElementById('listingsGrid');
    if (!grid) return;

    if (!this.filteredListings.length) {
      grid.innerHTML = `
        <div class="col-span-full text-center py-10 text-text-light/70 dark:text-text-dark/70">
          <p class="text-lg font-semibold">No listings found.</p>
          <p class="text-sm mt-1">Try changing your search or filters.</p>
        </div>
      `;
      return;
    }

    const start = (this.currentPage - 1) * this.itemsPerPage;
    const end = start + this.itemsPerPage;
    const pageItems = this.filteredListings.slice(start, end);

    grid.innerHTML = pageItems.map((listing) => this.createListingCard(listing)).join('');
  }

  createListingCard(listing) {
    const formattedPrice = `$${listing.price}`;

    return `
      <a
        href="Listing Detail.html?id=${listing.id}"
        class="group bg-surface-light dark:bg-surface-dark rounded-lg overflow-hidden shadow-soft hover:shadow-lift hover:-translate-y-1 transition-all duration-300 border border-border-light dark:border-border-dark block"
        data-id="${listing.id}"
      >
        <div
          class="w-full aspect-square bg-cover bg-center"
          style="background-image: url('${listing.image}')"
        ></div>
        <div class="p-4">
          <h3 class="font-bold text-lg truncate">${listing.title}</h3>
          <p class="text-2xl font-bold text-secondary dark:text-primary mt-1">${formattedPrice}</p>
          <p class="text-sm text-text-light/60 dark:text-text-dark/60 mt-1">${listing.city}</p>
        </div>
      </a>
    `;
  }
}

document.addEventListener('DOMContentLoaded', () => {
  window.classifiedsPage = new ClassifiedsPage();
});
