/* LIBROWSE BOOK EXCHANGE - Admin & Staff Management JavaScript
   Shared by admin.html and staff.html. Which sections actually run is
   controlled by the page-level `PAGE_MODE` constant ("Admin" or "Staff"),
   set in a small inline <script> before this file is loaded. */

const API_BASE = "http://127.0.0.1:8000/api";

/* A generously high limit so management tables show every row instead
   of only the API's default page size of 50. */
const ALL_ROWS = "limit=1000";

let currentUser = null;

/* Reference data shared across sections, loaded once on init */
let allUsers = [];
let allCategories = [];
let allBooksCatalog = [];
let allUserBooks = [];

let userMap = {};
let categoryMap = {};
let bookMap = {};
let inventoryMap = {};

/* ==========================================================================
   SESSION / ACCESS CONTROL
   ========================================================================== */

function loadCurrentUser() {
    try {
        const stored = localStorage.getItem("librowseCurrentUser");
        currentUser = stored ? JSON.parse(stored) : null;
    } catch {
        currentUser = null;
    }
}

/** Where a given role's landing page is. */
function roleHomePage(role) {
    if (role === "Admin") return "admin.html";
    if (role === "Staff") return "staff.html";
    if (role === "Customer") return "index.html";
    return "login.html";
}

/**
 * Guards a management page: requires a logged-in user whose role is in
 * `allowedRoles`. Anyone else is sent to the right place instead of
 * being shown a broken page.
 */
function requireRole(allowedRoles) {
    if (!currentUser || !currentUser.user_id) {
        window.location.href = "login.html";
        return false;
    }

    if (currentUser.status === "Suspended" || currentUser.status === "Banned") {
        alert("Your account is " + currentUser.status.toLowerCase() + ". Please contact an administrator.");
        localStorage.removeItem("librowseCurrentUser");
        window.location.href = "login.html";
        return false;
    }

    if (!allowedRoles.includes(currentUser.role)) {
        alert("Access denied: this page requires " + allowedRoles.join(" or ") + " access.");
        window.location.href = roleHomePage(currentUser.role);
        return false;
    }

    return true;
}

function logout() {
    localStorage.removeItem("librowseCurrentUser");
    window.location.href = "login.html";
}

function renderTopBar() {
    const el = document.getElementById("auth-status");
    if (!el || !currentUser) return;

    el.innerHTML = `
        <span>Signed in as <strong>${escapeHTML(currentUser.username)}</strong> (${escapeHTML(currentUser.role)})</span>
        <button id="logout-button" type="button">Log out</button>
    `;

    document.getElementById("logout-button").addEventListener("click", logout);
}

/* ==========================================================================
   GENERIC HELPERS
   ========================================================================== */

async function apiRequest(endpoint, options = {}) {
    const url = `${API_BASE}/${endpoint}`;

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 8000);

    try {
        const response = await fetch(url, { ...options, signal: controller.signal });
        const text = await response.text();

        let data;
        try {
            data = text ? JSON.parse(text) : {};
        } catch {
            throw new Error("The server did not return valid JSON.");
        }

        if (!response.ok) {
            throw new Error(data.error || `Server returned error status ${response.status}`);
        }

        return data;
    } catch (error) {
        if (error.name === "AbortError") {
            error = new Error(`Request to ${url} timed out. Is the PHP server running (php -S 127.0.0.1:8000)?`);
        }
        console.error("API Error:", error);
        throw error;
    } finally {
        clearTimeout(timeoutId);
    }
}

