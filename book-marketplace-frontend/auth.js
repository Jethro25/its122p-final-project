/* LIBROWSE BOOK EXCHANGE - Frontend Authentication JavaScript */

function librowseApiBase() {
    if (window.LIBROWSE_API_BASE) return String(window.LIBROWSE_API_BASE).replace(/\/$/, '');
    const host = window.location.hostname || '127.0.0.1';
    const port = window.location.port;
    if (port === '8000') {
        const protocol = window.location.protocol === 'https:' ? 'https:' : 'http:';
        return `${protocol}//${host}:8000/api`;
    }
    return '/api';
}
const API_BASE = librowseApiBase();
const SESSION_TOKEN_KEY = "librowseSessionToken";
const SESSION_USER_KEY = "librowseCurrentUser";

/**
 * Built-in mock customer accounts for offline/demo resilience
 * matching the SQL seed in book-marketplace-backend/sql/queries.sql
 */

/* ==========================================================================
   API REQUEST HELPER
   ========================================================================== */

/**
 * Sends asynchronous HTTP requests to the PHP REST backend
 * @param {string} endpoint - The relative API path (e.g., 'user.php')
 * @param {object} options - Fetch options (method, headers, body)
 * @returns {Promise<any>}
 */
async function apiRequest(endpoint, options = {}) {
    const url = `${API_BASE}/${endpoint}`;

    try {
        const token = sessionStorage.getItem(SESSION_TOKEN_KEY);
        const headers = new Headers(options.headers || {});
        if (token) headers.set("Authorization", `Bearer ${token}`);
        headers.set("Cache-Control", "no-store");
        const response = await fetch(url, { ...options, headers, cache: "no-store" });
        const text = await response.text();

        let data;
        try {
            data = text ? JSON.parse(text) : {};
        } catch {
            throw new Error("The backend server did not return valid JSON.");
        }

        if (response.status === 401) {
            sessionStorage.removeItem(SESSION_TOKEN_KEY);
            sessionStorage.removeItem(SESSION_USER_KEY);
            localStorage.removeItem(SESSION_USER_KEY);
            throw new Error("Your session has expired. Please sign in again.");
        }

        if (!response.ok) {
            throw new Error(data.error || `Server returned error status ${response.status}`);
        }

        return data;
    } catch (err) {
        // Handle network/connection failure when PHP server is not running
        if (err.name === "TypeError" && err.message.includes("fetch")) {
            throw new Error(
                "Cannot connect to the backend at " + API_BASE +
                ". Please ensure your PHP server is running (e.g., php -S 127.0.0.1:8000 -t book-marketplace-backend)."
            );
        }
        throw err;
    }
}

/**
 * Searches for users by specific column (username or email)
 * @param {string} field - 'username' or 'email'
 * @param {string} value - Search query string
 * @returns {Promise<Array>}
 */
async function findUsersByField(field, value) {
    const encoded = encodeURIComponent(value);
    const users = await apiRequest(`user.php?${field}=${encoded}&limit=50&offset=0`);
    return Array.isArray(users) ? users : [];
}

/* ==========================================================================
   UI NOTIFICATIONS & FEEDBACK
   ========================================================================== */

/**
 * Displays status, success, or error messages in the auth alert banner
 * @param {string} message - Text to show
 * @param {"error"|"success"|"info"} type - Message severity
 */
function showMessage(message, type = "info") {
    const messageEl = document.getElementById("auth-message");
    if (!messageEl) return;

    if (!message) {
        messageEl.style.display = "none";
        messageEl.textContent = "";
        return;
    }

    messageEl.className = "auth-message";
    if (type === "error") {
        messageEl.classList.add("auth-error");
        messageEl.innerHTML = `
            <svg style="width:16px;height:16px;flex-shrink:0;fill:currentColor;" viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
            </svg>
            <span>${escapeHTML(message)}</span>
        `;
    } else if (type === "success") {
        messageEl.classList.add("auth-success");
        messageEl.innerHTML = `
            <svg style="width:16px;height:16px;flex-shrink:0;fill:currentColor;" viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
            </svg>
            <span>${escapeHTML(message)}</span>
        `;
    } else {
        messageEl.classList.add("auth-info");
        messageEl.innerHTML = `
            <svg style="width:16px;height:16px;flex-shrink:0;fill:currentColor;" viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
            </svg>
            <span>${escapeHTML(message)}</span>
        `;
    }

    messageEl.style.display = "flex";
}

