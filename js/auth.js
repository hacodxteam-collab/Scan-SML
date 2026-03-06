// ===== Auth Utility =====
const API_BASE = 'api';

async function checkSession() {
    try {
        const res = await fetch(`${API_BASE}/auth.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check' }),
        });
        const data = await res.json();
        if (data.logged_in) return data.user;
        return null;
    } catch {
        return null;
    }
}

async function requireAuth(allowedRoles = []) {
    const user = await checkSession();
    if (!user) {
        window.location.href = 'index.html';
        return null;
    }
    if (allowedRoles.length > 0 && !allowedRoles.includes(user.role)) {
        window.location.href = 'index.html';
        return null;
    }
    return user;
}

async function logout() {
    try {
        await fetch(`${API_BASE}/auth.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'logout' }),
        });
    } catch { }
    window.location.href = 'index.html';
}

// ===== Auto Logout on Idle =====
let inactivityTimer;
const INACTIVITY_LIMIT_MS = 15 * 60 * 1000; // 15 minutes

function resetInactivityTimer() {
    clearTimeout(inactivityTimer);
    inactivityTimer = setTimeout(() => {
        // Auto logout if idle limit reached
        logout();
    }, INACTIVITY_LIMIT_MS);
}

function initAutoLogout() {
    // Listen for user interactions to reset timer
    ['mousemove', 'mousedown', 'keypress', 'touchstart', 'scroll'].forEach(evt => {
        document.addEventListener(evt, resetInactivityTimer, true);
    });
    // Start timer initially
    resetInactivityTimer();
}

// Call initAutoLogout when requireAuth succeeds
const originalRequireAuth = requireAuth;
requireAuth = async function (allowedRoles = []) {
    const user = await originalRequireAuth(allowedRoles);
    if (user) {
        initAutoLogout();
    }
    return user;
};

// Toast notifications
function showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<span>${type === 'success' ? '✅' : type === 'error' ? '❌' : type === 'warning' ? '⚠️' : 'ℹ️'}</span> ${message}`;
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(100px)';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

// API helper
async function apiCall(endpoint, method = 'GET', body = null) {
    const options = {
        method,
        headers: { 'Content-Type': 'application/json' },
    };
    if (body) options.body = JSON.stringify(body);

    const res = await fetch(`${API_BASE}/${endpoint}`, options);
    const data = await res.json();

    if (!res.ok) {
        throw new Error(data.error || 'API Error');
    }
    return data;
}

// Format date/time
function formatDateTime(str) {
    if (!str) return '-';
    const d = new Date(str);
    return d.toLocaleDateString('th-TH', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }) + ' ' + d.toLocaleTimeString('th-TH', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

// Escape HTML
function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
