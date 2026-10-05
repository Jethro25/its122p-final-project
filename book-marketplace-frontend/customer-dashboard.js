/* LIBROWSE - Customer Dashboard
   Loads everything at the same time, shows shimmering placeholders while it
   loads, and escapes book titles before they go on the page. */
(async function () {
    if (window.librowseAuthReady) {
        const user = await window.librowseAuthReady;
        if (!user) return;
    }

    const API = window.LIBROWSE_API_BASE
        ? String(window.LIBROWSE_API_BASE).replace(/\/$/, "")
        : (window.location.port === "8000"
            ? `${window.location.protocol === "https:" ? "https:" : "http:"}//${window.location.hostname || "127.0.0.1"}:8000/api`
            : "/api");

    const esc = v => String(v ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
    const statIds = ["stat-total", "stat-active", "stat-sold", "stat-pending-listing",
                     "stat-tx-total", "stat-tx-pending", "stat-tx-completed", "stat-tx-cancelled",
                     "stat-refund-pending", "stat-refund-approved", "stat-refund-other",
                     "stat-report-pending", "stat-report-review", "stat-report-resolved"];

    function setStat(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.remove("stat-loading");
        el.textContent = (value === undefined || value === null) ? "—" : String(value);
    }
    statIds.forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.textContent = " "; el.classList.add("stat-loading"); }
    });

    let me = null;
    try { me = JSON.parse(sessionStorage.getItem("librowseCurrentUser") || "null"); } catch (_) {}
    if (!me || !me.user_id) return;
    document.getElementById("dashboard-title").textContent = `Welcome back, ${me.username}!`;
    document.getElementById("dashboard-subtitle").textContent = "Here is a summary of your activity on Librowse.";

    const token = sessionStorage.getItem("librowseSessionToken");
    async function get(endpoint) {
        const res = await fetch(`${API}/${endpoint}`, {
            headers: { "Cache-Control": "no-store", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
            cache: "no-store"
        });
        if (res.status === 401) { window.location.replace("login.html"); throw new Error("Session expired"); }
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || "Request failed");
        return Array.isArray(data) ? data : [];
    }

    const uid = String(me.user_id);
    const tbody = document.getElementById("dashboard-listing-body");
    const table = document.getElementById("dashboard-listing-table");
    if (tbody && table) {
        tbody.innerHTML = '<tr class="loading-row"><td colspan="6"><span class="inline-spinner" aria-hidden="true"></span> Loading your listings…</td></tr>';
        table.style.display = "";
    }

    // Everything at once instead of one request after another
    const [listingsR, booksR, txR, refundsR, reportsR] = await Promise.allSettled([
        get("user_books.php"), get("books_catalog.php"), get("transactions.php"),
        get("refund_request.php"), get("reports.php")
    ]);

    /* Listings */
    if (listingsR.status === "fulfilled" && booksR.status === "fulfilled") {
        const listings = listingsR.value.filter(l => String(l.seller_id) === uid);
        const titles = {};
        booksR.value.forEach(b => { titles[b.book_id] = b.title; });
        setStat("stat-total", listings.length);
        setStat("stat-active", listings.filter(l => l.status === "Available").length);
        setStat("stat-sold", listings.filter(l => l.status === "Sold" || l.status === "Traded").length);
        setStat("stat-pending-listing", listings.filter(l => l.status === "In_transaction").length);
        if (tbody) {
            tbody.innerHTML = listings.length ? listings.map(l => {
                const type = l.listing_type === "For_sale" ? "For Sale" : l.listing_type === "For_trade" ? "For Trade" : "Sale / Trade";
                const price = (l.price !== null && l.price !== undefined && l.price !== "") ? "₱" + Number(l.price).toFixed(2) : "Trade only";
                const status = l.status === "In_transaction" ? "On hold" : l.status;
                return `<tr><td>${esc(l.inventory_id)}</td><td>${esc(titles[l.book_id] || "Unknown book")}</td><td>${esc(type)}</td><td>${esc(price)}</td><td>${esc(l.condition)}</td><td>${esc(status)}</td></tr>`;
            }).join("") : '<tr><td colspan="6">You have no listings yet. <a href="list-book.html">List a book →</a></td></tr>';
        }
    } else {
        ["stat-total", "stat-active", "stat-sold", "stat-pending-listing"].forEach(id => setStat(id, "—"));
        if (tbody) tbody.innerHTML = '<tr><td colspan="6">Couldn\'t load your listings. Refresh the page to try again.</td></tr>';
    }

    /* Transactions you requested */
    if (txR.status === "fulfilled") {
        const mine = txR.value.filter(t => String(t.buyer_id) === uid);
        setStat("stat-tx-total", mine.length);
        setStat("stat-tx-pending", mine.filter(t => ["Pending", "Accepted", "Disputed"].includes(t.status)).length);
        setStat("stat-tx-completed", mine.filter(t => t.status === "Completed").length);
        setStat("stat-tx-cancelled", mine.filter(t => t.status === "Cancelled").length);
    } else ["stat-tx-total", "stat-tx-pending", "stat-tx-completed", "stat-tx-cancelled"].forEach(id => setStat(id, "—"));

    /* Refunds */
    if (refundsR.status === "fulfilled") {
        const mine = refundsR.value.filter(r => String(r.customer_id) === uid);
        setStat("stat-refund-pending", mine.filter(r => r.status === "Pending").length);
        setStat("stat-refund-approved", mine.filter(r => r.status === "Approved").length);
        setStat("stat-refund-other", mine.filter(r => r.status === "Rejected").length);
    } else ["stat-refund-pending", "stat-refund-approved", "stat-refund-other"].forEach(id => setStat(id, "—"));

    /* Reports — every closed outcome counts as resolved */
    if (reportsR.status === "fulfilled") {
        const mine = reportsR.value.filter(r => String(r.submitted_by_id) === uid);
        setStat("stat-report-pending", mine.filter(r => r.status === "Pending").length);
        setStat("stat-report-review", mine.filter(r => r.status === "Under_Review").length);
        setStat("stat-report-resolved", mine.filter(r => ["Resolved", "Approved", "Rejected", "Dismissed"].includes(r.status)).length);
    } else ["stat-report-pending", "stat-report-review", "stat-report-resolved"].forEach(id => setStat(id, "—"));
})();