/**
 * Escapes HTML characters to prevent XSS in client-rendered messages
 */
function escapeHTML(str) {
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

/* ==========================================================================
   SESSION STORAGE
   ========================================================================== */

/**
 * Saves current user session details to localStorage
 * Matches the format expected by script.js (currentUser)
 * @param {object} user
 */
function saveCurrentUser(user, token) {
    sessionStorage.setItem(SESSION_USER_KEY, JSON.stringify(user));
    sessionStorage.setItem(SESSION_TOKEN_KEY, token);
    localStorage.removeItem(SESSION_USER_KEY);
}

function getStoredUser() {
    try { return JSON.parse(sessionStorage.getItem(SESSION_USER_KEY) || 'null'); }
    catch { return null; }
}

async function clearCurrentUser() {
    const token = sessionStorage.getItem(SESSION_TOKEN_KEY);
    try {
        if (token) {
            await fetch(`${API_BASE}/auth.php?action=logout`, {
                method: "POST",
                headers: { "Authorization": `Bearer ${token}`, "Cache-Control": "no-store" },
                cache: "no-store"
            });
        }
    } catch (_) {}
    sessionStorage.removeItem(SESSION_TOKEN_KEY);
    sessionStorage.removeItem(SESSION_USER_KEY);
    localStorage.removeItem(SESSION_USER_KEY);
}

/* ==========================================================================
   LOGIN HANDLER
   ========================================================================== */

/**
 * Processes Customer login form submission
 * @param {Event} event
 */
async function handleLogin(event) {
    event.preventDefault();
    const identifier = document.getElementById("login-identifier")?.value.trim() || "";
    const password = document.getElementById("login-password")?.value || "";
    const submitBtn = document.getElementById("login-submit-btn");

    if (!identifier || !password) {
        showMessage("Please enter both your email/username and password.", "error");
        return;
    }
    if (submitBtn) { submitBtn.disabled = true; submitBtn.querySelector("span").textContent = "Signing in..."; }
    showMessage("Authenticating with Librowse...", "info");

    try {
        const response = await fetch(`${API_BASE}/auth.php?action=login`, {
            method: "POST",
            headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
            cache: "no-store",
            body: JSON.stringify({ identifier, password })
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || "Unable to sign in.");
        saveCurrentUser(data.user, data.token);
        const destination = data.user.role === "Admin" ? "admin.html" : data.user.role === "Staff" ? "staff.html" : "customer-dashboard.html";
        showMessage(`Welcome back, ${data.user.username}! Redirecting...`, "success");
        setTimeout(() => window.location.replace(destination), 250);
    } catch (error) {
        const detail = error instanceof TypeError
            ? `Unable to reach ${API_BASE}. Check that the PHP API is running and that this page was opened over HTTP/HTTPS (not file://).`
            : error.message;
        showMessage(`Unable to sign in: ${detail}`, "error");
    } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.querySelector("span").textContent = "Sign In"; }
    }
}

/* ==========================================================================
   REGISTRATION HANDLER
   ========================================================================== */

/**
 * Processes new Customer account creation
 * @param {Event} event
 */