function escapeHTML(str) {
    return String(str ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function showPageMessage(message, type = "info") {
    const el = document.getElementById("page-message");
    if (!el) return;

    if (!message) {
        el.style.display = "none";
        el.textContent = "";
        return;
    }

    el.className = "auth-message auth-" + type;
    el.textContent = message;
    el.style.display = "flex";

    if (type !== "error") {
        setTimeout(() => {
            if (el.textContent === message) {
                el.style.display = "none";
            }
        }, 4000);
    }
}

function formatDateTime(value) {
    if (!value) return "-";
    const d = new Date(value.replace(" ", "T"));
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString();
}

/** Current time as a MySQL-compatible `YYYY-MM-DD HH:MM:SS` string. */
function nowForMySQL() {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function formatPrice(price) {
    if (price === null || price === "" || price === undefined) return "Trade Only";
    return `₱${Number(price).toFixed(2)}`;
}

/** Turns `Pending Verification` / `In_transaction` / `User_Violation` into readable text. */
function humanizeEnum(value) {
    if (!value) return "-";
    return String(value).replace(/_/g, " ");
}

function badge(value, extraClass = "") {
    const cls = "badge badge-" + String(value || "").toLowerCase().replace(/[^a-z]+/g, "-");
    return `<span class="${cls} ${extraClass}">${escapeHTML(humanizeEnum(value))}</span>`;
}

function optionsHTML(values, selected) {
    return values
        .map((v) => `<option value="${v}" ${v === selected ? "selected" : ""}>${escapeHTML(humanizeEnum(v))}</option>`)
        .join("");
}

const STATUS_OPTIONS = ["Active", "Suspended", "Banned", "Pending Verification"];
const ROLE_OPTIONS = ["Customer", "Staff", "Admin"];
const TRANSACTION_STATUS_OPTIONS = ["Pending", "Accepted", "Completed", "Cancelled", "Disputed"];
const REFUND_STATUS_OPTIONS = ["Pending", "Approved", "Rejected"];
const REPORT_STATUS_OPTIONS = ["Pending", "Under_Review", "Approved", "Rejected", "Resolved", "Dismissed"];
const RESOLVED_STATUSES = ["Approved", "Rejected", "Resolved", "Dismissed"];

/* ==========================================================================
   REFERENCE DATA (shared lookups)
   ========================================================================== */

async function loadReferenceData() {
    const [users, categories, books, listings] = await Promise.all([
        apiRequest(`user.php?${ALL_ROWS}`),
        apiRequest(`book_categories.php?${ALL_ROWS}`),
        apiRequest(`books_catalog.php?${ALL_ROWS}`),
        apiRequest(`user_books.php?${ALL_ROWS}`),
    ]);

    allUsers = users;
    allCategories = categories;
    allBooksCatalog = books;
    allUserBooks = listings;

    userMap = {};
    users.forEach((u) => (userMap[u.user_id] = u));

    categoryMap = {};
    categories.forEach((c) => (categoryMap[c.category_id] = c.category_name));

    bookMap = {};
    books.forEach((b) => (bookMap[b.book_id] = b));

    inventoryMap = {};
    listings.forEach((l) => (inventoryMap[l.inventory_id] = l));
}

function usernameOf(userId) {
    const u = userMap[userId];
    return u ? u.username : userId ? `User #${userId}` : "-";
}

function listingLabel(inventoryId) {
    if (!inventoryId) return "-";
    const listing = inventoryMap[inventoryId];
    if (!listing) return `Listing #${inventoryId}`;
    const book = bookMap[listing.book_id];
    return book ? `${book.title} (#${inventoryId})` : `Listing #${inventoryId}`;
}

/* ==========================================================================
   OVERVIEW STATS
   ========================================================================== */

async function renderOverviewStats() {
    const grid = document.getElementById("stat-grid");
    if (!grid) return;

    try {
        const [reports, refunds, transactions] = await Promise.all([
            apiRequest(`reports.php?${ALL_ROWS}`),
            apiRequest(`refund_request.php?${ALL_ROWS}`),
            apiRequest(`transactions.php?${ALL_ROWS}`),
        ]);

        const pendingReports = reports.filter((r) => r.status === "Pending" || r.status === "Under_Review").length;
        const pendingRefunds = refunds.filter((r) => r.status === "Pending").length;
        const pendingTransactions = transactions.filter((t) => t.status === "Pending" || t.status === "Disputed").length;
        const activeCustomers = allUsers.filter((u) => u.role === "Customer" && u.status === "Active").length;

        const cards =
            PAGE_MODE === "Admin"
                ? [
                      { label: "Total Users", value: allUsers.length },
                      { label: "Active Customers", value: activeCustomers },
                      { label: "Book Listings", value: allUserBooks.length },
                      { label: "Pending Reports", value: pendingReports },
                      { label: "Pending / Disputed Transactions", value: pendingTransactions },
                  ]
                : [
                      { label: "Active Customers", value: activeCustomers },
                      { label: "Pending Forms & Reports", value: pendingReports },
                      { label: "Pending Refunds", value: pendingRefunds },
                      { label: "Pending / Disputed Transactions", value: pendingTransactions },
                  ];

        grid.innerHTML = cards
            .map((c) => `<div class="stat-card"><div class="stat-value">${c.value}</div><div class="stat-label">${escapeHTML(c.label)}</div></div>`)
            .join("");
    } catch (error) {
        grid.innerHTML = `<p class="checkbox-group-empty">Unable to load overview stats.</p>`;
    }
}

/* ==========================================================================
   USERS / CUSTOMERS MANAGEMENT
   (Admin: all users, full control. Staff: Customers only, status/permission.)
   ========================================================================== */

async function loadUsers() {
    const tbodyId = PAGE_MODE === "Admin" ? "users-table-body" : "customers-table-body";
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="7">Loading...</td></tr>`;

    try {
        const roleFilterEl = document.getElementById("user-role-filter");
        const statusFilterEl = document.getElementById("user-status-filter");
        const roleFilter = roleFilterEl ? roleFilterEl.value : "";
        const statusFilter = statusFilterEl ? statusFilterEl.value : "";

        let rows = allUsers;
        if (PAGE_MODE === "Staff") {
            rows = rows.filter((u) => u.role === "Customer");
        }
        if (roleFilter) {
            rows = rows.filter((u) => u.role === roleFilter);
        }
        if (statusFilter) {
            rows = rows.filter((u) => u.status === statusFilter);
        }

        renderUsersTable(rows, tbody);
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="7">Unable to load users.</td></tr>`;
    }
}

function renderUsersTable(rows, tbody) {
    if (rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7">No users found.</td></tr>`;
        return;
    }

    const isAdmin = PAGE_MODE === "Admin";

    tbody.innerHTML = rows
        .map((u) => {
            const roleCell = isAdmin
                ? `<select data-field="role" data-id="${u.user_id}">${optionsHTML(ROLE_OPTIONS, u.role)}</select>`
                : escapeHTML(u.role);

            const statusCell = `<select data-field="status" data-id="${u.user_id}">${optionsHTML(STATUS_OPTIONS, u.status)}</select>`;

            const permissionCell = !isAdmin
                ? `<textarea class="mini-textarea" data-field="permission" data-id="${u.user_id}" rows="2">${escapeHTML(
                      typeof u.permission === "string" ? u.permission : JSON.stringify(u.permission || {})
                  )}</textarea>`
                : "";

            const deleteButton = isAdmin
                ? `<button type="button" class="small-btn danger-btn" onclick="deleteUserRow(${u.user_id})">Delete</button>`
                : "";

            return `
                <tr>
                    <td>${u.user_id}</td>
                    <td>${escapeHTML(u.username)}</td>
                    <td>${escapeHTML(u.email)}</td>
                    <td>${roleCell}</td>
                    <td>${statusCell}</td>
                    ${isAdmin ? "" : `<td>${permissionCell}</td>`}
                    <td class="table-actions">
                        <button type="button" class="small-btn" onclick="saveUserRow(${u.user_id})">Save</button>
                        ${deleteButton}
                    </td>
                </tr>
            `;
        })
        .join("");
}

async function saveUserRow(userId) {
    const container = PAGE_MODE === "Admin" ? "users-table-body" : "customers-table-body";
    const tbody = document.getElementById(container);
    const payload = {};

    const roleSelect = tbody.querySelector(`select[data-field="role"][data-id="${userId}"]`);
    if (roleSelect) payload.role = roleSelect.value;

    const statusSelect = tbody.querySelector(`select[data-field="status"][data-id="${userId}"]`);
    if (statusSelect) payload.status = statusSelect.value;

    const permissionField = tbody.querySelector(`textarea[data-field="permission"][data-id="${userId}"]`);
    if (permissionField) {
        try {
            payload.permission = permissionField.value.trim() ? JSON.parse(permissionField.value) : {};
        } catch {
            showPageMessage("Permission field must be valid JSON.", "error");
            return;
        }
    }

    try {
        await apiRequest(`user.php?id=${userId}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        showPageMessage("User updated.", "success");
        await loadReferenceData();
        await loadUsers();
        await renderOverviewStats();
    } catch (error) {
        showPageMessage("Unable to update user: " + error.message, "error");
    }
}

async function deleteUserRow(userId) {
    if (!confirm("Permanently delete this user? This cannot be undone.")) return;

    try {
        await apiRequest(`user.php?id=${userId}`, { method: "DELETE" });
        showPageMessage("User deleted.", "success");
        await loadReferenceData();
        await loadUsers();
        await renderOverviewStats();
    } catch (error) {
        showPageMessage(
            "Unable to delete user: " + error.message + " (Users with existing books, listings, transactions or reports can't be removed due to database constraints — consider Banning them instead.)",
            "error"
        );
    }
}

/* ==========================================================================
   BOOK CATEGORIES MANAGEMENT (Admin only)
   ========================================================================== */

async function loadCategoriesSection() {
    const tbody = document.getElementById("categories-table-body");
    if (!tbody) return;

    if (allCategories.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4">No categories yet.</td></tr>`;
        return;
    }

    tbody.innerHTML = allCategories
        .map(
            (c) => `
                <tr>
                    <td>${c.category_id}</td>
                    <td><input type="text" data-field="category_name" data-id="${c.category_id}" value="${escapeHTML(c.category_name)}"></td>
                    <td><input type="text" data-field="description" data-id="${c.category_id}" value="${escapeHTML(c.description || "")}"></td>
                    <td class="table-actions">
                        <button type="button" class="small-btn" onclick="saveCategoryRow(${c.category_id})">Save</button>
                        <button type="button" class="small-btn danger-btn" onclick="deleteCategoryRow(${c.category_id})">Delete</button>
                    </td>
                </tr>
            `
        )
        .join("");
}

async function addCategory(event) {
    event.preventDefault();

    const nameInput = document.getElementById("new-category-name");
    const descInput = document.getElementById("new-category-description");

    const name = nameInput.value.trim();
    if (!name) {
        showPageMessage("Category name is required.", "error");
        return;
    }

    try {
        await apiRequest("book_categories.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                created_by_admin_id: currentUser.user_id,
                category_name: name,
                description: descInput.value.trim(),
            }),
        });
        showPageMessage("Category created.", "success");
        nameInput.value = "";
        descInput.value = "";
        await loadReferenceData();
        await loadCategoriesSection();
        await refreshBookCategoryOptions();
    } catch (error) {
        showPageMessage("Unable to create category: " + error.message, "error");
    }
}

