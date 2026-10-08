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

    /* Sign out of every device: ends all of this account's sessions on the server */
    async function logoutAll() {
        const token = getToken();
        try {
            if (token) {
                await nativeFetch(`${API_BASE}/auth.php?action=logout_all`, {
                    method: "POST",
                    headers: { "Authorization": `Bearer ${token}`, "Cache-Control": "no-store" },
                    cache: "no-store"
                });
            }
        } catch (_) {}
        finally { clearSession(); }
    }

    /* ======================================================================
       ONE PLACE FOR EVERY API CALL ON THIS PAGE
       All the page scripts use fetch(), so wrapping it here adds three
       behaviours everywhere without changing each script:

       1. Duplicate protection (idempotency). Each "create" (POST) request
          gets an Idempotency-Key. Sending the SAME thing again — double
          click, retry after the connection dropped, reply that never
          arrived — reuses the same key, so the server returns the first
          result instead of creating a second purchase/listing/report.
          After a definite answer the key is forgotten, so a new, deliberate
          action gets a new key.

       2. Idle-timeout warning. The server says how many seconds of
          inactivity are left (X-Session-Idle-Remaining). Two minutes
          before the end we show a warning with "Stay signed in". This is
          only a display: the SERVER decides when the session ends.

       3. Background requests. Auto-refresh sets
          window.librowseBackgroundRequest = true so its requests carry
          X-Librowse-Background: 1 and don't count as user activity.
       ====================================================================== */
    const nativeFetch = window.fetch.bind(window);
    const IDEM_PREFIX = "librowseIdem:";
    const WARN_BEFORE = 120;            // seconds

    function isApiUrl(url) {
        try { return new URL(url, window.location.href).href.startsWith(new URL(API_BASE, window.location.href).href); }
        catch (_) { return false; }
    }
    async function digest(text) {
        try {
            if (window.crypto?.subtle) {
                const buf = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text));
                return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, "0")).join("").slice(0, 32);
            }
        } catch (_) {}
        let h = 2166136261;                                  // FNV-1a fallback (non-HTTPS pages)
        for (let i = 0; i < text.length; i++) { h ^= text.charCodeAt(i); h = Math.imul(h, 16777619); }
        return (h >>> 0).toString(16) + text.length.toString(16);
    }
    function newKey() {
        if (window.crypto?.randomUUID) return crypto.randomUUID();
        return Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 12);
    }

    window.fetch = async function (input, init = {}) {
        const url = typeof input === "string" ? input : (input && input.url) || "";
        if (!isApiUrl(url)) return nativeFetch(input, init);

        const method = String(init.method || "GET").toUpperCase();
        const headers = new Headers(init.headers || {});
        if (window.librowseBackgroundRequest) headers.set("X-Librowse-Background", "1");

        let slot = null;
        if (method === "POST" && !/auth\.php/.test(url) && !headers.has("Idempotency-Key")) {
            const body = typeof init.body === "string" ? init.body : "";
            slot = IDEM_PREFIX + await digest(`${getToken() || ""}|${url}|${body}`);
            let key = sessionStorage.getItem(slot);
            if (!key) { key = newKey(); sessionStorage.setItem(slot, key); }
            headers.set("Idempotency-Key", key);
        }

        let response;
        try {
            response = await nativeFetch(input, { ...init, headers });
        } catch (err) {
            // No answer at all: we don't know if the server got it, so KEEP the key.
            // Trying again is safe — the server will recognise the same request.
            throw err;
        }

        if (slot) {
            const stillRunning = response.status === 409 && response.headers.get("Idempotent-Replayed") !== "true"
                && (await response.clone().json().catch(() => ({}))).duplicate;
            if (response.status < 500 && !stillRunning) sessionStorage.removeItem(slot);
        }

        const idle = Number(response.headers.get("X-Session-Idle-Remaining"));
        if (Number.isFinite(idle) && idle > 0) scheduleIdleWarning(idle);

        if (response.status === 401) {
            const data = await response.clone().json().catch(() => ({}));
            if (data.reason === "idle_timeout") sessionStorage.setItem("librowseSignoutReason", "idle");
        }
        return response;
    };

    /* ---------- idle-timeout warning banner ---------- */
    let idleDeadline = 0, warnTimer = null, tickTimer = null;
    function scheduleIdleWarning(secondsLeft) {
        idleDeadline = Date.now() + secondsLeft * 1000;
        clearTimeout(warnTimer);
        hideIdleBanner();
        warnTimer = setTimeout(showIdleBanner, Math.max(0, (secondsLeft - WARN_BEFORE) * 1000));
    }
    function hideIdleBanner() {
        clearInterval(tickTimer);
        document.getElementById("librowse-idle")?.remove();
    }
    function showIdleBanner() {
        if (!getToken()) return;
        let bar = document.getElementById("librowse-idle");
        if (!bar) {
            bar = document.createElement("div");
            bar.id = "librowse-idle";
            bar.setAttribute("role", "alertdialog");
            bar.setAttribute("aria-live", "assertive");
            bar.style.cssText = "position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:9998;max-width:min(560px,calc(100% - 32px));background:#3b2a20;color:#fffaf3;border-radius:14px;padding:14px 18px;box-shadow:0 10px 30px rgba(0,0,0,.25);font:500 15px 'Nunito Sans',system-ui,sans-serif;display:flex;gap:12px;align-items:center;flex-wrap:wrap";
            bar.innerHTML = '<span id="librowse-idle-text" style="flex:1 1 240px"></span>'
                + '<button type="button" id="librowse-idle-stay" style="border:0;border-radius:999px;padding:9px 16px;background:#e3cfb6;color:#3b2a20;font:inherit;font-weight:700;cursor:pointer">Stay signed in</button>'
                + '<button type="button" id="librowse-idle-out" style="border:1px solid #e3cfb6;border-radius:999px;padding:9px 16px;background:transparent;color:#fffaf3;font:inherit;cursor:pointer">Sign out</button>';
            document.body.appendChild(bar);
            bar.querySelector("#librowse-idle-stay").addEventListener("click", async () => {
                // A real request = activity; the server resets the idle timer
                try {
                    const r = await window.fetch(`${API_BASE}/auth.php?action=validate`, {
                        headers: { "Authorization": `Bearer ${getToken()}` }, cache: "no-store"
                    });
                    if (r.status === 401) return expireNow();
                } catch (_) {}
                hideIdleBanner();
            });
            bar.querySelector("#librowse-idle-out").addEventListener("click", async () => {
                await logout();
                window.location.replace("login.html");
            });
            bar.querySelector("#librowse-idle-stay").focus();
        }
        const tick = () => {
            const left = Math.ceil((idleDeadline - Date.now()) / 1000);
            if (left <= 0) return expireNow();
            const m = Math.floor(left / 60), s = String(left % 60).padStart(2, "0");
            document.getElementById("librowse-idle-text").textContent =
                `For your security, you'll be signed out in ${m}:${s} because you've been inactive.`;
        };
        tick();
        clearInterval(tickTimer);
        tickTimer = setInterval(tick, 1000);
    }
    function expireNow() {
        hideIdleBanner();
        clearSession();
        sessionStorage.setItem("librowseSignoutReason", "idle");
        window.location.replace("login.html");
    }

    window.librowseAuth = { API_BASE, getToken, getUser, saveSession, clearSession, validateSession, requireRole, logout, logoutAll };
    window.librowseAuthReady = validateSession(true);

    window.addEventListener("pageshow", function (event) {
        // Only re-check when the page comes back from the back/forward cache
        // (e.g. after logging out); a normal load was already checked above.
        if (event.persisted) window.librowseAuthReady = validateSession(true);
    });
})();
