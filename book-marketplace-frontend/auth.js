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
/* ==========================================================================
   LOGIN RATE LIMITING (display side)
   The server is the source of truth: it counts wrong passwords and locks the
   account in the database on the 4th one. This code only explains what is
   happening and remembers locked accounts so a refresh still shows the lock.
   ========================================================================== */
const LOCKED_ACCOUNTS_KEY = "librowseLockedAccounts";
const LAST_LOCKED_KEY = "librowseLastLockedAccount";

function getLockedAccounts() {
    try { return JSON.parse(localStorage.getItem(LOCKED_ACCOUNTS_KEY) || "[]"); }
    catch (_) { return []; }
}
function isAccountMarkedLocked(identifier) {
    return !!identifier && getLockedAccounts().includes(identifier.toLowerCase().trim());
}
function markAccountLocked(identifier, username) {
    const list = new Set(getLockedAccounts());
    [identifier, username].filter(Boolean).forEach(v => list.add(String(v).toLowerCase().trim()));
    try {
        localStorage.setItem(LOCKED_ACCOUNTS_KEY, JSON.stringify([...list]));
        localStorage.setItem(LAST_LOCKED_KEY, identifier);
    } catch (_) {}
}
function forgetLockedAccount(identifier) {
    const key = String(identifier || "").toLowerCase().trim();
    try {
        localStorage.setItem(LOCKED_ACCOUNTS_KEY, JSON.stringify(getLockedAccounts().filter(v => v !== key)));
        if ((localStorage.getItem(LAST_LOCKED_KEY) || "").toLowerCase() === key) localStorage.removeItem(LAST_LOCKED_KEY);
    } catch (_) {}
}

function attemptDots(used, max) {
    let dots = "";
    for (let i = 1; i <= max; i++) dots += `<span class="attempt-dot${i <= used ? " used" : ""}"></span>`;
    return `<span class="attempt-dots" aria-hidden="true">${dots}</span>`;
}

function showAttemptWarning(used, max, finalWarning) {
    const messageEl = document.getElementById("auth-message");
    if (!messageEl) return;
    const left = Math.max(max - used, 0);
    messageEl.className = finalWarning ? "auth-message auth-warning" : "auth-message auth-error";
    messageEl.style.display = "flex";
    messageEl.innerHTML = finalWarning
        ? `<div><strong>Incorrect password — last chance.</strong>
             You have used all ${max} attempts. <strong>One more incorrect password will lock your account</strong>,
             and only an administrator can unlock it.
             <div class="attempt-row">${attemptDots(used, max)} Attempt ${used} of ${max}</div></div>`
        : `<div><strong>Incorrect password.</strong>
             You have ${left} attempt${left === 1 ? "" : "s"} left before the final warning.
             After ${max} incorrect attempts, the next one locks your account.
             <div class="attempt-row">${attemptDots(used, max)} Attempt ${used} of ${max}</div></div>`;
}

function setLoginLocked(locked, identifier) {
    const submitBtn = document.getElementById("login-submit-btn");
    const messageEl = document.getElementById("auth-message");
    if (!submitBtn) return;
    const label = submitBtn.querySelector("span");

    if (!locked) {
        submitBtn.disabled = false;
        submitBtn.classList.remove("btn-locked");
        if (label) label.textContent = "Sign In";
        return;
    }

    submitBtn.disabled = true;
    submitBtn.classList.add("btn-locked");
    if (label) label.textContent = "Account locked";
    if (messageEl) {
        messageEl.className = "auth-message auth-message-locked";
        messageEl.style.display = "block";
        messageEl.innerHTML = `
            <strong class="lock-title">This account is locked</strong>
            <span>${escapeHTML(identifier || "This account")} was locked after too many incorrect password attempts.
            For your security, signing in is blocked until an <strong>administrator unlocks the account</strong>.
            Please contact the Librowse admin team.</span>
            <button type="button" class="lock-retry-link" id="lock-retry-link">An admin already unlocked it? Try again</button>`;
        document.getElementById("lock-retry-link")?.addEventListener("click", () => {
            forgetLockedAccount(identifier);
            setLoginLocked(false);
            showMessage("Enter your password to try again. If the account is still locked, you will see this notice again.", "info");
            document.getElementById("login-password")?.focus();
        });
    }
}

/* Re-show the lock after a refresh, and when a locked username is typed in */
function initLoginLockState() {
    const idEl = document.getElementById("login-identifier");
    if (!idEl) return;
    const last = localStorage.getItem(LAST_LOCKED_KEY);
    if (last && isAccountMarkedLocked(last)) {
        idEl.value = last;
        setLoginLocked(true, last);
    }
    idEl.addEventListener("input", () => {
        const value = idEl.value.trim();
        if (isAccountMarkedLocked(value)) {
            setLoginLocked(true, value);
        } else if (document.getElementById("login-submit-btn")?.classList.contains("btn-locked")) {
            setLoginLocked(false);
            showMessage("", "info");
        }
    });
}

async function handleLogin(event) {
    event.preventDefault();
    const identifier = document.getElementById("login-identifier")?.value.trim() || "";
    const password = document.getElementById("login-password")?.value || "";
    const submitBtn = document.getElementById("login-submit-btn");

    if (isAccountMarkedLocked(identifier)) { setLoginLocked(true, identifier); return; }
    if (!identifier || !password) {
        showMessage("Please enter both your email/username and password.", "error");
        return;
    }
    if (submitBtn) { submitBtn.disabled = true; submitBtn.querySelector("span").textContent = "Signing in..."; }
    showMessage("Authenticating with Librowse...", "info");

    let lockedNow = false;
    try {
        const response = await fetch(`${API_BASE}/auth.php?action=login`, {
            method: "POST",
            headers: { "Content-Type": "application/json", "Cache-Control": "no-store" },
            cache: "no-store",
            body: JSON.stringify({ identifier, password })
        });
        const data = await response.json();

        if (data.locked) {
            lockedNow = true;
            markAccountLocked(identifier, data.username);
            setLoginLocked(true, identifier);
            return;
        }
        if (!response.ok) {
            if (data.attempts_used) {
                showAttemptWarning(data.attempts_used, data.max_attempts || 3, !!data.final_warning);
                const pw = document.getElementById("login-password");
                if (pw) { pw.value = ""; pw.focus(); }
                return;
            }
            throw new Error(data.error || "Unable to sign in.");
        }

        forgetLockedAccount(identifier);
        saveCurrentUser(data.user, data.token);
        const destination = data.user.role === "Admin" ? "admin.html" : data.user.role === "Staff" ? "staff.html" : "customer-dashboard.html";
        showMessage(`Welcome back, ${data.user.username}! Redirecting...`, "success");
        setTimeout(() => window.location.replace(destination), 250);
    } catch (error) {
        const detail = error instanceof TypeError
            ? `Unable to reach ${API_BASE}. Check your connection and try again.`
            : error.message;
        showMessage(`Unable to sign in: ${detail}`, "error");
    } finally {
        if (submitBtn && !lockedNow && !submitBtn.classList.contains("btn-locked")) {
            submitBtn.disabled = false;
            submitBtn.querySelector("span").textContent = "Sign In";
        }
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
    initLoginLockState();
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