async function saveCategoryRow(categoryId) {
    const tbody = document.getElementById("categories-table-body");
    const name = tbody.querySelector(`input[data-field="category_name"][data-id="${categoryId}"]`).value.trim();
    const description = tbody.querySelector(`input[data-field="description"][data-id="${categoryId}"]`).value.trim();

    try {
        await apiRequest(`book_categories.php?id=${categoryId}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ category_name: name, description }),
        });
        showPageMessage("Category updated.", "success");
        await loadReferenceData();
        await refreshBookCategoryOptions();
    } catch (error) {
        showPageMessage("Unable to update category: " + error.message, "error");
    }
}

async function deleteCategoryRow(categoryId) {
    if (!confirm("Delete this category? Books using it must be reassigned first.")) return;

    try {
        await apiRequest(`book_categories.php?id=${categoryId}`, { method: "DELETE" });
        showPageMessage("Category deleted.", "success");
        await loadReferenceData();
        await loadCategoriesSection();
        await refreshBookCategoryOptions();
    } catch (error) {
        showPageMessage("Unable to delete category: " + error.message + " (books still assigned to it must be moved first)", "error");
    }
}

/* ==========================================================================
   BOOKS CATALOG MANAGEMENT (Admin only)
   ========================================================================== */

function categoryMultiSelectHTML(idAttr, selectedIds) {
    const selected = new Set((selectedIds || []).map(Number));
    return `
        <select multiple class="category-multiselect" id="${idAttr}">
            ${allCategories
                .map((c) => `<option value="${c.category_id}" ${selected.has(c.category_id) ? "selected" : ""}>${escapeHTML(c.category_name)}</option>`)
                .join("")}
        </select>
    `;
}

function refreshBookCategoryOptions() {
    const select = document.getElementById("new-book-categories");
    if (select) {
        select.innerHTML = allCategories.map((c) => `<option value="${c.category_id}">${escapeHTML(c.category_name)}</option>`).join("");
    }
    return loadBooksCatalogSection();
}

function loadBooksCatalogSection() {
    const tbody = document.getElementById("books-table-body");
    if (!tbody) return;

    if (allBooksCatalog.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6">No books in the catalog yet.</td></tr>`;
        return;
    }

    tbody.innerHTML = allBooksCatalog
        .map(
            (b) => `
                <tr>
                    <td>${b.book_id}</td>
                    <td><input type="text" data-field="title" data-id="${b.book_id}" value="${escapeHTML(b.title)}"></td>
                    <td><input type="text" data-field="author" data-id="${b.book_id}" value="${escapeHTML(b.author)}"></td>
                    <td><input type="text" data-field="isbn" data-id="${b.book_id}" value="${escapeHTML(b.isbn)}"></td>
                    <td>${categoryMultiSelectHTML(`book-cats-${b.book_id}`, b.category_ids)}</td>
                    <td class="table-actions">
                        <button type="button" class="small-btn" onclick="saveBookRow(${b.book_id})">Save</button>
                        <button type="button" class="small-btn danger-btn" onclick="deleteBookRow(${b.book_id})">Delete</button>
                    </td>
                </tr>
            `
        )
        .join("");
}

function selectedOptionValues(selectEl) {
    return Array.from(selectEl.selectedOptions).map((o) => Number(o.value));
}

async function addBook(event) {
    event.preventDefault();

    const title = document.getElementById("new-book-title").value.trim();
    const author = document.getElementById("new-book-author").value.trim();
    const isbn = document.getElementById("new-book-isbn").value.trim();
    const categoryIds = selectedOptionValues(document.getElementById("new-book-categories"));

    if (!title || !author || !isbn) {
        showPageMessage("Title, author, and ISBN are all required.", "error");
        return;
    }
    if (categoryIds.length === 0) {
        showPageMessage("Select at least one category.", "error");
        return;
    }

    try {
        await apiRequest("books_catalog.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                title,
                author,
                isbn,
                category_ids: categoryIds,
                managed_by_admin_id: currentUser.user_id,
            }),
        });
        showPageMessage("Book added to catalog.", "success");
        document.getElementById("add-book-form").reset();
        await loadReferenceData();
        await loadBooksCatalogSection();
    } catch (error) {
        showPageMessage("Unable to add book: " + error.message, "error");
    }
}

