/**
 * ZipZapZoi Classifieds — Shared Utilities
 * Include this file in every page: <script src="js/utils.js"></script>
 */

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
        window.ZZZ.read('zipzapzoi_current_user', null) ||
        window.ZZZ.safeParse(sessionStorage.getItem('zzz_user'), null)
    );
};

window.ZZZ.setCurrentUser = function setCurrentUserCompat(user) {
    if (user) {
        localStorage.setItem('zzz_user', JSON.stringify(user));
        localStorage.setItem('zipzapzoi_current_user', JSON.stringify(user));
    } else {
        localStorage.removeItem('zzz_user');
        localStorage.removeItem('zipzapzoi_current_user');
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