async function handleRegister(event) {
    event.preventDefault();
    const username = document.getElementById("register-username")?.value.trim() || "";
    const email = document.getElementById("register-email")?.value.trim().toLowerCase() || "";
    const password = document.getElementById("register-password")?.value || "";
    const confirmPassword = document.getElementById("register-confirm-password")?.value || "";
    const submitBtn = document.getElementById("register-submit-btn");

    if (!username || !email || !password || !confirmPassword) return showMessage("Please fill in all registration fields.", "error");
    if (!/^[a-zA-Z0-9_]{3,50}$/.test(username)) return showMessage("Username must be 3-50 characters and contain only letters, numbers, and underscores.", "error");
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return showMessage("Please provide a valid email address.", "error");
    if (password.length < 6) return showMessage("Password must be at least 6 characters long.", "error");
    if (password !== confirmPassword) return showMessage("Passwords do not match.", "error");

    if (submitBtn) { submitBtn.disabled = true; submitBtn.querySelector("span").textContent = "Creating Account..."; }
    try {
        const response = await fetch(`${API_BASE}/auth.php?action=register`, {
            method: "POST",
            headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
            cache: "no-store",
            body: JSON.stringify({ username, email, password })
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || "Registration failed.");
        saveCurrentUser(data.user, data.token);
        showMessage("Account created successfully! Redirecting...", "success");
        setTimeout(() => window.location.replace("customer-dashboard.html"), 250);
    } catch (error) {
        showMessage(`Registration failed: ${error.message}`, "error");
    } finally {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.querySelector("span").textContent = "Create Account"; }
    }
}

/* ==========================================================================
   PAGE INITIALIZATION & EVENT BINDINGS
   ========================================================================== */

document.addEventListener("DOMContentLoaded", async () => {
    localStorage.removeItem(SESSION_USER_KEY);
    const currentUser = getStoredUser();
    const sessionCard = document.getElementById("session-active-card");
    const sessionText = document.getElementById("session-active-text");
    const sessionLogoutBtn = document.getElementById("session-logout-btn");

    if (currentUser && sessionStorage.getItem(SESSION_TOKEN_KEY)) {
        try {
            const token = sessionStorage.getItem(SESSION_TOKEN_KEY);
            const response = await fetch(`${API_BASE}/auth.php?action=validate`, { headers: { "Authorization": `Bearer ${token}` }, cache: "no-store" });
            if (!response.ok) throw new Error("Session invalid");
            const data = await response.json();
            saveCurrentUser(data.user, token);
            if (sessionCard && sessionText) {
                sessionCard.style.display = "block";
                sessionText.innerHTML = `You are currently signed in as <strong>${escapeHTML(data.user.username)}</strong> (Role: ${escapeHTML(data.user.role)}).`;
            }
            const link = document.getElementById("session-continue-link");
            if (link) {
                const role = String(data.user.role || '').toLowerCase();
                link.href = role === 'admin' ? 'admin.html' : role === 'staff' ? 'staff.html' : 'customer-dashboard.html';
                link.textContent = role === 'admin' || role === 'staff' ? 'Open Management Dashboard' : 'Go to Marketplace';
            }
        } catch (_) { await clearCurrentUser(); }
    }

    sessionLogoutBtn?.addEventListener("click", async () => {
        await clearCurrentUser();
        if (sessionCard) sessionCard.style.display = "none";
        showMessage("Signed out. You may now log in with another account.", "info");
    });

    document.getElementById("login-form")?.addEventListener("submit", handleLogin);
    document.getElementById("register-form")?.addEventListener("submit", handleRegister);

    document.querySelectorAll(".toggle-password-btn").forEach(button => button.addEventListener("click", () => {
        const input = document.getElementById(button.dataset.target);
        if (input) input.type = input.type === "password" ? "text" : "password";
    }));

    // Demo convenience buttons still fill the login form, but authentication is now always server-side.
    document.querySelectorAll(".demo-pill").forEach(button => button.addEventListener("click", () => {
        const username = document.getElementById("login-identifier");
        const password = document.getElementById("login-password");
        if (username) username.value = button.dataset.username || "";
        if (password) password.value = button.dataset.pass || "password";
    }));
});
