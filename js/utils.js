/**
 * ZipZapZoi Classifieds — Shared Utilities
 * Include this file in every page: <script src="js/utils.js"></script>
 */

// ============================================
// Google Analytics (GA4) Placeholder
// ============================================
(function() {
    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://www.googletagmanager.com/gtag/js?id=G-XXXXXXXXXX';
    document.head.appendChild(script);

    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    window.gtag = gtag;
    gtag('js', new Date());
    gtag('config', 'G-XXXXXXXXXX');
})();

// ============================================
// Global Dark Mode Initializer
// ============================================
(function() {
    const theme = localStorage.getItem('zzz_theme');
    const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    if (theme === 'dark' || (!theme && prefersDark)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }
})();

window.toggleDarkMode = () => {
    const html = document.documentElement;
    const isDark = html.classList.toggle('dark');
    localStorage.setItem('zzz_theme', isDark ? 'dark' : 'light');
    
    // Update theme icon if it exists on the page
    const themeIcon = document.getElementById('themeIcon');
    if (themeIcon) {
        themeIcon.textContent = isDark ? 'light_mode' : 'dark_mode';
    }
    const themeIconSidebar = document.getElementById('themeIconSidebar');
    if (themeIconSidebar) {
        themeIconSidebar.textContent = isDark ? 'light_mode' : 'dark_mode';
    }
};

// ============================================
// Shared Storage / Data Helpers
// ============================================
window.ZZZ = window.ZZZ || {};

window.ZZZ.safeParse = function safeParse(raw, fallback) {
    try {
        return raw ? JSON.parse(raw) : fallback;
    } catch (error) {
        console.warn('ZipZapZoi: failed to parse stored data', error);
        return fallback;
    }
};

window.ZZZ.read = function readStorage(key, fallback) {
    return window.ZZZ.safeParse(localStorage.getItem(key), fallback);
};

window.ZZZ.write = function writeStorage(key, value) {
    localStorage.setItem(key, JSON.stringify(value));
    localStorage.setItem('zzz_data_updated_at', String(Date.now()));
    window.dispatchEvent(new CustomEvent('zzz:data-updated', { detail: { key, value } }));
};

window.ZZZ.ensureClassifiedsSchema = function ensureClassifiedsSchema() {
    const existing = window.ZZZ.read('zzz_classifieds_schema', null);
    if (existing && Array.isArray(existing.categories) && Array.isArray(existing.subcategories) && Array.isArray(existing.fields)) {
        return existing;
    }

    const source = window.ZZZ_SCHEMA || { categories: [], subcategories: [], fields: [] };
    const schema = JSON.parse(JSON.stringify(source));
    window.ZZZ.write('zzz_classifieds_schema', schema);
    return schema;
};

window.ZZZ.getClassifiedsSchema = function getClassifiedsSchema() {
    return window.ZZZ.ensureClassifiedsSchema();
};

window.ZZZ.getCurrentUser = function getCurrentUserCompat() {
    return (
        window.ZZZ.read('zzz_user', null) ||
        window.ZZZ.safeParse(sessionStorage.getItem('zzz_user'), null)
    );
};

window.ZZZ.setCurrentUser = function setCurrentUserCompat(user) {
    if (user) {
        localStorage.setItem('zzz_user', JSON.stringify(user));
    } else {
        localStorage.removeItem('zzz_user');
        sessionStorage.removeItem('zzz_user');
    }
    localStorage.setItem('zzz_data_updated_at', String(Date.now()));
};

window.ZZZ.getFavoriteIds = function getFavoriteIds(userId) {
    const user = userId ? { id: userId } : window.ZZZ.getCurrentUser();
    if (!user || !user.id) return [];

    const byUser = window.ZZZ.read('zzz_favorites_by_user', {});
    if (Array.isArray(byUser[user.id])) return byUser[user.id];

    const legacy = window.ZZZ.read('zzz_favorites', []);
    return Array.isArray(legacy) ? legacy : [];
};

window.ZZZ.setFavoriteIds = function setFavoriteIds(ids, userId) {
    const user = userId ? { id: userId } : window.ZZZ.getCurrentUser();
    if (!user || !user.id) return;

    const byUser = window.ZZZ.read('zzz_favorites_by_user', {});
    byUser[user.id] = Array.from(new Set(ids));
    window.ZZZ.write('zzz_favorites_by_user', byUser);
};

