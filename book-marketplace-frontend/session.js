/* Shared server-session guard for all authenticated Librowse pages. */
(function () {
    const API_BASE = (window.LIBROWSE_API_BASE ? String(window.LIBROWSE_API_BASE).replace(/\/$/, "") : (window.location.port === "8000" ? `${window.location.protocol === "https:" ? "https:" : "http:"}//${window.location.hostname || "127.0.0.1"}:8000/api` : "/api"));
    const TOKEN_KEY = "librowseSessionToken";
    const USER_KEY = "librowseCurrentUser";

    document.documentElement.classList.add("librowse-auth-pending");
    const style = document.createElement("style");
    style.textContent = 'html.librowse-auth-pending body{visibility:hidden!important}html.librowse-auth-ready body{visibility:visible!important}';
    document.head.appendChild(style);

    function getToken() { return sessionStorage.getItem(TOKEN_KEY); }
    function getUser() {
        try { return JSON.parse(sessionStorage.getItem(USER_KEY) || 'null'); }
        catch (_) { return null; }
    }
    function saveSession(token, user) {
        sessionStorage.setItem(TOKEN_KEY, token);
        sessionStorage.setItem(USER_KEY, JSON.stringify(user));
        localStorage.removeItem(USER_KEY);
    }
    function clearSession() {
        sessionStorage.removeItem(TOKEN_KEY);
        sessionStorage.removeItem(USER_KEY);
        localStorage.removeItem(USER_KEY);
    }

    async function validateSession(redirect = true) {
        document.documentElement.classList.add("librowse-auth-pending");
        document.documentElement.classList.remove("librowse-auth-ready");
        const token = getToken();
        if (!token) {
            clearSession();
            if (redirect) window.location.replace("login.html");
            return null;
        }
        try {
            const response = await fetch(`${API_BASE}/auth.php?action=validate`, {
                method: "GET",
                headers: { "Authorization": `Bearer ${token}`, "Cache-Control": "no-store" },
                cache: "no-store"
            });
            if (!response.ok) throw new Error('Invalid session');
            const data = await response.json();
            if (!data.authenticated || !data.user) throw new Error('Invalid session');
            sessionStorage.setItem(USER_KEY, JSON.stringify(data.user));
            document.documentElement.classList.remove("librowse-auth-pending");
            document.documentElement.classList.add("librowse-auth-ready");
            return data.user;
        } catch (_) {
            clearSession();
            if (redirect) window.location.replace("login.html");
            return null;
        }
    }

    async function requireRole(expected) {
        const allowed = Array.isArray(expected) ? expected : [expected];
        const user = await validateSession(true);
        if (!user) return null;
        const role = String(user.role || '').toLowerCase();
        if (!allowed.includes(role)) {
            window.location.replace(role === 'admin' ? 'admin.html' : role === 'staff' ? 'staff.html' : 'customer-dashboard.html');
            return null;
        }
        return user;
    }

    async function logout() {
        const token = getToken();
        try {
            if (token) {
                await fetch(`${API_BASE}/auth.php?action=logout`, {
                    method: "POST",
                    headers: { "Authorization": `Bearer ${token}`, "Cache-Control": "no-store" },
                    cache: "no-store"
                });
            }
        } catch (_) {}
        finally { clearSession(); }
    }

    window.librowseAuth = { API_BASE, getToken, getUser, saveSession, clearSession, validateSession, requireRole, logout };
    window.librowseAuthReady = validateSession(true);

    window.addEventListener("pageshow", function () {
        // Handles bfcache/back-button restores after logout.
        window.librowseAuthReady = validateSession(true);
    });
})();