async function saveBookRow(bookId) {
    const tbody = document.getElementById("books-table-body");
    const title = tbody.querySelector(`input[data-field="title"][data-id="${bookId}"]`).value.trim();
    const author = tbody.querySelector(`input[data-field="author"][data-id="${bookId}"]`).value.trim();
    const isbn = tbody.querySelector(`input[data-field="isbn"][data-id="${bookId}"]`).value.trim();
    const categoryIds = selectedOptionValues(document.getElementById(`book-cats-${bookId}`));

    if (categoryIds.length === 0) {
        showPageMessage("Select at least one category.", "error");
        return;
    }

    try {
        await apiRequest(`books_catalog.php?id=${bookId}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ title, author, isbn, category_ids: categoryIds }),
        });
        showPageMessage("Book updated.", "success");
        await loadReferenceData();
    } catch (error) {
        showPageMessage("Unable to update book: " + error.message, "error");
    }
}

async function deleteBookRow(bookId) {
    if (!confirm("Delete this book from the catalog? Existing listings referencing it must be removed first.")) return;

    try {
        await apiRequest(`books_catalog.php?id=${bookId}`, { method: "DELETE" });
        showPageMessage("Book deleted.", "success");
        await loadReferenceData();
        await loadBooksCatalogSection();
    } catch (error) {
        showPageMessage("Unable to delete book: " + error.message + " (listings referencing it must be removed first)", "error");
    }
}

/* ==========================================================================
   REPORTS & FORMS MANAGEMENT
   Admin sees every report. Staff sees two focused views:
     - "forms"      -> Verification_Form / Seller_Application
     - "moderation" -> User_Violation / Listing_Dispute / General_Feedback
   ========================================================================== */

let allReports = [];

async function loadReportsData() {
    allReports = await apiRequest(`reports.php?${ALL_ROWS}`);
}

function reportRowHTML(r, showDecideButtons) {
    const decideButtons = showDecideButtons
        ? `
            <button type="button" class="small-btn" onclick="decideReport(${r.report_id}, 'Approved')">Approve</button>
            <button type="button" class="small-btn danger-btn" onclick="decideReport(${r.report_id}, 'Rejected')">Reject</button>
          `
        : "";

    return `
        <tr>
            <td>${r.report_id}</td>
            <td>${escapeHTML(usernameOf(r.submitted_by_id))}</td>
            <td>${badge(r.report_category)}</td>
            <td>${humanizeEnum(r.related_entity_type)}</td>
            <td class="report-form-data">${escapeHTML(typeof r.form_data === "string" ? r.form_data : JSON.stringify(r.form_data))}</td>
            <td><select data-field="status" data-id="${r.report_id}">${optionsHTML(REPORT_STATUS_OPTIONS, r.status)}</select></td>
            <td><textarea class="mini-textarea" data-field="resolution_notes" data-id="${r.report_id}" rows="2">${escapeHTML(r.resolution_notes || "")}</textarea></td>
            <td>${formatDateTime(r.submitted_at)}</td>
            <td class="table-actions">
                <button type="button" class="small-btn" onclick="saveReportRow(${r.report_id})">Save</button>
                ${decideButtons}
            </td>
        </tr>
    `;
}

function renderReportsSection(containerId, rows, showDecideButtons) {
    const tbody = document.getElementById(containerId);
    if (!tbody) return;

    if (rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="9">Nothing here.</td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map((r) => reportRowHTML(r, showDecideButtons)).join("");
}

function loadAdminReportsSection() {
    const categoryFilterEl = document.getElementById("report-category-filter");
    const statusFilterEl = document.getElementById("report-status-filter");
    const categoryFilter = categoryFilterEl ? categoryFilterEl.value : "";
    const statusFilter = statusFilterEl ? statusFilterEl.value : "";

    let rows = allReports;
    if (categoryFilter) rows = rows.filter((r) => r.report_category === categoryFilter);
    if (statusFilter) rows = rows.filter((r) => r.status === statusFilter);

    renderReportsSection("reports-table-body", rows, false);
}

function loadStaffFormsSection() {
    const rows = allReports.filter((r) => r.report_category === "Verification_Form" || r.report_category === "Seller_Application");
    renderReportsSection("forms-table-body", rows, true);
}

function loadStaffModerationSection() {
    const rows = allReports.filter(
        (r) => r.report_category === "User_Violation" || r.report_category === "Listing_Dispute" || r.report_category === "General_Feedback"
    );
    renderReportsSection("moderation-table-body", rows, false);
}

async function persistReportDecision(reportId, status, resolutionNotes) {
    const payload = {
        status,
        reviewed_by_id: currentUser.user_id,
        resolution_notes: resolutionNotes,
    };
    if (RESOLVED_STATUSES.includes(status)) {
        payload.resolved_at = nowForMySQL();
    }

    await apiRequest(`reports.php?id=${reportId}`, {
        method: "PUT",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
    });
}

async function findReportRowContainer(reportId) {
    return (
        document.querySelector(`#reports-table-body select[data-id="${reportId}"]`)?.closest("tbody") ||
        document.querySelector(`#forms-table-body select[data-id="${reportId}"]`)?.closest("tbody") ||
        document.querySelector(`#moderation-table-body select[data-id="${reportId}"]`)?.closest("tbody")
    );
}

async function saveReportRow(reportId) {
    const tbody = await findReportRowContainer(reportId);
    if (!tbody) return;

    const status = tbody.querySelector(`select[data-field="status"][data-id="${reportId}"]`).value;
    const notes = tbody.querySelector(`textarea[data-field="resolution_notes"][data-id="${reportId}"]`).value.trim();

    try {
        await persistReportDecision(reportId, status, notes);
        showPageMessage("Report updated.", "success");
        await refreshReportsEverywhere();
    } catch (error) {
        showPageMessage("Unable to update report: " + error.message, "error");
    }
}

async function decideReport(reportId, status) {
    try {
        await persistReportDecision(reportId, status, status === "Approved" ? "Approved by staff." : "Rejected by staff.");
        showPageMessage(`Form ${status.toLowerCase()}.`, "success");
        await refreshReportsEverywhere();
    } catch (error) {
        showPageMessage("Unable to update form: " + error.message, "error");
    }
}

async function refreshReportsEverywhere() {
    await loadReportsData();
    if (PAGE_MODE === "Admin") {
        loadAdminReportsSection();
    } else {
        loadStaffFormsSection();
        loadStaffModerationSection();
    }
    renderOverviewStats();
}

/* ==========================================================================
   TRANSACTIONS MONITORING (Admin + Staff)
   ========================================================================== */

async function loadTransactionsSection() {
    const tbodyId = PAGE_MODE === "Admin" ? "admin-transactions-body" : "staff-transactions-body";
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    try {
        const transactions = await apiRequest(`transactions.php?${ALL_ROWS}`);
        if (transactions.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8">No transactions yet.</td></tr>`;
            return;
        }

        tbody.innerHTML = transactions
            .map(
                (t) => `
                    <tr>
                        <td>${t.transaction_id}</td>
                        <td>${escapeHTML(usernameOf(t.buyer_id))}</td>
                        <td>${escapeHTML(listingLabel(t.requested_inventory_id))}</td>
                        <td>${escapeHTML(listingLabel(t.offered_inventory_id))}</td>
                        <td>${humanizeEnum(t.transaction_type)}</td>
                        <td>${formatPrice(t.amount_paid)}</td>
                        <td><select data-field="status" data-id="${t.transaction_id}">${optionsHTML(TRANSACTION_STATUS_OPTIONS, t.status)}</select></td>
                        <td class="table-actions">
                            <button type="button" class="small-btn" onclick="saveTransactionRow(${t.transaction_id})">Save</button>
                        </td>
                    </tr>
                `
            )
            .join("");
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="8">Unable to load transactions.</td></tr>`;
    }
}

async function saveTransactionRow(transactionId) {
    const tbodyId = PAGE_MODE === "Admin" ? "admin-transactions-body" : "staff-transactions-body";
    const tbody = document.getElementById(tbodyId);
    const status = tbody.querySelector(`select[data-field="status"][data-id="${transactionId}"]`).value;

    try {
        await apiRequest(`transactions.php?id=${transactionId}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ status, managed_by_staff_id: currentUser.user_id }),
        });
        showPageMessage("Transaction updated.", "success");
        await loadTransactionsSection();
        await renderOverviewStats();
    } catch (error) {
        showPageMessage("Unable to update transaction: " + error.message, "error");
    }
}

