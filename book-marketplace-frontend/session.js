/* Shared server-session guard for all authenticated Librowse pages. */
(function () {
    const API_BASE = (window.LIBROWSE_API_BASE ? String(window.LIBROWSE_API_BASE).replace(/\/$/, "") : (window.location.port === "8000" ? `${window.location.protocol === "https:" ? "https:" : "http:"}//${window.location.hostname || "127.0.0.1"}:8000/api` : "/api"));
    const TOKEN_KEY = "librowseSessionToken";
    const USER_KEY = "librowseCurrentUser";

    document.documentElement.classList.add("librowse-auth-pending");
    const style = document.createElement("style");
    style.textContent = [
        'html.librowse-auth-pending body{visibility:hidden!important}',
        'html.librowse-auth-ready body{visibility:visible!important}',
        '#librowse-boot{position:fixed;inset:0;z-index:9999;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;background:#f4eadd;color:#6b5040;font:600 15px "Nunito Sans",system-ui,sans-serif;text-align:center;padding:24px}',
        'html.librowse-auth-ready #librowse-boot{display:none}',
        '#librowse-boot .lb-spin{width:34px;height:34px;border:3px solid #e3cfb6;border-top-color:#9a7458;border-radius:50%;animation:lbspin .8s linear infinite}',
        '#librowse-boot button{margin:0;padding:10px 22px;border:0;border-radius:999px;background:#9a7458;color:#fffaf3;font:inherit;cursor:pointer}',
        '@keyframes lbspin{to{transform:rotate(360deg)}}',
        '@media (prefers-reduced-motion:reduce){#librowse-boot .lb-spin{animation-duration:2.4s}}'
    ].join('');
    document.head.appendChild(style);

    /* Visible "loading" screen while the session is checked (the page itself
       stays hidden so nobody sees content they aren't allowed to see). */
    function showBoot(message, withRetry) {
        let boot = document.getElementById('librowse-boot');
        if (!boot) {
            boot = document.createElement('div');
            boot.id = 'librowse-boot';
            boot.setAttribute('role', 'status');
            boot.setAttribute('aria-live', 'polite');
            document.documentElement.appendChild(boot);
        }
        boot.innerHTML = withRetry
            ? '<span></span><button type="button">Try again</button>'
            : '<div class="lb-spin" aria-hidden="true"></div><span></span>';
        boot.querySelector('span').textContent = message;
        boot.querySelector('button')?.addEventListener('click', () => {
            window.librowseAuthReady = validateSession(true);
        });
    }
    showBoot('Opening Librowse…');

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
        showBoot('Opening Librowse…');
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
            if (response.status === 401 || response.status === 403) throw new Error('Invalid session');
            if (!response.ok) throw new TypeError('Server unavailable');
            const data = await response.json();
            if (!data.authenticated || !data.user) throw new Error('Invalid session');
            sessionStorage.setItem(USER_KEY, JSON.stringify(data.user));
            document.documentElement.classList.remove("librowse-auth-pending");
            document.documentElement.classList.add("librowse-auth-ready");
            return data.user;
        } catch (error) {
            // Can't reach the server: keep the session and offer a retry
            // instead of logging the person out.
            if (error instanceof TypeError) {
                showBoot("We couldn't reach Librowse. Check your connection and try again.", true);
                return null;
            }
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

    window.addEventListener("pageshow", function (event) {
        // Only re-check when the page comes back from the back/forward cache
        // (e.g. after logging out); a normal load was already checked above.
        if (event.persisted) window.librowseAuthReady = validateSession(true);
    });
})();
