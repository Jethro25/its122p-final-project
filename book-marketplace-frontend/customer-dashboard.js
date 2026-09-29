/* LIBROWSE - Customer Dashboard JS */
(async function () {
    if (window.librowseAuthReady) {
        const user = await window.librowseAuthReady;
        if (!user) return;
    }

    function getApiBase() {
        if (window.LIBROWSE_API_BASE) return String(window.LIBROWSE_API_BASE).replace(/\/$/, '');
        return window.location.port === '8000'
            ? (window.location.protocol === 'https:' ? 'https:' : 'http:') + '//' + (window.location.hostname || '127.0.0.1') + ':8000/api'
            : '/api';
    }

    function setStat(id, value) {
        var el = document.getElementById(id);
        if (el) el.textContent = (value !== undefined && value !== null) ? String(value) : '—';
    }

    function getHeaders() {
        var token = sessionStorage.getItem('librowseSessionToken');
        var h = { 'Cache-Control': 'no-store' };
        if (token) h['Authorization'] = 'Bearer ' + token;
        return h;
    }

    /* Welcome */
    var stored = sessionStorage.getItem('librowseCurrentUser') || localStorage.getItem('librowseCurrentUser');
    var currentU = null;
    try { currentU = stored ? JSON.parse(stored) : null; } catch (_) {}
    if (currentU && currentU.username) {
        var t = document.getElementById('dashboard-title');
        var s = document.getElementById('dashboard-subtitle');
        if (t) t.textContent = 'Welcome back, ' + currentU.username + '!';
        if (s) s.textContent = 'Here is a summary of your activity on Librowse.';
    }

    var uid = currentU && currentU.user_id ? currentU.user_id : null;
    if (!uid) return;

    var API = getApiBase();
    var headers = getHeaders();

    /* Listings */
    try {
        var results = await Promise.all([
            fetch(API + '/user_books.php', { headers: headers, cache: 'no-store' }).then(function(r){ return r.json(); }),
            fetch(API + '/books_catalog.php', { headers: headers, cache: 'no-store' }).then(function(r){ return r.json(); })
        ]);
        var listings = results[0].filter(function(l){ return String(l.seller_id) === String(uid); });
        var bookMap = {};
        results[1].forEach(function(b){ bookMap[b.book_id] = b; });

        setStat('stat-total', listings.length);
        setStat('stat-active', listings.filter(function(l){ return l.status === 'Available'; }).length);
        setStat('stat-sold', listings.filter(function(l){ return l.status === 'Sold' || l.status === 'Traded'; }).length);
        setStat('stat-pending-listing', listings.filter(function(l){ return l.status === 'Pending' || l.status === 'In_transaction'; }).length);

        var tbody = document.getElementById('dashboard-listing-body');
        var table = document.getElementById('dashboard-listing-table');
        if (tbody && table) {
            if (listings.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6">You have no listings yet. <a href="list-book.html">List a book →</a></td></tr>';
            } else {
                tbody.innerHTML = listings.map(function(l) {
                    var book = bookMap[l.book_id] || {};
                    var type = l.listing_type === 'For_sale' ? 'For Sale' : l.listing_type === 'For_trade' ? 'For Trade' : 'Sale / Trade';
                    var price = (l.price !== null && l.price !== undefined && l.price !== '') ? '₱' + Number(l.price).toFixed(2) : 'Trade Only';
                    return '<tr><td>' + l.inventory_id + '</td><td>' + (book.title || 'Unknown') + '</td><td>' + type + '</td><td>' + price + '</td><td>' + l.condition + '</td><td>' + l.status + '</td></tr>';
                }).join('');
            }
            table.style.display = '';
        }
    } catch (_) {
        ['stat-total','stat-active','stat-sold','stat-pending-listing'].forEach(function(id){ setStat(id,'—'); });
        var note = document.getElementById('listing-note');
        if (note) { note.textContent = 'Listing data unavailable. Ensure the backend is running.'; note.style.display = ''; }
    }

    /* Transactions */
    try {
        var allTx = await fetch(API + '/transactions.php', { headers: headers, cache: 'no-store' }).then(function(r){ return r.json(); });
        var myTx = allTx.filter(function(t){ return String(t.buyer_id) === String(uid); });
        setStat('stat-tx-total', myTx.length);
        setStat('stat-tx-pending', myTx.filter(function(t){ return t.status === 'Pending'; }).length);
        setStat('stat-tx-completed', myTx.filter(function(t){ return t.status === 'Completed'; }).length);
        setStat('stat-tx-cancelled', myTx.filter(function(t){ return t.status === 'Cancelled'; }).length);
    } catch (_) {
        ['stat-tx-total','stat-tx-pending','stat-tx-completed','stat-tx-cancelled'].forEach(function(id){ setStat(id,'—'); });
    }

    /* Refunds */
    try {
        var refunds = await fetch(API + '/refund_request.php', { headers: headers, cache: 'no-store' }).then(function(r){ return r.json(); });
        var myRefunds = Array.isArray(refunds) ? refunds.filter(function(r){ return String(r.customer_id) === String(uid); }) : [];
        setStat('stat-refund-pending', myRefunds.filter(function(r){ return r.status === 'Pending'; }).length);
        setStat('stat-refund-approved', myRefunds.filter(function(r){ return r.status === 'Approved'; }).length);
        setStat('stat-refund-other', myRefunds.filter(function(r){ return r.status !== 'Pending' && r.status !== 'Approved'; }).length);
    } catch (_) {
        ['stat-refund-pending','stat-refund-approved','stat-refund-other'].forEach(function(id){ setStat(id,'—'); });
    }

    /* Reports */
    try {
        var reports = await fetch(API + '/reports.php', { headers: headers, cache: 'no-store' }).then(function(r){ return r.json(); });
        var myReports = Array.isArray(reports) ? reports.filter(function(r){ return String(r.submitted_by_id) === String(uid); }) : [];
        setStat('stat-report-pending', myReports.filter(function(r){ return r.status === 'Pending'; }).length);
        setStat('stat-report-review', myReports.filter(function(r){ return r.status === 'Under_Review' || r.status === 'Under Review'; }).length);
        setStat('stat-report-resolved', myReports.filter(function(r){ return r.status === 'Resolved'; }).length);
    } catch (_) {
        ['stat-report-pending','stat-report-review','stat-report-resolved'].forEach(function(id){ setStat(id,'—'); });
    }
})();