/* ==========================================================================
   REFUND REQUESTS (Staff)
   ========================================================================== */

async function loadRefundsSection() {
    const tbody = document.getElementById("refunds-table-body");
    if (!tbody) return;

    try {
        const refunds = await apiRequest(`refund_request.php?${ALL_ROWS}`);
        if (refunds.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7">No refund requests yet.</td></tr>`;
            return;
        }

        tbody.innerHTML = refunds
            .map(
                (r) => `
                    <tr>
                        <td>${r.refund_id}</td>
                        <td>#${r.transaction_id}</td>
                        <td>${escapeHTML(usernameOf(r.customer_id))}</td>
                        <td class="report-form-data">${escapeHTML(r.reason)}</td>
                        <td>${badge(r.status)}</td>
                        <td>${formatDateTime(r.requested_at)}</td>
                        <td class="table-actions">
                            ${
                                r.status === "Pending"
                                    ? `
                                        <button type="button" class="small-btn" onclick="decideRefund(${r.refund_id}, 'Approved')">Approve</button>
                                        <button type="button" class="small-btn danger-btn" onclick="decideRefund(${r.refund_id}, 'Rejected')">Reject</button>
                                      `
                                    : "-"
                            }
                        </td>
                    </tr>
                `
            )
            .join("");
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="7">Unable to load refund requests.</td></tr>`;
    }
}

async function decideRefund(refundId, status) {
    try {
        await apiRequest(`refund_request.php?id=${refundId}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ status, processed_by_staff_id: currentUser.user_id }),
        });
        showPageMessage(`Refund request ${status.toLowerCase()}.`, "success");
        await loadRefundsSection();
        await renderOverviewStats();
    } catch (error) {
        showPageMessage("Unable to update refund request: " + error.message, "error");
    }
}

