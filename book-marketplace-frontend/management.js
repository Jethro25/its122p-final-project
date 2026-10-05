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
const MANAGEMENT_API_BASE = librowseApiBase();

const managementState = {
    user: null,
    role: null,
    users: [],
    categories: [],
    books: [],
    listings: [],
    reports: [],
    transactions: [],
    refunds: [],
    records: [],
    activeTab: null,
    editingCategoryId: null,
    editingBookId: null
};

function mgEscape(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function mgRole(value) {
    return String(value || "").toLowerCase();
}

async function mgApi(endpoint, options = {}) {
    const token = sessionStorage.getItem("librowseSessionToken");
    const headers = new Headers(options.headers || {});
    if (token) headers.set("Authorization", `Bearer ${token}`);
    headers.set("Cache-Control", "no-store");
    const response = await fetch(`${MANAGEMENT_API_BASE}/${endpoint}`, { ...options, headers, cache: "no-store" });
    const text = await response.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; }
    catch { throw new Error("Backend returned invalid JSON."); }

    if (response.status === 401) {
        if (window.librowseAuth) window.librowseAuth.clearSession();
        window.location.replace("login.html");
        throw new Error("Your session has expired.");
    }
    if (!response.ok) {
        throw new Error(data.error || `Request failed (${response.status}).`);
    }
    return data;
}

