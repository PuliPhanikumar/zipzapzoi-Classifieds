/**
 * ZipZapZoi Data Manager
 * Centralized data system for the entire classified site
 * Works with localStorage and is API-ready for backend integration
 */

const ZZZDataManager = (function() {
  'use strict';

  const STORAGE_KEY = 'zipzapzoi_data';
  const USER_KEY = 'zipzapzoi_current_user';

  // Initialize default data structure
  let appData = {
    categories: [],
    listings: [],
    users: [],
    messages: [],
    settings: {
      siteName: 'ZipZapZoi',
      currency: '₹',
      adminEmail: 'admin@zipzapzoi.com'
    },
    version: '1.0'
  };

  // Load data from localStorage
  function loadData() {
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (stored) {
        appData = JSON.parse(stored);
      } else {
        initializeSampleData();
        saveData();
      }
    } catch (error) {
      console.error('Error loading data:', error);
      initializeSampleData();
    }
  }

  // Save data to localStorage
  function saveData() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(appData));
      return true;
    } catch (error) {
      console.error('Error saving data:', error);
      return false;
    }
  }

  // Initialize sample data
  function initializeSampleData() {
    appData.categories = [
      {
        id: 'cat_electronics',
        name: 'Electronics',
        icon: 'devices',
        slug: 'electronics',
        subcategories: [
          {
            id: 'sub_mobiles',
            name: 'Mobile Phones',
            slug: 'mobile-phones',
            fields: [
              { id: 'f1', label: 'Brand', type: 'text', required: true, placeholder: 'e.g., Samsung, Apple' },
              { id: 'f2', label: 'Model', type: 'text', required: true, placeholder: 'e.g., Galaxy S23' },
              { id: 'f3', label: 'Price', type: 'number', required: true, placeholder: 'Enter price' },
              { id: 'f4', label: 'Condition', type: 'select', required: true, options: ['New', 'Like New', 'Used', 'Refurbished'] },
              { id: 'f5', label: 'RAM', type: 'select', required: false, options: ['4GB', '6GB', '8GB', '12GB', '16GB'] },
              { id: 'f6', label: 'Storage', type: 'select', required: false, options: ['64GB', '128GB', '256GB', '512GB', '1TB'] }
            ]
          },
          {
            id: 'sub_laptops',
            name: 'Laptops',
            slug: 'laptops',
            fields: [
              { id: 'f7', label: 'Brand', type: 'text', required: true, placeholder: 'e.g., Dell, HP, Lenovo' },
              { id: 'f8', label: 'Model', type: 'text', required: true, placeholder: 'e.g., XPS 13' },
              { id: 'f9', label: 'Price', type: 'number', required: true, placeholder: 'Enter price' },
              { id: 'f10', label: 'Processor', type: 'text', required: false, placeholder: 'e.g., Intel i7' },
              { id: 'f11', label: 'RAM', type: 'select', required: false, options: ['4GB', '8GB', '16GB', '32GB', '64GB'] },
              { id: 'f12', label: 'Storage Type', type: 'select', required: false, options: ['SSD', 'HDD', 'Hybrid'] }
            ]
          }
        ]
      },
      {
        id: 'cat_vehicles',
        name: 'Vehicles',
        icon: 'directions_car',
        slug: 'vehicles',
        subcategories: [
          {
            id: 'sub_cars',
            name: 'Cars',
            slug: 'cars',
            fields: [
              { id: 'f13', label: 'Make', type: 'text', required: true, placeholder: 'e.g., Toyota, Honda' },
              { id: 'f14', label: 'Model', type: 'text', required: true, placeholder: 'e.g., Camry' },
              { id: 'f15', label: 'Year', type: 'number', required: true, placeholder: 'e.g., 2020' },
              { id: 'f16', label: 'Price', type: 'number', required: true, placeholder: 'Enter price' },
              { id: 'f17', label: 'Fuel Type', type: 'select', required: true, options: ['Petrol', 'Diesel', 'Electric', 'Hybrid'] },
              { id: 'f18', label: 'Kilometers', type: 'number', required: false, placeholder: 'Odometer reading' }
            ]
          }
        ]
      },
      {
        id: 'cat_realestate',
        name: 'Real Estate',
        icon: 'home',
        slug: 'real-estate',
        subcategories: [
          {
            id: 'sub_apartments',
            name: 'Apartments',
            slug: 'apartments',
            fields: [
              { id: 'f19', label: 'Property Type', type: 'select', required: true, options: ['Rent', 'Sale'] },
              { id: 'f20', label: 'BHK', type: 'select', required: true, options: ['1 BHK', '2 BHK', '3 BHK', '4 BHK', '5+ BHK'] },
              { id: 'f21', label: 'Price', type: 'number', required: true, placeholder: 'Enter price' },
              { id: 'f22', label: 'Area (sq ft)', type: 'number', required: false, placeholder: 'Built-up area' },
              { id: 'f23', label: 'Furnishing', type: 'select', required: false, options: ['Fully Furnished', 'Semi Furnished', 'Unfurnished'] }
            ]
          }
        ]
      }
    ];
  }

  // ===== CATEGORY METHODS =====
  function getAllCategories() {
    return appData.categories || [];
  }

  function getCategoryById(categoryId) {
    return appData.categories.find(cat => cat.id === categoryId);
  }

  function getCategoryBySlug(slug) {
    return appData.categories.find(cat => cat.slug === slug);
  }

  function addCategory(categoryData) {
    const newCategory = {
      id: 'cat_' + Date.now(),
      name: categoryData.name,
      icon: categoryData.icon || 'category',
      slug: categoryData.name.toLowerCase().replace(/\s+/g, '-'),
      subcategories: []
    };
    appData.categories.push(newCategory);
    saveData();
    return newCategory;
  }

  function updateCategory(categoryId, updates) {
    const category = getCategoryById(categoryId);
    if (category) {
      Object.assign(category, updates);
      saveData();
      return category;
    }
    return null;
  }

  function deleteCategory(categoryId) {
    appData.categories = appData.categories.filter(cat => cat.id !== categoryId);
    saveData();
  }

  // ===== SUBCATEGORY METHODS =====
  function getSubcategoriesByCategory(categoryId) {
    const category = getCategoryById(categoryId);
    return category?.subcategories || [];
  }

  function getSubcategoryById(categoryId, subcategoryId) {
    const category = getCategoryById(categoryId);
    return category?.subcategories.find(sub => sub.id === subcategoryId);
  }

  function addSubcategory(categoryId, subcategoryData) {
    const category = getCategoryById(categoryId);
    if (category) {
      const newSubcategory = {
        id: 'sub_' + Date.now(),
        name: subcategoryData.name,
        slug: subcategoryData.name.toLowerCase().replace(/\s+/g, '-'),
        fields: []
      };
      if (!category.subcategories) category.subcategories = [];
      category.subcategories.push(newSubcategory);
      saveData();
      return newSubcategory;
    }
    return null;
  }

  function deleteSubcategory(categoryId, subcategoryId) {
    const category = getCategoryById(categoryId);
    if (category && category.subcategories) {
      category.subcategories = category.subcategories.filter(sub => sub.id !== subcategoryId);
      saveData();
    }
  }

  // ===== FIELD METHODS =====
  function getFieldsBySubcategory(categoryId, subcategoryId) {
    const subcategory = getSubcategoryById(categoryId, subcategoryId);
    return subcategory?.fields || [];
  }

  function addField(categoryId, subcategoryId, fieldData) {
    const subcategory = getSubcategoryById(categoryId, subcategoryId);
    if (subcategory) {
      const newField = {
        id: 'f' + Date.now(),
        label: fieldData.label,
        type: fieldData.type,
        required: fieldData.required || false,
        placeholder: fieldData.placeholder || '',
        options: fieldData.options || []
      };
      if (!subcategory.fields) subcategory.fields = [];
      subcategory.fields.push(newField);
      saveData();
      return newField;
    }
    return null;
  }

  function deleteField(categoryId, subcategoryId, fieldId) {
    const subcategory = getSubcategoryById(categoryId, subcategoryId);
    if (subcategory && subcategory.fields) {
      subcategory.fields = subcategory.fields.filter(f => f.id !== fieldId);
      saveData();
    }
  }

  // ===== LISTING METHODS =====
  function getAllListings() {
    return appData.listings || [];
  }

  function getListingById(listingId) {
    return appData.listings.find(listing => listing.id === listingId);
  }

  function getListingsByCategory(categoryId) {
    return appData.listings.filter(listing => listing.categoryId === categoryId);
  }

  function getListingsByUser(userId) {
    return appData.listings.filter(listing => listing.userId === userId);
  }

  function addListing(listingData) {
    const newListing = {
      id: 'listing_' + Date.now(),
      ...listingData,
      createdAt: new Date().toISOString(),
      status: listingData.status || 'pending',
      views: 0
    };
    appData.listings.push(newListing);
    saveData();
    return newListing;
  }

  function updateListing(listingId, updates) {
    const listing = getListingById(listingId);
    if (listing) {
      Object.assign(listing, updates);
      listing.updatedAt = new Date().toISOString();
      saveData();
      return listing;
    }
    return null;
  }

  function deleteListing(listingId) {
    appData.listings = appData.listings.filter(listing => listing.id !== listingId);
    saveData();
  }

  // ===== USER METHODS =====
  function getCurrentUser() {
    try {
      const userStr = localStorage.getItem(USER_KEY) || localStorage.getItem('zzz_user') || sessionStorage.getItem('zzz_user');
      return userStr ? JSON.parse(userStr) : null;
    } catch (error) {
      return null;
    }
  }

  function setCurrentUser(user) {
    if (user) {
      localStorage.setItem(USER_KEY, JSON.stringify(user));
      localStorage.setItem('zzz_user', JSON.stringify(user));
    } else {
      localStorage.removeItem(USER_KEY);
      localStorage.removeItem('zzz_user');
      sessionStorage.removeItem('zzz_user');
    }
  }

  function registerUser(userData) {
    const newUser = {
      id: 'user_' + Date.now(),
      email: userData.email,
      name: userData.name,
      phone: userData.phone || '',
      role: userData.role || 'seller',
      createdAt: new Date().toISOString(),
      status: 'active'
    };
    appData.users.push(newUser);
    saveData();
    return newUser;
  }

  function getUserById(userId) {
    return appData.users.find(user => user.id === userId);
  }

  function getUserByEmail(email) {
    return appData.users.find(user => user.email === email);
  }

  // ===== MESSAGE METHODS =====
  function addMessage(messageData) {
    const newMessage = {
      id: 'msg_' + Date.now(),
      ...messageData,
      createdAt: new Date().toISOString(),
      read: false
    };
    if (!appData.messages) appData.messages = [];
    appData.messages.push(newMessage);
    saveData();
    return newMessage;
  }

  function getMessagesByUser(userId) {
    return (appData.messages || []).filter(
      msg => msg.toUserId === userId || msg.fromUserId === userId
    );
  }

  function markMessageAsRead(messageId) {
    const message = (appData.messages || []).find(msg => msg.id === messageId);
    if (message) {
      message.read = true;
      saveData();
    }
  }

  // ===== SEARCH & FILTER =====
  function searchListings(query) {
    const searchTerm = query.toLowerCase();
    return appData.listings.filter(listing => 
      listing.title?.toLowerCase().includes(searchTerm) ||
      listing.description?.toLowerCase().includes(searchTerm)
    );
  }

  function filterListings(filters) {
    let results = appData.listings;

    if (filters.categoryId) {
      results = results.filter(l => l.categoryId === filters.categoryId);
    }
    if (filters.subcategoryId) {
      results = results.filter(l => l.subcategoryId === filters.subcategoryId);
    }
    if (filters.minPrice) {
      results = results.filter(l => l.price >= filters.minPrice);
    }
    if (filters.maxPrice) {
      results = results.filter(l => l.price <= filters.maxPrice);
    }
    if (filters.status) {
      results = results.filter(l => l.status === filters.status);
    }

    return results;
  }

  // ===== STATISTICS =====
  function getStats() {
    return {
      totalCategories: appData.categories.length,
      totalListings: appData.listings.length,
      activeListings: appData.listings.filter(l => l.status === 'active').length,
      totalUsers: appData.users.length,
      pendingListings: appData.listings.filter(l => l.status === 'pending').length
    };
  }

  // Initialize on load
  loadData();

  // Public API
  return {
    // Category methods
    getAllCategories,
    getCategoryById,
    getCategoryBySlug,
    addCategory,
    updateCategory,
    deleteCategory,

    // Subcategory methods
    getSubcategoriesByCategory,
    getSubcategoryById,
    addSubcategory,
    deleteSubcategory,

    // Field methods
    getFieldsBySubcategory,
    addField,
    deleteField,

    // Listing methods
    getAllListings,
    getListingById,
    getListingsByCategory,
    getListingsByUser,
    addListing,
    updateListing,
    deleteListing,

    // User methods
    getCurrentUser,
    setCurrentUser,
    registerUser,
    getUserById,
    getUserByEmail,

    // Message methods
    addMessage,
    getMessagesByUser,
    markMessageAsRead,

    // Search & filter
    searchListings,
    filterListings,

    // Statistics
    getStats,

    // Utility
    saveData,
    loadData
  };
})();

// Make available globally
window.ZZZDataManager = ZZZDataManager;