/* ==========================================================================
   SYSTEM RECORDS (Admin only)
   ========================================================================== */

async function loadSystemRecordsSection() {
    const tbody = document.getElementById("records-table-body");
    if (!tbody) return;

    try {
        const records = await apiRequest(`system_records.php?${ALL_ROWS}`);
        if (records.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5">No system records yet.</td></tr>`;
            return;
        }

        tbody.innerHTML = records
            .map(
                (r) => `
                    <tr>
                        <td>${r.record_id}</td>
                        <td>${escapeHTML(usernameOf(r.admin_id))}</td>
                        <td>${badge(r.record_type)}</td>
                        <td class="report-form-data">${escapeHTML(typeof r.details === "string" ? r.details : JSON.stringify(r.details))}</td>
                        <td>${formatDateTime(r.created_at)}</td>
                    </tr>
                `
            )
            .join("");
    } catch (error) {
        tbody.innerHTML = `<tr><td colspan="5">Unable to load system records.</td></tr>`;
    }
}

async function addSystemRecord(event) {
    event.preventDefault();

    const type = document.getElementById("new-record-type").value;
    const detailsText = document.getElementById("new-record-details").value.trim();

    let details = {};
    if (detailsText) {
        try {
            details = JSON.parse(detailsText);
        } catch {
            showPageMessage("Details must be valid JSON (or leave blank).", "error");
            return;
        }
    }

    try {
        await apiRequest("system_records.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ admin_id: currentUser.user_id, record_type: type, details }),
        });
        showPageMessage("Record added.", "success");
        document.getElementById("add-record-form").reset();
        await loadSystemRecordsSection();
    } catch (error) {
        showPageMessage("Unable to add record: " + error.message, "error");
    }
}

