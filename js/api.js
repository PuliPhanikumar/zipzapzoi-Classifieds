/**
 * ZipZapZoi Classifieds — JavaScript API Client
 * Replaces all localStorage data reads/writes with real MySQL API calls.
 * Include on every page: <script src="js/api.js"></script>
 * All methods return a Promise. Use: const result = await API.method();
 */

const API = (() => {
  const BASE = '/api';

  // ── Core fetch helper ─────────────────────────────────────────
  async function req(endpoint, options = {}) {
    const defaults = {
      credentials: 'include',  // send session cookie automatically
      headers: {},
    };
    const merged = { ...defaults, ...options };
    if (merged.body && typeof merged.body === 'object') {
      merged.headers['Content-Type'] = 'application/json';
      merged.body = JSON.stringify(merged.body);
    }
    
    // Add cache-buster to bypass Hostinger LiteSpeed caching for GET requests
    if (!merged.method || merged.method.toUpperCase() === 'GET') {
      endpoint += (endpoint.includes('?') ? '&' : '?') + '_t=' + Date.now();
    }
    const url = `${BASE}/${endpoint}`;

    try {
      const res  = await fetch(url, merged);
      const data = await res.json();
      return data; // { success, data } or { success, error }
    } catch (err) {
      console.error('[API] Network error:', err);
      return { success: false, error: 'Network error. Please check your connection.' };
    }
  }

  const get  = (ep)        => req(ep,         { method: 'GET' });
  const post = (ep, body)  => req(ep,         { method: 'POST',   body });
  const put  = (ep, body)  => req(ep,         { method: 'PUT',    body });
  const del  = (ep)        => req(ep,         { method: 'DELETE' });

  // ── Auth ──────────────────────────────────────────────────────
  const Auth = {
    /** Step 1 of register: validate + get OTP from server to send via EmailJS */
    register: (data) => post('auth.php?action=register', data),

    /** Step 2 of register: verify OTP → creates account + session */
    verifyOtp: (email, otp, action = 'register') =>
      post('auth.php?action=verify_otp', { email, otp, action }),

    /** Step 1 of login: check credentials → get OTP */
    login: (email, password) =>
      post('auth.php?action=login', { email, password }),

    /** Forgot password: get reset link to send via EmailJS */
    forgotPassword: (email) =>
      post('auth.php?action=forgot_password', { email }),

    /** Reset password using token from email link */
    resetPassword: (token, newPassword) =>
      post('auth.php?action=reset_password', { token, new_password: newPassword }),

    /** Get current logged-in user (checks session cookie) */
    me: () => get('auth.php?action=me'),

    /** Logout (destroys session) */
    logout: () => post('auth.php?action=logout'),

    /** Request OTP for sensitive action (password change etc.) */
    requestSensitiveOtp: () => post('auth.php?action=request_sensitive_otp'),

    /** Verify sensitive action OTP */
    verifySensitiveOtp: (otp) =>
      post('auth.php?action=verify_sensitive_otp', { otp }),
  };

  // ── Listings ──────────────────────────────────────────────────
  const Listings = {
    /**
     * Get listings with optional filters:
     * { category, subcategory, city, state, search,
     *   min_price, max_price, sort, status, user_id, page, limit }
     */
    getAll: (filters = {}) => {
      const params = new URLSearchParams(filters).toString();
      return get(`listings.php${params ? '?' + params : ''}`);
    },

    /** Get single listing by ID */
    getOne: (id) => get(`listings.php?id=${id}`),

    /** Create a new listing */
    create: (data) => post('listings.php', data),

    /** Update a listing */
    update: (id, data) => put(`listings.php?id=${id}`, data),

    /** Delete a listing */
    delete: (id) => del(`listings.php?id=${id}`),
  };

  // ── Users ─────────────────────────────────────────────────────
  const Users = {
    /** Get my profile (includes quota info) */
    me: () => get('users.php?id=me'),

    /** Get public profile of any user */
    get: (id) => get(`users.php?id=${id}`),

    /** Update my profile { name, phone, city, state, avatar } */
    update: (data) => put('users.php', data),
  };

  // ── Favorites ─────────────────────────────────────────────────
  const Favorites = {
    /** Get my saved listings */
    getAll: () => get('favorites.php'),

    /** Toggle favorite (add if not saved, remove if saved) */
    toggle: (listingId) =>
      post('favorites.php?action=toggle', { listing_id: listingId }),

    /** Add to favorites */
    add: (listingId) => post('favorites.php', { listing_id: listingId }),

    /** Remove from favorites */
    remove: (listingId) => del(`favorites.php?listing_id=${listingId}`),
  };

  // ── Messages ──────────────────────────────────────────────────
  const Messages = {
    /** Get my inbox (grouped by thread) */
    getInbox: () => get('messages.php'),

    /** Get messages in a thread with specific user */
    getThread: (userId, listingId = null) => {
      const qs = listingId ? `&listing_id=${listingId}` : '';
      return get(`messages.php?thread=${userId}${qs}`);
    },

    /** Send a message */
    send: (toUserId, body, subject = '', listingId = null) =>
      post('messages.php', { to_user_id: toUserId, body, subject, listing_id: listingId }),

    /** Mark message as read */
    markRead: (id) => put(`messages.php?id=${id}`),

    /** Get unread message count */
    unreadCount: () => get('messages.php?action=unread_count'),
  };

  // ── Uploads ───────────────────────────────────────────────────
  const Uploads = {
    /**
     * Upload an image file
     * @param {File} file - A File object from <input type="file">
     * @returns { success, data: { url, filename } }
     */
    image: async (file) => {
      const formData = new FormData();
      formData.append('image', file);
      const res = await fetch(`${BASE}/uploads.php`, {
        method: 'POST',
        credentials: 'include',
        body: formData,  // No Content-Type header — browser sets it with boundary
      });
      return res.json();
    },
  };

  // ── Transactions ──────────────────────────────────────────────
  const Transactions = {
    /** Get my transaction history */
    getAll: () => get('transactions.php'),

    /**
     * Record a payment after Razorpay confirms success
     * @param {{ plan_id, plan_name, amount, razorpay_payment_id, razorpay_order_id, ads, days }} data
     */
    record: (data) => post('transactions.php', data),
  };

  // ── Helper: show error from API response as toast ─────────────
  function handleError(result, fallback = 'Something went wrong.') {
    const msg = result?.error || fallback;
    if (typeof showToast === 'function') showToast(msg, 'error');
    else alert(msg);
  }

  // ── Helper: check if user is logged in (fast, no network call) ─
  // Note: for definitive auth check, use Auth.me() instead
  function isLoggedIn() {
    // Checks for session cookie existence (does not verify on server)
    return document.cookie.includes('zzz_session');
  }

  // ── Public API surface ────────────────────────────────────────
  return { Auth, Listings, Users, Favorites, Messages, Uploads, Transactions, handleError, isLoggedIn };
})();

// Make globally available
window.API = API;

// ── Auto-update unread badge using real API ───────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  if (!API.isLoggedIn()) return;
  try {
    const res = await API.Messages.unreadCount();
    if (res.success) {
      const count = res.data.count;
      document.querySelectorAll('.zzz-unread-badge').forEach(el => {
        if (count > 0) { el.textContent = count > 9 ? '9+' : count; el.style.display = 'flex'; }
        else el.style.display = 'none';
      });
    }
  } catch (_) { /* silent fail */ }
});
