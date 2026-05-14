/**
 * ZipZapZoi Classifieds — Shared Utilities
 * Include this file in every page: <script src="js/utils.js"></script>
 */

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
    return JSON.parse(localStorage.getItem('zzz_user') || sessionStorage.getItem('zzz_user') || 'null');
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
    localStorage.removeItem('zzz_user');
    sessionStorage.removeItem('zzz_user');
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