/* ==========================================================================
   STAFF: REPORT AN ISSUE TO ADMINISTRATION
   (No dedicated ticketing table exists in the schema, so this reuses
   REPORTS with category General_Feedback, submitted by the staff member.)
   ========================================================================== */

async function submitStaffIssueReport(event) {
    event.preventDefault();

    const details = document.getElementById("issue-details").value.trim();
    if (!details) {
        showPageMessage("Please describe the issue.", "error");
        return;
    }

    try {
        await apiRequest("reports.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                submitted_by_id: currentUser.user_id,
                report_category: "General_Feedback",
                related_entity_type: "None",
                form_data: { source: "staff_issue_report", details },
                status: "Pending",
            }),
        });
        showPageMessage("Issue reported to Administration.", "success");
        document.getElementById("report-issue-form").reset();
    } catch (error) {
        showPageMessage("Unable to submit report: " + error.message, "error");
    }
}

/* ==========================================================================
   PAGE INITIALIZATION
   ========================================================================== */

async function initAdminPage() {
    loadCurrentUser();
    if (!requireRole(["Admin"])) return;

    renderTopBar();
    await loadReferenceData();
    await loadReportsData();

    populateFilterOptions();
    await Promise.all([
        renderOverviewStats(),
        loadUsers(),
        loadCategoriesSection(),
        loadTransactionsSection(),
        loadSystemRecordsSection(),
    ]);
    loadAdminReportsSection();
    refreshBookCategoryOptions();

    bindCommonFilterEvents();

    document.getElementById("add-category-form")?.addEventListener("submit", addCategory);
    document.getElementById("add-book-form")?.addEventListener("submit", addBook);
    document.getElementById("add-record-form")?.addEventListener("submit", addSystemRecord);
}