function currentUserFromStorage() {
    try {
        const raw = sessionStorage.getItem("librowseCurrentUser");
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

async function requireManagementRole(expectedRole) {
    const user = window.librowseAuth ? await window.librowseAuth.requireRole(expectedRole) : currentUserFromStorage();

    if (!user || !user.user_id) {
        window.location.href = "login.html";
        return false;
    }

    const role = mgRole(user.role);
    const expected = Array.isArray(expectedRole) ? expectedRole : [expectedRole];

    if (!expected.includes(role)) {
        if (role === "admin") window.location.href = "admin.html";
        else if (role === "staff") window.location.href = "staff.html";
        else window.location.href = "index.html";
        return false;
    }

    managementState.user = user;
    managementState.role = role;
    return true;
}

function showMgmtAlert(message, type = "info") {
    const box = document.getElementById("management-alert");
    if (!box) return;
    box.textContent = message;
    box.className = `management-alert ${type}`;
    box.style.display = "block";
    clearTimeout(window.__mgAlertTimer);
    window.__mgAlertTimer = setTimeout(() => box.style.display = "none", 3200);
}

function setActiveTab(tab) {
    document.querySelectorAll(".management-section").forEach(section => {
        section.classList.toggle("active", section.dataset.section === tab);
    });
    document.querySelectorAll(".management-nav button[data-tab]").forEach(button => {
        button.classList.toggle("active", button.dataset.tab === tab);
    });
    managementState.activeTab = tab;
}

function goBackToMarketplace() {
    window.location.href = "index.html";
}

async function logoutManagement() {
    if (window.librowseAuth) await window.librowseAuth.logout();
    else { sessionStorage.removeItem("librowseSessionToken"); sessionStorage.removeItem("librowseCurrentUser"); }
    window.location.replace("login.html");
}

function renderSession() {
    const user = managementState.user;
    const name = document.getElementById("management-user-name");
    const role = document.getElementById("management-role-label");
    if (name) name.textContent = `${user.username} (${user.email || "no email"})`;
    if (role) role.textContent = managementState.role.toUpperCase();
}

function formatDate(value) {
    if (!value) return "—";
    const parsed = new Date(String(value).replace(" ", "T"));
    if (Number.isNaN(parsed.getTime())) return value;
    return parsed.toLocaleString();
}

function formatMoney(value) {
    if (value === null || value === undefined || value === "") return "—";
    return `₱${Number(value).toFixed(2)}`;
}

function badge(value) {
    const text = String(value ?? "—");
    let cls = "muted";
    if (["Active","Accepted","Approved","Completed","Resolved"].includes(text)) cls = "success";
    if (["Pending","Pending Verification","Under_Review","In_transaction","Disputed"].includes(text)) cls = "warning";
    if (["Suspended","Banned","Locked","Rejected","Cancelled","Removed","Dismissed"].includes(text)) cls = "danger";
    return `<span class="management-badge ${cls}">${mgEscape(text.replaceAll("_"," "))}</span>`;
}

function selected(a, b) {
    return String(a) === String(b) ? "selected" : "";
}

function userMap() {
    return Object.fromEntries(managementState.users.map(u => [u.user_id, u]));
}

function bookMap() {
    return Object.fromEntries(managementState.books.map(b => [b.book_id, b]));
}

function listingMap() {
    return Object.fromEntries(managementState.listings.map(l => [l.inventory_id, l]));
}

function parsePermissions(value) {
    if (!value) return {};
    if (typeof value === "object") return value;
    try {
        const parsed = JSON.parse(value);
        return parsed && typeof parsed === "object" && !Array.isArray(parsed) ? parsed : {};
    } catch {
        throw new Error("Permissions must contain valid JSON object syntax.");
    }
}

async function loadAllUsers() {
    managementState.users = await mgApi("user.php?limit=500&offset=0");
    return managementState.users;
}

async function loadCategories() {
    managementState.categories = await mgApi("book_categories.php?limit=500&offset=0");
    return managementState.categories;
}

async function loadBooks() {
    managementState.books = await mgApi("books_catalog.php?limit=500&offset=0");
    return managementState.books;
}

async function loadListings() {
    managementState.listings = await mgApi("user_books.php?limit=500&offset=0");
    return managementState.listings;
}

async function loadReports() {
    managementState.reports = await mgApi("reports.php?limit=500&offset=0");
    return managementState.reports;
}

async function loadTransactions() {
    managementState.transactions = await mgApi("transactions.php?limit=500&offset=0");
    return managementState.transactions;
}

async function loadRefunds() {
    managementState.refunds = await mgApi("refund_request.php?limit=500&offset=0");
    return managementState.refunds;
}

async function loadRecords() {
    managementState.records = await mgApi("system_records.php?limit=500&offset=0");
    return managementState.records;
}

async function reloadCoreData() {
    await Promise.all([loadAllUsers(), loadCategories(), loadBooks(), loadListings(), loadReports(), loadTransactions(), loadRefunds()]);
    try { await loadRecords(); } catch (e) { console.warn("Records unavailable:", e.message); }
}

function renderDashboardStats() {
    const target = document.getElementById("overview-stats");
    if (!target) return;

    const customers = managementState.users.filter(u => mgRole(u.role) === "customer");
    const staff = managementState.users.filter(u => mgRole(u.role) === "staff");
    const pendingReports = managementState.reports.filter(r => ["Pending","Under_Review"].includes(r.status));
    const pendingRefunds = managementState.refunds.filter(r => r.status === "Pending");
    const openTransactions = managementState.transactions.filter(t => ["Pending","Accepted","Disputed"].includes(t.status));
    const activeListings = managementState.listings.filter(l => l.status === "Available");

    const cards = managementState.role === "admin" ? [
        ["Users", managementState.users.length],
        ["Catalog Books", managementState.books.length],
        ["Active Listings", activeListings.length],
        ["Open Transactions", openTransactions.length],
        ["Pending Reports", pendingReports.length],
        ["Pending Refunds", pendingRefunds.length],
        ["Staff", staff.length],
        ["Categories", managementState.categories.length]
    ] : [
        ["Customers", customers.length],
        ["Pending Forms", managementState.reports.filter(r => ["Verification_Form","Seller_Application"].includes(r.report_category) && ["Pending","Under_Review"].includes(r.status)).length],
        ["Open Transactions", openTransactions.length],
        ["Pending Refunds", pendingRefunds.length]
    ];

    target.innerHTML = cards.map(([label, value]) => `
        <div class="management-card">
            <div class="management-stat-label">${mgEscape(label)}</div>
            <div class="management-stat-value">${value}</div>
        </div>
    `).join("");
}

function renderUsersTable() {
    const tbody = document.getElementById("users-body");
    if (!tbody) return;

    const search = (document.getElementById("users-search")?.value || "").toLowerCase().trim();
    let users = managementState.users.filter(u => {
        if (managementState.role === "staff" && mgRole(u.role) !== "customer") return false;
        if (!search) return true;
        return [u.username, u.email, u.role, u.status].some(x => String(x || "").toLowerCase().includes(search));
    });

    if (!users.length) {
        tbody.innerHTML = `<tr><td colspan="8" class="management-empty">No users found.</td></tr>`;
        return;
    }

    tbody.innerHTML = users.map(user => {
        const canDelete = managementState.role === "admin" && user.user_id !== managementState.user.user_id;
        const perms = typeof user.permission === "string" ? user.permission : JSON.stringify(user.permission || {}, null, 0);

        const roleOptions = managementState.role === "admin"
            ? ["Customer","Staff","Admin"]
            : ["Customer"];

        return `
            <tr>
                <td>${user.user_id}</td>
                <td><strong>${mgEscape(user.username)}</strong><div class="muted">${mgEscape(user.email)}</div></td>
                <td>
                    <select data-user-role="${user.user_id}" ${managementState.role === "staff" ? "disabled" : ""}>
                        ${roleOptions.map(r => `<option value="${r}" ${selected(r,user.role)}>${r}</option>`).join("")}
                    </select>
                </td>
                <td>
                    <select data-user-status="${user.user_id}" ${managementState.role === "staff" && user.status === "Locked" ? "disabled title=\"Only an administrator can unlock this account\"" : ""}>
                        ${(managementState.role === "staff" && user.status !== "Locked" ? ["Active","Suspended","Banned","Pending Verification"] : ["Active","Suspended","Banned","Pending Verification","Locked"]).map(s => `<option value="${s}" ${selected(s,user.status)}>${s}</option>`).join("")}
                    </select>
                </td>
                <td>
                    <textarea data-user-permission="${user.user_id}" ${managementState.role === "staff" ? "disabled" : ""} aria-label="Permissions for ${mgEscape(user.username)}">${mgEscape(perms)}</textarea>
                </td>
                <td>${badge(user.status)}</td>
                <td>${formatDate(user.created_at)}</td>
                <td>
                    <div class="management-actions">
                        ${user.status === "Locked" && managementState.role === "admin" ? `<button class="management-btn success small" onclick="unlockUser(${user.user_id})">Unlock</button>` : ""}
                        ${user.status === "Locked" && (managementState.reports || []).some(r => Number(r.submitted_by_id) === Number(user.user_id) && ["Pending","Under_Review"].includes(r.status) && parseFormData(r.form_data)?.type === "unlock_request") ? `<span class="management-badge warning">Requested unlock</span>` : ""}
                        ${managementState.role === "staff" && user.status === "Locked" ? `<span class="muted">Admin unlocks</span>` : `<button class="management-btn primary small" onclick="saveUser(${user.user_id})">Save</button>`}
                        ${canDelete ? `<button class="management-btn danger small" onclick="deleteUser(${user.user_id})">Archive</button>` : ""}
                    </div>
                </td>
            </tr>
        `;
    }).join("");
}

async function saveUser(userId) {
    try {
        const roleEl = document.querySelector(`[data-user-role="${userId}"]`);
        const statusEl = document.querySelector(`[data-user-status="${userId}"]`);
        const permissionEl = document.querySelector(`[data-user-permission="${userId}"]`);

        // Staff may only change the status; admins can also change permissions and role
        const payload = { status: statusEl.value };
        if (managementState.role === "admin") payload.permission = parsePermissions(permissionEl.value);

        if (managementState.role === "admin" && roleEl) payload.role = roleEl.value;

        await mgApi(`user.php?id=${userId}`, {
            method: "PUT",
            headers: {"Content-Type":"application/json"},
            body: JSON.stringify(payload)
        });

        showMgmtAlert("User account updated.", "success");
        await loadAllUsers();
        renderUsersTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

async function unlockUser(userId) {
    try {
        await mgApi(`user.php?id=${userId}`, {
            method: "PUT",
            headers: {"Content-Type":"application/json"},
            body: JSON.stringify({ status: "Active" })
        });
        // Close any open unlock requests from this user
        const openRequests = (managementState.reports || []).filter(r =>
            Number(r.submitted_by_id) === Number(userId) &&
            ["Pending","Under_Review"].includes(r.status) &&
            parseFormData(r.form_data)?.type === "unlock_request");
        for (const r of openRequests) {
            await mgApi(`reports.php?id=${r.report_id}`, {
                method: "PUT",
                headers: {"Content-Type":"application/json"},
                body: JSON.stringify({
                    status: "Resolved",
                    resolution_notes: "Account unlocked by an administrator.",
                    reviewed_by_id: managementState.user.user_id,
                    resolved_at: new Date().toISOString().slice(0,19).replace("T"," ")
                })
            });
        }
        showMgmtAlert(openRequests.length
            ? "Account unlocked and the unlock request marked as resolved."
            : "Account unlocked. The user can sign in again.", "success");
        await loadAllUsers();
        if (openRequests.length) await loadReports();
        renderUsersTable();
        if (typeof renderReportsTable === "function") renderReportsTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

async function deleteUser(userId) {
    if (!confirm(`Archive user #${userId}? They will no longer be able to sign in. It will be hidden from the app, but the record is kept in the database for logging.`)) return;

    try {
        await mgApi(`user.php?id=${userId}`, {method:"DELETE"});
        showMgmtAlert("User archived. The record is kept for logging.", "success");
        await loadAllUsers();
        renderUsersTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(`Could not remove user: ${error.message}`, "error");
    }
}

function fillCategoryOptions(selectedIds = []) {
    const host = document.getElementById("book-category-editor");
    if (!host) return;
    host.innerHTML = managementState.categories.map(c => `
        <label>
            <input type="checkbox" name="book_category_ids" value="${c.category_id}" ${selectedIds.includes(Number(c.category_id)) ? "checked" : ""}>
            ${mgEscape(c.category_name)}
        </label>
    `).join("") || `<span class="muted">No categories available.</span>`;
}

function getBookCategoryIds() {
    return Array.from(document.querySelectorAll('input[name="book_category_ids"]:checked'))
        .map(el => Number(el.value));
}

function resetBookForm() {
    managementState.editingBookId = null;
    document.getElementById("book-form-title").textContent = "Add catalog book";
    document.getElementById("book-submit-button").textContent = "Add Book";
    document.getElementById("book-form")?.reset();
    fillCategoryOptions([]);
}

function editBook(bookId) {
    const book = managementState.books.find(b => Number(b.book_id) === Number(bookId));
    if (!book) return;
    managementState.editingBookId = bookId;
    document.getElementById("book-form-title").textContent = `Edit catalog book #${bookId}`;
    document.getElementById("book-submit-button").textContent = "Save Book";
    document.getElementById("book-title").value = book.title || "";
    document.getElementById("book-author").value = book.author || "";
    document.getElementById("book-isbn").value = book.isbn || "";
    fillCategoryOptions((book.category_ids || [book.category_id]).map(Number));
    document.getElementById("books-section")?.scrollIntoView({behavior:"smooth"});
}

async function submitBookForm(event) {
    event.preventDefault();

    try {
        const title = document.getElementById("book-title").value.trim();
        const author = document.getElementById("book-author").value.trim();
        const isbn = document.getElementById("book-isbn").value.trim();
        const categoryIds = getBookCategoryIds();

        if (!title || !author || !isbn || !categoryIds.length) {
            throw new Error("Title, author, ISBN, and at least one category are required.");
        }

        const payload = {
            title,
            author,
            isbn,
            category_ids: categoryIds,
            managed_by_admin_id: managementState.user.user_id
        };

        if (managementState.editingBookId) {
            delete payload.managed_by_admin_id;
            await mgApi(`books_catalog.php?id=${managementState.editingBookId}`, {
                method:"PUT",
                headers:{"Content-Type":"application/json"},
                body:JSON.stringify(payload)
            });
            showMgmtAlert("Catalog book updated.", "success");
        } else {
            await mgApi("books_catalog.php", {
                method:"POST",
                headers:{"Content-Type":"application/json"},
                body:JSON.stringify(payload)
            });
            showMgmtAlert("Catalog book added.", "success");
        }

        resetBookForm();
        await loadBooks();
        await loadCategories();
        fillCategoryOptions([]);
        renderBooksTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

function renderBooksTable() {
    const tbody = document.getElementById("books-body");
    if (!tbody) return;
    const map = userMap();

    if (!managementState.books.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="management-empty">No catalog books found.</td></tr>`;
        return;
    }

    tbody.innerHTML = managementState.books.map(book => `
        <tr>
            <td>${book.book_id}</td>
            <td><strong>${mgEscape(book.title)}</strong></td>
            <td>${mgEscape(book.author)}</td>
            <td>${mgEscape(book.isbn)}</td>
            <td>${mgEscape((book.category_ids || [book.category_id]).map(id => managementState.categories.find(c => Number(c.category_id) === Number(id))?.category_name || `#${id}`).join(", "))}</td>
            <td>${mgEscape(map[book.managed_by_admin_id]?.username || `User #${book.managed_by_admin_id}`)}</td>
            <td>
                <div class="management-actions">
                    <button class="management-btn primary small" onclick="editBook(${book.book_id})">Edit</button>
                    <button class="management-btn danger small" onclick="deleteBook(${book.book_id})" title="Soft-delete: hides from catalog but keeps the record">Archive</button>
                </div>
            </td>
        </tr>
    `).join("");
}

async function deleteBook(bookId) {
    if (!confirm(`Archive catalog book #${bookId}? It will be hidden from the app, but the record is kept in the database for logging.`)) return;
    try {
        await mgApi(`books_catalog.php?id=${bookId}`, {method:"DELETE"});
        showMgmtAlert("Catalog book archived. The record is kept for logging.", "success");
        await loadBooks();
        await loadListings();
        renderBooksTable();
        renderListingsTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(`Could not delete catalog book: ${error.message}`, "error");
    }
}

function editCategory(categoryId) {
    const c = managementState.categories.find(x => Number(x.category_id) === Number(categoryId));
    if (!c) return;
    managementState.editingCategoryId = categoryId;
    document.getElementById("category-name").value = c.category_name || "";
    document.getElementById("category-description").value = c.description || "";
    document.getElementById("category-submit-button").textContent = "Save Category";
    document.getElementById("categories-section")?.scrollIntoView({behavior:"smooth"});
}

function resetCategoryForm() {
    managementState.editingCategoryId = null;
    document.getElementById("category-form")?.reset();
    document.getElementById("category-submit-button").textContent = "Add Category";
}

async function submitCategoryForm(event) {
    event.preventDefault();
    try {
        const category_name = document.getElementById("category-name").value.trim();
        const description = document.getElementById("category-description").value.trim();
        if (!category_name) throw new Error("Category name is required.");

        if (managementState.editingCategoryId) {
            await mgApi(`book_categories.php?id=${managementState.editingCategoryId}`, {
                method:"PUT",
                headers:{"Content-Type":"application/json"},
                body:JSON.stringify({category_name, description})
            });
            showMgmtAlert("Category updated.", "success");
        } else {
            await mgApi("book_categories.php", {
                method:"POST",
                headers:{"Content-Type":"application/json"},
                body:JSON.stringify({
                    category_name,
                    description,
                    created_by_admin_id: managementState.user.user_id
                })
            });
            showMgmtAlert("Category created.", "success");
        }

        resetCategoryForm();
        await loadCategories();
        fillCategoryOptions([]);
        renderCategoriesTable();
        renderBooksTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

function renderCategoriesTable() {
    const tbody = document.getElementById("categories-body");
    if (!tbody) return;
    const bookCounts = {};
    managementState.books.forEach(book => {
        (book.category_ids || [book.category_id]).forEach(id => bookCounts[id] = (bookCounts[id] || 0) + 1);
    });

    tbody.innerHTML = managementState.categories.map(c => `
        <tr>
            <td>${c.category_id}</td>
            <td>${mgEscape(c.category_name)}</td>
            <td>${mgEscape(c.description || "—")}</td>
            <td>${bookCounts[c.category_id] || 0}</td>
            <td>
                <div class="management-actions">
                    <button class="management-btn primary small" onclick="editCategory(${c.category_id})">Edit</button>
                    <button class="management-btn danger small" onclick="deleteCategory(${c.category_id})">Archive</button>
                </div>
            </td>
        </tr>
    `).join("") || `<tr><td colspan="5" class="management-empty">No categories found.</td></tr>`;
}

async function deleteCategory(categoryId) {
    if (!confirm(`Archive category #${categoryId}? It will be hidden from the app, but the record is kept in the database for logging.`)) return;
    try {
        await mgApi(`book_categories.php?id=${categoryId}`, {method:"DELETE"});
        showMgmtAlert("Category archived. The record is kept for logging.", "success");
        await loadCategories();
        await loadBooks();
        renderCategoriesTable();
        renderBooksTable();
        fillCategoryOptions([]);
    } catch (error) {
        showMgmtAlert(`Could not delete category: ${error.message}`, "error");
    }
}

function renderListingsTable() {
    const tbody = document.getElementById("listings-body");
    if (!tbody) return;
    const bm = bookMap();
    const um = userMap();

    tbody.innerHTML = managementState.listings.map(l => `
        <tr>
            <td>${l.inventory_id}</td>
            <td>${mgEscape(bm[l.book_id]?.title || `Book #${l.book_id}`)}</td>
            <td>${mgEscape(um[l.seller_id]?.username || `User #${l.seller_id}`)}</td>
            <td>${mgEscape(l.listing_type)}</td>
            <td>${mgEscape(l.condition)}</td>
            <td>${formatMoney(l.price)}</td>
            <td>
                <select id="listing-status-${l.inventory_id}">
                    ${["Available","In_transaction","Sold","Traded","Removed"].map(s => `<option value="${s}" ${selected(s,l.status)}>${s.replaceAll("_"," ")}</option>`).join("")}
                </select>
            </td>
            <td>${formatDate(l.listed_at)}</td>
            <td>
                <button class="management-btn primary small" onclick="saveListing(${l.inventory_id})">Save</button>
            </td>
        </tr>
    `).join("") || `<tr><td colspan="9" class="management-empty">No listings found.</td></tr>`;
}

async function saveListing(inventoryId) {
    try {
        const status = document.getElementById(`listing-status-${inventoryId}`).value;
        await mgApi(`user_books.php?id=${inventoryId}`, {
            method:"PUT",
            headers:{"Content-Type":"application/json"},
            body:JSON.stringify({status})
        });
        showMgmtAlert("Listing status updated.", "success");
        await loadListings();
        renderListingsTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

function parseFormData(value) {
    if (!value) return {};
    if (typeof value === "object") return value;
    try {
        return JSON.parse(value);
    } catch {
        return {details: value};
    }
}

function renderReportsTable() {
    const tbody = document.getElementById("reports-body");
    if (!tbody) return;
    const um = userMap();

    let reports = [...managementState.reports];
    const formOnly = document.getElementById("reports-forms-only")?.checked;
    if (formOnly) {
        reports = reports.filter(r => ["Verification_Form","Seller_Application"].includes(r.report_category));
    }

    tbody.innerHTML = reports.map(r => {
        const data = parseFormData(r.form_data);
        const isUnlock = data && data.type === "unlock_request";
        const requester = um[r.submitted_by_id];
        const stillLocked = requester && requester.status === "Locked";
        const detailsCell = isUnlock
            ? `<div class="unlock-request-cell"><span class="management-badge danger">Unlock request</span>
                 <div>${data.message ? `&ldquo;${mgEscape(data.message)}&rdquo;` : "<span class='muted'>No message</span>"}</div>
                 <div class="muted">Account is ${requester ? mgEscape(requester.status) : "unknown"}</div></div>`
            : `<span class="management-code">${mgEscape(JSON.stringify(data))}</span>`;
        const unlockBtn = isUnlock && stillLocked && managementState.role === "admin"
            ? `<button class="management-btn success small" onclick="unlockUser(${r.submitted_by_id})">Unlock account</button>`
            : "";
        return `
        <tr>
            <td>${r.report_id}</td>
            <td>${mgEscape(um[r.submitted_by_id]?.username || `User #${r.submitted_by_id}`)}</td>
            <td>${mgEscape(r.report_category.replaceAll("_"," "))}</td>
            <td>${mgEscape(r.related_entity_type)}</td>
            <td>${detailsCell}</td>
            <td>${formatDate(r.submitted_at)}</td>
            <td>
                <select id="report-status-${r.report_id}">
                    ${["Pending","Under_Review","Approved","Rejected","Resolved","Dismissed"].map(s => `<option value="${s}" ${selected(s,r.status)}>${s.replaceAll("_"," ")}</option>`).join("")}
                </select>
            </td>
            <td>
                <textarea id="report-notes-${r.report_id}" placeholder="Resolution/review notes">${mgEscape(r.resolution_notes || "")}</textarea>
            </td>
            <td>
                <div class="management-actions">
                    ${unlockBtn}
                    <button class="management-btn primary small" onclick="saveReport(${r.report_id})">Save</button>
                </div>
            </td>
        </tr>`;
    }).join("") || `<tr><td colspan="9" class="management-empty">No reports/forms found.</td></tr>`;
}

async function saveReport(reportId) {
    try {
        const status = document.getElementById(`report-status-${reportId}`).value;
        const resolution_notes = document.getElementById(`report-notes-${reportId}`).value.trim();
        await mgApi(`reports.php?id=${reportId}`, {
            method:"PUT",
            headers:{"Content-Type":"application/json"},
            body:JSON.stringify({
                status,
                resolution_notes,
                reviewed_by_id: managementState.user.user_id,
                resolved_at: ["Approved","Rejected","Resolved","Dismissed"].includes(status) ? new Date().toISOString().slice(0,19).replace("T"," ") : null
            })
        });
        showMgmtAlert("Report/form review saved.", "success");
        await loadReports();
        renderReportsTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

/* Valid next statuses — the server enforces the same rules */
const TX_NEXT = {
    Pending:  ["Accepted", "Cancelled", "Disputed"],
    Accepted: ["Completed", "Cancelled", "Disputed"],
    Disputed: ["Completed", "Cancelled"],
    Completed: [],
    Cancelled: []
};

function renderTransactionsTable() {
    const tbody = document.getElementById("transactions-body");
    if (!tbody) return;
    const um = userMap();
    const lm = listingMap();
    const bm = bookMap();

    tbody.innerHTML = managementState.transactions.map(t => {
        const listing = lm[t.requested_inventory_id];
        const bookTitle = listing ? (bm[listing.book_id]?.title || `Book #${listing.book_id}`) : `Listing #${t.requested_inventory_id}`;
        return `
        <tr>
            <td>${t.transaction_id}</td>
            <td>${mgEscape(um[t.buyer_id]?.username || `User #${t.buyer_id}`)}</td>
            <td>${mgEscape(bookTitle)}</td>
            <td>${mgEscape(t.transaction_type)}</td>
            <td>${formatMoney(t.amount_paid)}</td>
            <td>${badge(t.status)}</td>
            <td>${mgEscape(um[t.managed_by_staff_id]?.username || "—")}</td>
            <td>${formatDate(t.created_at)}</td>
            <td>
                <select id="transaction-status-${t.transaction_id}" ${(TX_NEXT[t.status] || []).length ? "" : "disabled"}>
                    ${[t.status, ...(TX_NEXT[t.status] || [])].map(s => `<option value="${s}" ${selected(s,t.status)}>${s}</option>`).join("")}
                </select>
            </td>
            <td>${(TX_NEXT[t.status] || []).length ? `<button class="management-btn primary small" onclick="saveTransaction(${t.transaction_id})">Save</button>` : `<span class="muted">Final</span>`}</td>
        </tr>`;
    }).join("") || `<tr><td colspan="10" class="management-empty">No transactions found.</td></tr>`;
}

async function saveTransaction(transactionId) {
    try {
        const status = document.getElementById(`transaction-status-${transactionId}`).value;
        const payload = {status};
        if (managementState.role === "staff") payload.managed_by_staff_id = managementState.user.user_id;
        await mgApi(`transactions.php?id=${transactionId}`, {
            method:"PUT",
            headers:{"Content-Type":"application/json"},
            body:JSON.stringify(payload)
        });
        showMgmtAlert("Transaction status updated.", "success");
        await loadTransactions();
        renderTransactionsTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

function renderRefundsTable() {
    const tbody = document.getElementById("refunds-body");
    if (!tbody) return;
    const um = userMap();
    tbody.innerHTML = managementState.refunds.map(r => `
        <tr>
            <td>${r.refund_id}</td>
            <td>#${r.transaction_id}</td>
            <td>${mgEscape(um[r.customer_id]?.username || `User #${r.customer_id}`)}</td>
            <td>${mgEscape(r.reason)}</td>
            <td>${formatDate(r.requested_at)}</td>
            <td>${badge(r.status)}</td>
            <td>${mgEscape(um[r.processed_by_staff_id]?.username || "—")}</td>
            <td>
                ${managementState.role === "staff" && r.status === "Pending" ? `
                <select id="refund-status-${r.refund_id}">
                    ${["Pending","Approved","Rejected"].map(s => `<option value="${s}" ${selected(s,r.status)}>${s}</option>`).join("")}
                </select>
                <button class="management-btn primary small" onclick="saveRefund(${r.refund_id})">Save</button>
                ` : badge(r.status)}
            </td>
        </tr>
    `).join("") || `<tr><td colspan="8" class="management-empty">No refund requests found.</td></tr>`;
}

async function saveRefund(refundId) {
    try {
        const status = document.getElementById(`refund-status-${refundId}`).value;
        await mgApi(`refund_request.php?id=${refundId}`, {
            method:"PUT",
            headers:{"Content-Type":"application/json"},
            body:JSON.stringify({status, processed_by_staff_id: managementState.user.user_id})
        });
        showMgmtAlert("Refund request updated.", "success");
        await loadRefunds();
        renderRefundsTable();
        renderDashboardStats();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

function renderRecordsTable() {
    const tbody = document.getElementById("records-body");
    if (!tbody) return;
    const um = userMap();
    tbody.innerHTML = managementState.records.map(r => `
        <tr>
            <td>${r.record_id}</td>
            <td>${mgEscape(um[r.admin_id]?.username || `User #${r.admin_id}`)}</td>
            <td>${mgEscape(r.record_type.replaceAll("_"," "))}</td>
            <td class="management-code">${mgEscape(typeof r.details === "string" ? r.details : JSON.stringify(r.details || {}))}</td>
            <td>${formatDate(r.created_at)}</td>
            <td><button class="management-btn danger small" onclick="deleteRecord(${r.record_id})" title="Soft-delete: hides from view but keeps the audit record">Archive</button></td>
        </tr>
    `).join("") || `<tr><td colspan="6" class="management-empty">No system records found.</td></tr>`;
}

async function submitRecordForm(event) {
    event.preventDefault();
    try {
        const record_type = document.getElementById("record-type").value;
        const detailsText = document.getElementById("record-details").value.trim();
        let details = {};
        if (detailsText) details = JSON.parse(detailsText);
        await mgApi("system_records.php", {
            method:"POST",
            headers:{"Content-Type":"application/json"},
            body:JSON.stringify({admin_id:managementState.user.user_id, record_type, details})
        });
        showMgmtAlert("System record created.", "success");
        document.getElementById("record-form").reset();
        await loadRecords();
        renderRecordsTable();
    } catch (error) {
        showMgmtAlert(error.message.includes("JSON") ? "Record details must be valid JSON." : error.message, "error");
    }
}

async function deleteRecord(recordId) {
    if (!confirm(`Archive system record #${recordId}? It will be hidden from the app, but the record is kept in the database for logging.`)) return;
    try {
        await mgApi(`system_records.php?id=${recordId}`, {method:"DELETE"});
        showMgmtAlert("System record archived. The record is kept for logging.", "success");
        await loadRecords();
        renderRecordsTable();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

async function submitStaffIssue(event) {
    event.preventDefault();
    try {
        const details = document.getElementById("staff-issue-details").value.trim();
        if (!details) throw new Error("Please describe the issue.");
        await mgApi("reports.php", {
            method:"POST",
            headers:{"Content-Type":"application/json"},
            body:JSON.stringify({
                submitted_by_id: managementState.user.user_id,
                report_category: "General_Feedback",
                related_entity_type: "None",
                form_data: JSON.stringify({issue_type:"Staff_System_Issue", details}),
                status: "Pending"
            })
        });
        showMgmtAlert("Issue reported to Administration.", "success");
        document.getElementById("staff-issue-form").reset();
    } catch (error) {
        showMgmtAlert(error.message, "error");
    }
}

function refreshRenderedData() {
    renderDashboardStats();
    renderUsersTable();
    renderBooksTable();
    renderCategoriesTable();
    fillCategoryOptions(managementState.editingBookId ? [] : []);
    renderListingsTable();
    renderReportsTable();
    renderTransactionsTable();
    renderRefundsTable();
    renderRecordsTable();
}

/* ── Loading placeholders while data arrives ───────────────────────────── */
function showLoadingPlaceholders() {
    document.querySelectorAll(".management-table tbody, tbody[id$='-body']").forEach(tbody => {
        const cols = tbody.closest("table")?.querySelectorAll("thead th").length || 6;
        tbody.innerHTML = `<tr class="management-loading-row"><td colspan="${cols}"><span class="mg-spinner" aria-hidden="true"></span> Loading…</td></tr>`;
    });
    const stats = document.getElementById("overview-stats");
    if (stats) stats.innerHTML = Array.from({ length: 4 }, () =>
        `<div class="management-card mg-skeleton"><div class="management-stat-label">Loading…</div><div class="management-stat-value">&nbsp;</div></div>`).join("");
}

/* ── Busy buttons: any action button is disabled until its request finishes ── */
let mgLastButton = null;
document.addEventListener("click", e => {
    const btn = e.target.closest("button");
    if (btn) mgLastButton = btn;
}, true);
function withBusyButton(fn, busyText) {
    return async function (...args) {
        const btn = mgLastButton;
        mgLastButton = null;
        if (btn && btn.dataset.busy === "1") return;          // ignore double clicks
        const label = btn ? btn.innerHTML : "";
        if (btn) { btn.dataset.busy = "1"; btn.disabled = true; btn.innerHTML = `<span class="mg-spinner" aria-hidden="true"></span> ${busyText}`; }
        try { return await fn.apply(this, args); }
        finally {
            mgDirty = false;
            if (btn && btn.isConnected) { btn.disabled = false; btn.innerHTML = label; delete btn.dataset.busy; }
        }
    };
}
[["saveUser","Saving…"],["unlockUser","Unlocking…"],["deleteUser","Archiving…"],["saveListing","Saving…"],
 ["saveReport","Saving…"],["saveTransaction","Saving…"],["saveRefund","Saving…"],["deleteBook","Archiving…"],
 ["deleteCategory","Archiving…"],["deleteRecord","Archiving…"],["submitBookForm","Saving…"],
 ["submitCategoryForm","Saving…"],["submitRecordForm","Saving…"],["submitStaffIssue","Sending…"]
].forEach(([name, text]) => {
    if (typeof window[name] === "function") window[name] = withBusyButton(window[name], text);
});

/* ── Auto-refresh that never throws away unsaved edits ──────────────────── */
let mgDirty = false;
document.addEventListener("input", e => { if (e.target.closest(".management-section")) mgDirty = true; }, true);
document.addEventListener("change", e => { if (e.target.closest(".management-section")) mgDirty = true; }, true);
function safeToAutoRefresh() {
    if (document.hidden || mgDirty) return false;
    const active = document.activeElement;
    return !(active && active.closest && active.closest(".management-section") && /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName));
}

async function initManagementPage() {
    if (window.librowseAuthReady && !await window.librowseAuthReady) return;
    const expectedRole = document.body.dataset.managementRole;
    if (!await requireManagementRole(expectedRole)) return;

    renderSession();

    document.querySelectorAll(".management-nav button[data-tab]").forEach(button => {
        button.addEventListener("click", () => setActiveTab(button.dataset.tab));
    });

    document.getElementById("management-logout")?.addEventListener("click", logoutManagement);
    document.getElementById("marketplace-link")?.addEventListener("click", goBackToMarketplace);

    document.getElementById("users-search")?.addEventListener("input", renderUsersTable);
    document.getElementById("reports-forms-only")?.addEventListener("change", renderReportsTable);
    document.getElementById("book-form")?.addEventListener("submit", submitBookForm);
    document.getElementById("book-cancel-edit")?.addEventListener("click", resetBookForm);
    document.getElementById("category-form")?.addEventListener("submit", submitCategoryForm);
    document.getElementById("category-cancel-edit")?.addEventListener("click", resetCategoryForm);
    document.getElementById("record-form")?.addEventListener("submit", submitRecordForm);
    document.getElementById("staff-issue-form")?.addEventListener("submit", submitStaffIssue);

    setActiveTab("overview");
    showLoadingPlaceholders();

    try {
        await reloadCoreData();
        refreshRenderedData();
    } catch (error) {
        showMgmtAlert(`Unable to load management data: ${error.message}`, "error");
    }

    setInterval(async () => {
        if (!safeToAutoRefresh()) return;      // someone is editing — try again next time
        try {
            await reloadCoreData();
            refreshRenderedData();
        } catch (e) {
            console.warn("Auto-refresh failed:", e.message);
        }
    }, 30000);
}

document.addEventListener("DOMContentLoaded", initManagementPage);