window.ZZZ.toggleFavorite = function toggleFavorite(listingId, userId) {
    const ids = window.ZZZ.getFavoriteIds(userId);
    const next = ids.includes(listingId) ? ids.filter(id => id !== listingId) : [...ids, listingId];
    window.ZZZ.setFavoriteIds(next, userId);
    return next.includes(listingId);
};

// ============================================
// CSRF Token Management
// ============================================
function generateCsrfToken() {
    return 'csrf_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
}
function getCsrfToken() {
    let token = sessionStorage.getItem('csrf_token');
    if (!token) { token = generateCsrfToken(); sessionStorage.setItem('csrf_token', token); }
    return token;
}
function setCsrfToken() {
    const token = getCsrfToken();
    document.querySelectorAll('input[name="csrf_token"]').forEach(input => input.value = token);
}

// ============================================
// Input Validation
// ============================================
function validateEmail(email) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email); }
function validatePhone(phone) { return phone.replace(/\D/g, '').length === 10; }
function validatePrice(price) { const n = parseFloat(price); return !isNaN(n) && n > 0 && n < 10000000; }
function validatePassword(password) { return password.length >= 6; }

// ============================================
// Input Sanitization
// ============================================
function sanitizeInput(input) { const d = document.createElement('div'); d.textContent = input; return d.innerHTML; }
function sanitizeHTML(html) { const d = document.createElement('div'); d.innerHTML = html; return d.textContent; }

// ============================================
// Toast Notification System
// ============================================
function showToast(message, type = 'success') {
    let toast = document.getElementById('zzz-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'zzz-toast';
        toast.style.cssText = `position:fixed;bottom:24px;left:50%;transform:translateX(-50%) translateY(100px);z-index:9999;padding:14px 24px;border-radius:100px;font-family:'Spline Sans',sans-serif;font-weight:700;font-size:14px;color:white;box-shadow:0 8px 32px rgba(0,0,0,0.2);transition:transform 0.4s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s ease;opacity:0;white-space:nowrap;`;
        document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.style.backgroundColor = type === 'success' ? '#019863' : type === 'error' ? '#ef4444' : '#f59e0b';
    toast.style.opacity = '1';
    toast.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(toast._timeout);
    toast._timeout = setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(-50%) translateY(100px)';
    }, 3000);
}

// ============================================
// Auth Utilities
// ============================================
function getCurrentUser() {
    return window.ZZZ.getCurrentUser();
}
function requireAuth(redirectPage) {
    if (!getCurrentUser()) {
        window.location.href = `Login Page.html?redirect=${encodeURIComponent(redirectPage || window.location.href)}`;
        return false;
    }
    return true;
}
function isAdmin() {
    const u = getCurrentUser();
    return u && (u.role === 'admin' || u.role === 'super_admin');
}
function handleLogout() {
    window.ZZZ.setCurrentUser(null);
    window.location.href = 'classifieds.html';
}

// ============================================
// Dynamic Copyright Year
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    setCsrfToken();
    // Update all copyright spans
    document.querySelectorAll('.zzz-copyright').forEach(el => {
        el.textContent = `© ${new Date().getFullYear()} ZipZapZoi. All Rights Reserved.`;
    });
});


// ============================================
// Global Unread Messages Badge
// ============================================
window.ZZZ.updateUnreadBadge = function() {
    const user = window.ZZZ.getCurrentUser();
    if (!user) return;
    
    const allMsgs = window.ZZZ.read('zzz_messages', []);
    let unreadCount = 0;
    
    allMsgs.forEach(m => {
        if (m.toUserId === user.id && !m.read) {
            unreadCount++;
        }
    });
    
    document.querySelectorAll('.zzz-unread-badge').forEach(el => {
        if (unreadCount > 0) {
            el.textContent = unreadCount > 9 ? '9+' : unreadCount;
            el.style.display = 'flex';
        } else {
            el.style.display = 'none';
        }
    });
};

document.addEventListener('DOMContentLoaded', () => {
    window.ZZZ.updateUnreadBadge();
});