async function initStaffPage() {
    loadCurrentUser();
    if (!requireRole(["Staff"])) return;

    renderTopBar();
    await loadReferenceData();
    await loadReportsData();

    await Promise.all([renderOverviewStats(), loadUsers(), loadTransactionsSection(), loadRefundsSection()]);
    loadStaffFormsSection();
    loadStaffModerationSection();

    document.getElementById("report-issue-form")?.addEventListener("submit", submitStaffIssueReport);
}

function populateFilterOptions() {
    const roleFilter = document.getElementById("user-role-filter");
    if (roleFilter) roleFilter.innerHTML = `<option value="">All roles</option>` + optionsHTML(ROLE_OPTIONS, "");

    const statusFilter = document.getElementById("user-status-filter");
    if (statusFilter) statusFilter.innerHTML = `<option value="">All statuses</option>` + optionsHTML(STATUS_OPTIONS, "");

    const categoryFilter = document.getElementById("report-category-filter");
    if (categoryFilter) {
        const cats = ["Verification_Form", "Seller_Application", "User_Violation", "Listing_Dispute", "General_Feedback"];
        categoryFilter.innerHTML = `<option value="">All categories</option>` + optionsHTML(cats, "");
    }

    const reportStatusFilter = document.getElementById("report-status-filter");
    if (reportStatusFilter) reportStatusFilter.innerHTML = `<option value="">All statuses</option>` + optionsHTML(REPORT_STATUS_OPTIONS, "");
}

function bindCommonFilterEvents() {
    document.getElementById("user-role-filter")?.addEventListener("change", loadUsers);
    document.getElementById("user-status-filter")?.addEventListener("change", loadUsers);
    document.getElementById("report-category-filter")?.addEventListener("change", loadAdminReportsSection);
    document.getElementById("report-status-filter")?.addEventListener("change", loadAdminReportsSection);
}

document.addEventListener("DOMContentLoaded", function () {
    if (typeof PAGE_MODE === "undefined") return;
    if (PAGE_MODE === "Admin") {
        initAdminPage();
    } else if (PAGE_MODE === "Staff") {
        initStaffPage();
    }
});