window.addEventListener('storage', (e) => {
    if (e.key === 'zzz_messages' || e.key === 'zzz_user') {
        window.ZZZ.updateUnreadBadge();
    }
});

// ============================================
// Plans & Quota Engine
// ============================================
(function() {

  // Default plan definitions — overridable by admin via zzz_plan_config
  const DEFAULT_PLANS = [
    { id: 'new_user_free', name: 'New User Free',  price: 0,   ads: 6,   days: 30, tag: 'First Month Only', tagColor: 'bg-green-500',  icon: 'celebration',   desc: 'Post 6 ads absolutely FREE for your first month.' },
    { id: 'monthly_free',  name: 'Monthly Free',   price: 0,   ads: 1,   days: 30, tag: 'Always Free',      tagColor: 'bg-blue-500',   icon: 'refresh',       desc: 'Every user gets 1 free ad per month, always.' },
    { id: 'extra_ad',      name: 'Extra Ad',       price: 19,  ads: 1,   days: 30, tag: 'Best Value',       tagColor: 'bg-orange-400', icon: 'add_circle',    desc: 'Need more visibility? Post extra ads for just ₹19.' },
    { id: 'renewal',       name: 'Renewal',        price: 16,  ads: 1,   days: 30, tag: 'Renewal',          tagColor: 'bg-purple-500', icon: 'autorenew',     desc: 'Renew your expired ad for only ₹16.' },
    { id: 'starter',       name: 'Starter Pack',   price: 149, ads: 10,  days: 30, tag: 'Popular',          tagColor: 'bg-teal-500',   icon: 'rocket_launch', desc: 'Perfect for small sellers. 10 ads for 30 days.' },
    { id: 'growth',        name: 'Growth Pack',    price: 299, ads: 25,  days: 45, tag: 'Most Popular',     tagColor: 'bg-primary',    icon: 'trending_up',   desc: 'Grow your business. 25 ads, 45 days validity.' },
    { id: 'business',      name: 'Business Pack',  price: 599, ads: 50,  days: 60, tag: 'Business',         tagColor: 'bg-indigo-600', icon: 'business_center', desc: 'Perfect for dealers & resellers. 50 ads, 60 days.' },
    { id: 'pro',           name: 'Pro Pack',       price: 999, ads: 100, days: 90, tag: 'Pro',              tagColor: 'bg-yellow-600', icon: 'workspace_premium', desc: 'Maximum power. 100 ads with 90-day validity.' },
  ];

  // Get plans (admin can override via zzz_plan_config)
  function getPlans() {
    const override = window.ZZZ.read('zzz_plan_config', null);
    if (override && Array.isArray(override) && override.length > 0) return override;
    return DEFAULT_PLANS;
  }

  // Get a single plan by id
  function getPlanById(id) {
    return getPlans().find(p => p.id === id) || null;
  }

  // Get user quota object: { adsRemaining, planName, planId, expiresAt, totalGranted }
  function getUserQuota(userId) {
    if (!userId) return null;
    const allQuotas = window.ZZZ.read('zzz_user_quotas', {});
    return allQuotas[userId] || null;
  }

  // Assign a plan to a user (adds ads to existing balance)
  function assignPlan(userId, planId, opts = {}) {
    if (!userId || !planId) return false;
    const plan = getPlanById(planId);
    if (!plan) return false;

    const allQuotas = window.ZZZ.read('zzz_user_quotas', {});
    const existing = allQuotas[userId] || { adsRemaining: 0, totalGranted: 0, planId: null, planName: null, expiresAt: null, history: [] };

    const adsToAdd = opts.adsOverride || plan.ads;
    const daysToAdd = opts.daysOverride || plan.days;
    const expiresAt = new Date(Date.now() + daysToAdd * 86400000).toISOString();

    existing.adsRemaining = (existing.adsRemaining || 0) + adsToAdd;
    existing.totalGranted = (existing.totalGranted || 0) + adsToAdd;
    existing.planId = planId;
    existing.planName = plan.name;
    existing.expiresAt = expiresAt;
    existing.history = existing.history || [];
    existing.history.push({
      planId, planName: plan.name, ads: adsToAdd, days: daysToAdd,
      price: opts.price !== undefined ? opts.price : plan.price,
      grantedAt: new Date().toISOString(), expiresAt
    });

    allQuotas[userId] = existing;
    window.ZZZ.write('zzz_user_quotas', allQuotas);

    // Track anti-spam for free plans
    if (planId === 'new_user_free') {
      const user = window.ZZZ.read('zzz_users', []).find(u => u.id === userId);
      if (user && user.phone) {
        const registry = window.ZZZ.read('zzz_phone_registry', {});
        registry[user.phone] = { userId, hadFreeTrial: true, registeredAt: new Date().toISOString() };
        window.ZZZ.write('zzz_phone_registry', registry);
      }
    }

    // Record transaction if paid plan
    if (plan.price > 0 || opts.price > 0) {
      const txns = window.ZZZ.read('zzz_transactions', []);
      const user = window.ZZZ.read('zzz_users', []).find(u => u.id === userId)
                || window.ZZZ.getCurrentUser();
      txns.unshift({
        id: 'txn_' + Date.now(),
        userId,
        userName: user ? user.name : 'User',
        planId, planName: plan.name,
        amount: opts.price !== undefined ? opts.price : plan.price,
        status: 'success',
        date: Date.now()
      });
      window.ZZZ.write('zzz_transactions', txns);
    }

    return true;
  }

  // Deduct 1 ad from user's quota. Returns true if successful.
  function deductQuota(userId) {
    if (!userId) return false;
    const allQuotas = window.ZZZ.read('zzz_user_quotas', {});
    const q = allQuotas[userId];
    if (!q || q.adsRemaining <= 0) return false;
    q.adsRemaining -= 1;
    window.ZZZ.write('zzz_user_quotas', allQuotas);
    return true;
  }

  // Check if user can post (has quota)
  function canPost(userId) {
    if (!userId) return false;
    const q = getUserQuota(userId);
    return q && q.adsRemaining > 0;
  }

  // Anti-spam: check if phone number was already used for free trial
  function phoneHadFreeTrial(phone) {
    if (!phone) return false;
    const registry = window.ZZZ.read('zzz_phone_registry', {});
    return !!(registry[phone] && registry[phone].hadFreeTrial);
  }

  // Grant monthly free ad to all users (call this from admin or on login)
  function grantMonthlyFreeAd(userId) {
    const allQuotas = window.ZZZ.read('zzz_user_quotas', {});
    const q = allQuotas[userId];
    const now = new Date();
    const monthKey = now.getFullYear() + '-' + (now.getMonth() + 1);
    if (q && q.monthlyFreeGranted === monthKey) return false; // Already granted this month
    if (!q) { allQuotas[userId] = { adsRemaining: 0, totalGranted: 0, planId: null, planName: null, expiresAt: null, history: [] }; }
    allQuotas[userId].adsRemaining = (allQuotas[userId].adsRemaining || 0) + 1;
    allQuotas[userId].totalGranted = (allQuotas[userId].totalGranted || 0) + 1;
    allQuotas[userId].monthlyFreeGranted = monthKey;
    window.ZZZ.write('zzz_user_quotas', allQuotas);
    return true;
  }

  // Expose publicly
  window.ZZZ.Plans = {
    getAll: getPlans,
    getById: getPlanById,
    getUserQuota,
    assignPlan,
    deductQuota,
    canPost,
    phoneHadFreeTrial,
    grantMonthlyFreeAd,
    DEFAULT_PLANS
  };

  // Auto-grant monthly free ad on load
  document.addEventListener('DOMContentLoaded', () => {
    const user = window.ZZZ.getCurrentUser();
    if (user && user.id) {
      window.ZZZ.Plans.grantMonthlyFreeAd(user.id);
    }
  });
})();

window.escapeHtml = function(unsafe) {
  if (unsafe === null || unsafe === undefined) return '';
  return String(unsafe).replace(/[&<>"'`]/g, function (match) {
    switch (match) {
      case '&': return '&amp;';
      case '<': return '&lt;';
      case '>': return '&gt;';
      case '"': return '&quot;';
      case "'": return '&#39;';
      case '`': return '&#96;';
    }
  });
};
