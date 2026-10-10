/* LIBROWSE BOOK EXCHANGE - Main JavaScript */


/* BACK-END LOCATION */

/*
   HTML Live Preview runs separately from PHP.
   PHP back-end will run at: http://127.0.0.1:8000/
   API files are served from: /api/
*/
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

/* Escape text before putting it into HTML. Titles, authors, names and
   reasons are typed by users, so they must never be inserted raw. */
function escapeHTML(value) {
    return String(value ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
}


/* GLOBAL DATA */

/* Temporary storage for API data */

let bookListings = [];

let booksCatalog = [];

let users = [];

let transactions = [];


/* Quick lookup maps for IDs */

let bookMap = {};

let userMap = {};

let inventoryMap = {};

let categoryMap = {};

let currentUser = null;
const SESSION_TOKEN_KEY = "librowseSessionToken";
const SESSION_USER_KEY = "librowseCurrentUser";


/* AUTH HELPERS */

function loadCurrentUser() {

    try {

        const stored =
            sessionStorage.getItem(
                SESSION_USER_KEY
            );

        currentUser =
            stored ? JSON.parse(stored) : null;

    } catch {

        currentUser = null;

    }

}


function updateAuthStatusUI() {

    const authStatus =
        document.getElementById(
            "auth-status"
        );


    if (!authStatus) {
        return;
    }


    if (!currentUser || !sessionStorage.getItem(SESSION_TOKEN_KEY)) {

        authStatus.innerHTML = `
            <a href="login.html">
                Sign in
            </a>
            <a href="register.html">
                Create account
            </a>
        `;

        return;

    }


    authStatus.innerHTML = `
        <span>
            Signed in as <strong>${escapeHTML(currentUser.username)}</strong>
        </span>

        <button id="logout-button" type="button">
            Log out
        </button>
    `;


    const logoutButton =
        document.getElementById(
            "logout-button"
        );


    if (logoutButton) {

        logoutButton.addEventListener(
            "click",
            async function () {
                if (window.librowseAuth) {
                    await window.librowseAuth.logout();
                }
                currentUser = null;
                updateAuthStatusUI();
                alert("You have been logged out.");
                window.location.replace("login.html");
            }
        );

    }

}


function requireAuthenticatedCustomer() {

    if (!currentUser) {

        alert(
            "Please login or register first."
        );

        window.location.href =
            "login.html";

        return false;

    }


    if (currentUser.role !== "Customer") {

        alert(
            "This page currently supports Customer accounts only."
        );

        window.location.href =
            "login.html";

        return false;

    }


    return true;

}


function applyCurrentUserToForms() {

    if (!currentUser) {
        return;
    }


    const customerIdInput =
        document.getElementById(
            "customer-id"
        );

    if (customerIdInput) {
        customerIdInput.value =
            currentUser.user_id;

        customerIdInput.readOnly = true;
    }


    const submittedByInput =
        document.getElementById(
            "submitted-by"
        );

    if (submittedByInput) {
        submittedByInput.value =
            currentUser.user_id;

        submittedByInput.readOnly = true;
    }

}


/* GENERAL API FUNCTION */

/* Handles all communication with PHP back-end */
/*
   GET    = retrieve data
   POST   = create data
   PUT    = update data
   DELETE = remove data
*/

async function apiRequest(endpoint, options = {}) {

    const url = `${API_BASE}/${endpoint}`;

    /* Abort the request if the server takes too long to respond, so the
       UI shows an error instead of hanging on "Loading..." forever */

    const controller = new AbortController();

    /* Serverless cold starts plus a remote database cost several seconds before
       any query runs, so a write needs a far longer window than a read. */
    const isWrite = String(options.method || "GET").toUpperCase() !== "GET";

    const timeoutId = setTimeout(function () {
        controller.abort();
    }, isWrite ? 30000 : 15000);

    try {

        const token = sessionStorage.getItem(SESSION_TOKEN_KEY);
        const requestHeaders = new Headers(options.headers || {});
        if (token) requestHeaders.set("Authorization", `Bearer ${token}`);
        requestHeaders.set("Cache-Control", "no-store");

        const response = await fetch(url, {
            ...options,
            headers: requestHeaders,
            signal: controller.signal,
            cache: "no-store"
        });

        /* PHP API returns JSON */
        const text = await response.text();

        let data;

        try {

            data = JSON.parse(text);

        } catch {

            throw new Error(
                "The server did not return valid JSON."
            );

        }


        if (response.status === 401) {
            if (window.librowseAuth) window.librowseAuth.clearSession();
            else { sessionStorage.removeItem(SESSION_TOKEN_KEY); sessionStorage.removeItem(SESSION_USER_KEY); }
            window.location.replace("login.html");
            throw new Error("Your session has expired. Please sign in again.");
        }

        /* response.ok = success */
        if (!response.ok) {

            throw new Error(
                data.error || "Something went wrong."
            );

        }

        return data;

    } catch (error) {

        /* No reply (timeout or connection lost). For a save, we can't know
           whether the server received it — say so honestly. Trying again is
           safe: the same Idempotency-Key is reused, so it is never saved twice. */
        const method = String(options.method || "GET").toUpperCase();
        if (error.name === "AbortError" || error instanceof TypeError) {
            console.warn("No reply from", url, error);
            error = new Error(method === "GET"
                ? "Librowse is taking too long to respond. Check your connection and try again."
                : "We didn't get a reply from Librowse, so we can't tell if this went through. Check your list before trying again — trying again is safe and won't submit it twice.");
        }

        console.error(
            "API Error:",
            error
        );

        throw error;

    } finally {

        clearTimeout(timeoutId);

    }

}


/* FORMAT FUNCTIONS */

/* Convert database values to readable text */
/*
   Example: For_sale becomes For Sale
*/

function formatListingType(type) {

    if (type === "For_sale") {
        return "For Sale";
    }

    if (type === "For_trade") {
        return "For Trade";
    }

    if (type === "Both") {
        return "Sale / Trade";
    }

    return type;

}


/* Format price to Philippine Peso */
/*
   Example: 500 becomes ₱500.00
*/

function formatPrice(price) {

    if (
        price === null ||
        price === "" ||
        price === undefined
    ) {

        return "Trade Only";

    }

    const amount = Number(price);

    return `₱${amount.toFixed(2)}`;

}


/* LOAD BOOKS */

/* Fetch books, catalog, and user data from API */

async function loadBooks() {
    const bookList = document.getElementById("book-list");
    try {
        if (bookList) {
            bookList.innerHTML = `<tr class="loading-row"><td colspan="10"><span class="inline-spinner" aria-hidden="true"></span> Loading books…</td></tr>`;
        }
        const results = await Promise.all([
            apiRequest("user_books.php"),
            apiRequest("books_catalog.php"),
            apiRequest("user.php")
        ]);
        bookListings = results[0];
        booksCatalog = results[1];
        users = results[2];
        bookMap = {};
        userMap = {};
        inventoryMap = {};
        booksCatalog.forEach(book => { bookMap[book.book_id] = book; });
        users.forEach(user => { userMap[user.user_id] = user; });
        bookListings.forEach(listing => { inventoryMap[listing.inventory_id] = listing; });
        if (bookList) renderBooks(bookListings);
    } catch (error) {
        console.error(error);
        const msg = "Couldn't load books right now.";
        if (bookList) bookList.innerHTML = `<tr><td colspan="10">${msg} Please refresh the page.</td></tr>`;
        const shelf = document.getElementById("bookshelf");
        if (shelf) {
            shelf.innerHTML = `<div class="shelf-empty"><h3>${msg}</h3><p>Check your connection, then try again.</p>
                <button type="button" id="shelf-retry">Try again</button></div>`;
            document.getElementById("shelf-retry")?.addEventListener("click", async () => {
                shelf.innerHTML = '<span class="shelf-skeleton"></span>'.repeat(6);
                await loadBooks();
                if (typeof filterBooks === "function") filterBooks();
            });
        }
        const count = document.getElementById("shelf-count");
        if (count) count.textContent = msg;
    }
}

/* DISPLAY BOOKS */

function renderBooks(listings) {

    const bookList =
        document.getElementById("book-list");


    /* No books found */

    if (listings.length === 0) {

        bookList.innerHTML = `
            <tr>
                <td colspan="10">
                    No books found.
                </td>
            </tr>
        `;

        return;

    }


    /* Clear previous contents */

    bookList.innerHTML = "";


    /* Loop through listings */

    listings.forEach(function (listing) {

        const book =
            bookMap[listing.book_id];

        const seller =
            userMap[listing.seller_id];


        /* Use fallback if no match */

        const title =
            book ? book.title : "Unknown Book";

        const author =
            book ? book.author : "Unknown Author";

        const categoryNames =
            book ? formatCategoryNames(book) : "Uncategorized";

        const sellerName =
            seller
                ? seller.username
                : `User #${listing.seller_id}`;


        /* Create table row */

        const row =
            document.createElement("tr");


        row.innerHTML = `

            <td>
                ${listing.book_id}
            </td>

            <td>
                ${escapeHTML(title)}
            </td>

            <td>
                ${escapeHTML(author)}
            </td>

            <td>
                ${escapeHTML(categoryNames)}
            </td>

            <td>
                ${escapeHTML(sellerName)}
            </td>

            <td>
                ${formatListingType(
            listing.listing_type
        )}
            </td>

            <td>
                ${escapeHTML(listing.condition)}
            </td>

            <td>
                ${formatPrice(
            listing.price
        )}
            </td>

            <td>
                ${escapeHTML(String(listing.status).replace('_', ' '))}
            </td>

            <td class="book-actions"></td>

        `;


        /* Show action buttons only when available */

        const actionCell =
            row.querySelector(".book-actions");


        const isOwnListing = currentUser && Number(listing.seller_id) === Number(currentUser.user_id);
        if (isOwnListing) {
            actionCell.textContent = "Your listing";
        } else if (listing.status === "Available") {

            /* Show Buy button if available for purchase */

            if (
                listing.listing_type === "For_sale" ||
                listing.listing_type === "Both"
            ) {

                const buyButton =
                    document.createElement("button");

                buyButton.textContent = "Buy";

                buyButton.addEventListener("click", async function () {
                if (buyButton.disabled) return;
                buyButton.disabled = true;
                buyButton.textContent = "Requesting…";
                try { await buyBook(listing); }
                finally { if (buyButton.isConnected) { buyButton.disabled = false; buyButton.textContent = "Buy"; } }
            });

                actionCell.appendChild(
                    buyButton
                );

            }


            /* Show Trade button if available for trade */

            if (
                listing.listing_type === "For_trade" ||
                listing.listing_type === "Both"
            ) {

                const tradeButton =
                    document.createElement("button");

                tradeButton.textContent = "Trade";

                tradeButton.addEventListener("click", async function () {
                if (tradeButton.disabled) return;
                tradeButton.disabled = true;
                tradeButton.textContent = "Requesting…";
                try { await tradeBook(listing); }
                finally { if (tradeButton.isConnected) { tradeButton.disabled = false; tradeButton.textContent = "Trade"; } }
            });

                actionCell.appendChild(
                    tradeButton
                );

            }

        } else {

            actionCell.textContent =
                "Not Available";

        }


        /* Add row to table */

        bookList.appendChild(row);

    });

}


/* SEARCH AND FILTER BOOKS */

function filterBooks() {

    const searchValue =
        document
            .getElementById("search-book")
            .value
            .toLowerCase()
            .trim();


    const listingType =
        document
            .getElementById("filter-type")
            .value;


    const condition =
        document
            .getElementById("filter-condition")
            .value;


    const selectedCategoryIds =
        getSelectedFilterCategoryIds();


    const filtered =
        bookListings.filter(
            function (listing) {

                const book =
                    bookMap[listing.book_id];


                const title =
                    book
                        ? book.title.toLowerCase()
                        : "";


                const author =
                    book
                        ? book.author.toLowerCase()
                        : "";


                /* Check search text */

                const matchesSearch =

                    title.includes(searchValue) ||

                    author.includes(searchValue);


                /* Check listing type */

                const matchesType =

                    listingType === "" ||

                    listing.listing_type ===
                    listingType;


                /* Check condition */

                const matchesCondition =

                    condition === "" ||

                    listing.condition ===
                    condition;


                /* Check category - book must have at least one of the
                   checked categories (no boxes checked = match everything) */

                const bookCategoryIds =
                    (book && Array.isArray(book.category_ids))
                        ? book.category_ids
                        : [];

                const matchesCategory =

                    selectedCategoryIds.length === 0 ||

                    bookCategoryIds.some(function (categoryId) {
                        return selectedCategoryIds.includes(categoryId);
                    });


                /* Book must pass all filters */

                return (
                    matchesSearch &&
                    matchesType &&
                    matchesCondition &&
                    matchesCategory
                );

            }
        );


    renderBooks(filtered);

}


/* LOAD BOOK CATEGORIES (for the List a Book form) */
/* Rendered as checkboxes, since a book can belong to more than one category */

/* Builds a row of checkboxes for one category list inside a container */

function renderCategoryCheckboxes(container, categories, idPrefix) {

    if (categories.length === 0) {

        container.innerHTML = `
            <p class="checkbox-group-empty">
                No categories available.
            </p>
        `;

        return;

    }

    container.innerHTML = "";

    categories.forEach(function (category) {

        const optionWrapper =
            document.createElement("label");

        optionWrapper.className =
            "checkbox-option";

        const checkbox =
            document.createElement("input");

        checkbox.type = "checkbox";
        checkbox.name = "category_ids";
        checkbox.value = category.category_id;
        checkbox.id = `${idPrefix}-${category.category_id}`;

        optionWrapper.appendChild(checkbox);

        optionWrapper.appendChild(
            document.createTextNode(
                ` ${category.category_name}`
            )
        );

        container.appendChild(optionWrapper);

    });

}


async function loadCategories() {

    const categoryOptions =
        document.getElementById(
            "book-category-options"
        );

    const filterCategoryOptions =
        document.getElementById(
            "filter-category-options"
        );

    if (!categoryOptions && !filterCategoryOptions) {
        return;
    }

    try {

        const categories =
            await apiRequest(
                "book_categories.php"
            );

        /* Keep a lookup of category_id -> category_name for display
           elsewhere (e.g. the browse table) */

        categoryMap = {};

        categories.forEach(function (category) {

            categoryMap[category.category_id] =
                category.category_name;

        });


        if (categoryOptions) {

            renderCategoryCheckboxes(
                categoryOptions,
                categories,
                "book-category"
            );

        }


        if (filterCategoryOptions) {

            renderCategoryCheckboxes(
                filterCategoryOptions,
                categories,
                "filter-category"
            );

            /* Re-run the search whenever a filter checkbox is toggled */

            filterCategoryOptions
                .querySelectorAll('input[name="category_ids"]')
                .forEach(function (checkbox) {

                    checkbox.addEventListener(
                        "change",
                        filterBooks
                    );

                });

        }

    } catch (error) {

        const message = `
            <p class="checkbox-group-empty">
                Unable to load categories.
            </p>
        `;

        if (categoryOptions) {
            categoryOptions.innerHTML = message;
        }

        if (filterCategoryOptions) {
            filterCategoryOptions.innerHTML = message;
        }

        console.error(
            "Unable to load categories.",
            error
        );

    }

}


/* Reads the checked category checkboxes from the List a Book form */

function getSelectedCategoryIds() {

    const checkedBoxes =
        document.querySelectorAll(
            '#book-category-options input[name="category_ids"]:checked'
        );

    return Array.from(checkedBoxes).map(function (checkbox) {
        return Number(checkbox.value);
    });

}


/* Reads the checked category checkboxes from the Browse Books filters */

function getSelectedFilterCategoryIds() {

    const checkedBoxes =
        document.querySelectorAll(
            '#filter-category-options input[name="category_ids"]:checked'
        );

    return Array.from(checkedBoxes).map(function (checkbox) {
        return Number(checkbox.value);
    });

}


/* Turns a book's category_ids into a readable, comma-separated list
   of category names, using the categoryMap loaded above */

function formatCategoryNames(book) {

    if (!book || !Array.isArray(book.category_ids) || book.category_ids.length === 0) {
        return "Uncategorized";
    }

    return book.category_ids
        .map(function (categoryId) {
            return categoryMap[categoryId] || `Category #${categoryId}`;
        })
        .join(", ");

}


/* LIST A BOOK */

async function submitBookListing(event) {

    /* Prevent page refresh on submit */

    event.preventDefault();


    /* Must be logged in - seller ID comes from the session, never from a text field */

    if (!requireAuthenticatedCustomer()) {
        return;
    }


    const title =
        document
            .getElementById("book-title")
            .value
            .trim();


    const author =
        document
            .getElementById("book-author")
            .value
            .trim();


    const isbn =
        document
            .getElementById("book-isbn")
            .value
            .trim();


    const categoryIds =
        getSelectedCategoryIds();


    const listingType =
        document
            .getElementById("listing-type")
            .value;


    const price =
        document
            .getElementById("price")
            .value;


    const condition =
        document
            .getElementById("book-condition")
            .value;


    if (categoryIds.length === 0) {

        alert(
            "Please select at least one category."
        );

        return;

    }


    /* Sale listings require a price */

    if (
        (
            listingType === "For_sale" ||
            listingType === "Both"
        ) &&
        price === ""
    ) {

        alert(
            "Please enter a price for this listing."
        );

        return;

    }


    try {

        /* Step 1: create a new BOOKS_CATALOG entry for this title/author.
           Every listing creates its own catalog row - nothing here is
           limited to books that already exist in the database. */

        const catalogData = {

            category_ids:
                categoryIds,

            managed_by_admin_id:
                currentUser.user_id,

            title:
                title,

            author:
                author,

            isbn:
                isbn

        };

        const newBook =
            await apiRequest(
                "books_catalog.php",
                {

                    method: "POST",

                    headers: {
                        "Content-Type":
                            "application/json"
                    },

                    body:
                        JSON.stringify(
                            catalogData
                        )

                }
            );


        /* Step 2: create the USER_BOOKS listing, pointing at the book
           we just created and the currently logged-in seller */

        const listingData = {

            book_id:
                newBook.book_id,

            seller_id:
                currentUser.user_id,

            listing_type:
                listingType,

            condition:
                condition,

            status:
                "Available"

        };

        /* Attach the cover photo chosen on the form (if any) */

        if (window.librowsePendingCover) {

            listingData.cover_image =
                window.librowsePendingCover;

        }

        /* Include price only if entered */

        if (price !== "") {

            listingData.price =
                Number(price);

        }

        const newListing =
            await apiRequest(
                "user_books.php",
                {

                    method: "POST",

                    headers: {
                        "Content-Type":
                            "application/json"
                    },

                    body:
                        JSON.stringify(
                            listingData
                        )

                }
            );


        alert(
            "Book listed successfully!"
        );


        /* Show the newly created listing back to the user */

        showNewListingResult(
            newListing,
            newBook
        );


        /* Clear form after submission */

        document
            .getElementById(
                "list-book-form"
            )
            .reset();


        /* Refresh table to show new listing */

        await loadBooks();


    } catch (error) {

        alert(
            "Unable to list book.\n\n" +
            error.message
        );

    }

}


/* DISPLAY THE LISTING JUST CREATED */

function showNewListingResult(listing, book) {

    const resultPanel =
        document.getElementById(
            "new-listing-result"
        );

    const detailsBody =
        document.getElementById(
            "new-listing-details"
        );

    if (!resultPanel || !detailsBody) {
        return;
    }

    const rows = [

        ["Inventory ID", listing.inventory_id],
        ["Book ID", listing.book_id],
        ["Seller ID", listing.seller_id],
        ["Title", book.title],
        ["Author", book.author],
        ["ISBN", book.isbn],
        ["Categories", formatCategoryNames(book)],
        ["Listing Type", formatListingType(listing.listing_type)],
        ["Condition", listing.condition],
        ["Price", formatPrice(listing.price)],
        ["Status", listing.status]

    ];

    detailsBody.innerHTML =
        rows
            .map(function (row) {

                return `
                    <tr>
                        <th>${escapeHTML(row[0])}</th>
                        <td>${escapeHTML(row[1])}</td>
                    </tr>
                `;

            })
            .join("");

    resultPanel.style.display = "block";

}


/* BUY A BOOK */

async function buyBook(listing) {

    if (!requireAuthenticatedCustomer()) {
        return;
    }

    const buyerId =
        String(currentUser.user_id);


    if (!buyerId) {
        return;
    }


    const confirmed =
        confirm(
            "Do you want to purchase this book?"
        );


    if (!confirmed) {
        return;
    }


    const transactionData = {

        buyer_id:
            Number(buyerId),

        requested_inventory_id:
            Number(
                listing.inventory_id
            ),

        transaction_type:
            "Purchase",

        amount_paid:
            listing.price
                ? Number(listing.price)
                : 0,

        status:
            "Pending"

    };


    try {

        const txCreated = await apiRequest(
            "transactions.php",
            {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(transactionData)
            }
        );

        // Redirect to checkout for payment
        const txId = txCreated && txCreated.transaction_id ? txCreated.transaction_id : null;
        if (txId) {
            window.location.href = `checkout.html?tx=${txId}`;
            return;
        }

        // Fallback if server didn't return an id
        alert("Purchase request submitted! Proceed to pay from your Orders page.");
        if (document.getElementById("book-list")) { await loadBooks(); filterBooks(); }
        await loadTransactions();


    } catch (error) {

        alert(
            "Unable to create transaction.\n\n" +
            error.message
        );

    }

}


/* TRADE A BOOK */

async function tradeBook(listing) {

    if (!requireAuthenticatedCustomer()) {
        return;
    }

    const buyerId =
        String(currentUser.user_id);


    if (!buyerId) {
        return;
    }


    /* Trade requires offering own inventory item */

    const offeredInventoryId =
        prompt(
            "Enter the Inventory ID of the book you want to offer:"
        );


    if (!offeredInventoryId) {
        return;
    }


    const transactionData = {

        buyer_id:
            Number(buyerId),

        requested_inventory_id:
            Number(
                listing.inventory_id
            ),

        offered_inventory_id:
            Number(
                offeredInventoryId
            ),

        transaction_type:
            "Trade",

        amount_paid:
            0,

        status:
            "Pending"

    };


    try {

        await apiRequest(
            "transactions.php",
            {

                method: "POST",

                headers: {
                    "Content-Type":
                        "application/json"
                },

                body:
                    JSON.stringify(
                        transactionData
                    )

            }
        );


        alert(
            "Trade request submitted!"
        );


        if (document.getElementById("book-list")) { await loadBooks(); filterBooks(); }
        await loadTransactions();


    } catch (error) {

        alert(
            "Unable to create trade request.\n\n" +
            error.message
        );

    }

}


/* ==========================================================================
   TRANSACTIONS (transactions.html)
   Shows the purchases/trades you requested AND requests made for your books.
   ========================================================================== */
function loadingRow(colspan, text) {
    return `<tr class="loading-row"><td colspan="${colspan}"><span class="inline-spinner" aria-hidden="true"></span> ${escapeHTML(text)}</td></tr>`;
}

function listingTitle(inventoryId) {
    const listing = inventoryMap[inventoryId];
    const book = listing ? bookMap[listing.book_id] : null;
    return book ? book.title : `Listing #${inventoryId}`;
}

function isMyListing(inventoryId) {
    const listing = inventoryMap[inventoryId];
    return !!(listing && currentUser && Number(listing.seller_id) === Number(currentUser.user_id));
}

const TX_STATUS_TEXT = {
    Pending:   "Pending — waiting for staff to confirm",
    Accepted:  "Accepted — being arranged",
    Completed: "Completed",
    Cancelled: "Cancelled",
    Disputed:  "Disputed — staff are reviewing"
};

async function loadTransactions() {
    const list = document.getElementById("transaction-list");
    if (!list) return;
    list.innerHTML = loadingRow(7, "Loading your transactions…");
    try {
        const all = await apiRequest("transactions.php");
        const me = Number(currentUser.user_id);
        transactions = (Array.isArray(all) ? all : []).filter(t =>
            Number(t.buyer_id) === me || isMyListing(t.requested_inventory_id) || isMyListing(t.offered_inventory_id)
        ).sort((x, y) => Number(y.transaction_id) - Number(x.transaction_id));
        renderTransactions(transactions);
    } catch (error) {
        list.innerHTML = `<tr><td colspan="7">Couldn't load your transactions: ${escapeHTML(error.message)}
            <button type="button" class="inline-retry" id="tx-retry">Try again</button></td></tr>`;
        document.getElementById("tx-retry")?.addEventListener("click", loadTransactions);
    }
}

function renderTransactions(transactionData) {
    const list = document.getElementById("transaction-list");
    if (!list) return;
    if (!transactionData.length) {
        list.innerHTML = `<tr><td colspan="8">No transactions yet. <a href="browse.html">Browse books</a> to buy or trade.</td></tr>`;
        return;
    }
    const me = Number(currentUser.user_id);
    list.innerHTML = "";
    transactionData.forEach(t => {
        const iAmBuyer = Number(t.buyer_id) === me;
        let book = escapeHTML(listingTitle(t.requested_inventory_id));
        if (t.transaction_type === "Trade" && t.offered_inventory_id) {
            book += `<div class="tx-sub">in exchange for ${escapeHTML(listingTitle(t.offered_inventory_id))}</div>`;
        }
        const payStatus = t.payment_status || (t.transaction_type === "Trade" ? "Not_required" : "Not_required");
        const payBadge = {
            "Paid":         '<span class="tx-status" style="background:#d4edda;color:#155724;">Paid</span>',
            "Unpaid":       '<span class="tx-status" style="background:#fff3cd;color:#856404;">Unpaid</span>',
            "Refunded":     '<span class="tx-status" style="background:#f8d7da;color:#721c24;">Refunded</span>',
            "Not_required": '<span style="color:#aaa;font-size:12px;">—</span>',
        }[payStatus] || `<span style="color:#aaa;font-size:12px;">${escapeHTML(payStatus)}</span>`;

        const row = document.createElement("tr");
        row.innerHTML = `
            <td>#${escapeHTML(t.transaction_id)}</td>
            <td>${book}</td>
            <td>${escapeHTML(t.transaction_type)}</td>
            <td>${t.transaction_type === "Trade" ? "—" : escapeHTML(formatPrice(t.amount_paid))}</td>
            <td><span class="tx-status tx-${escapeHTML(String(t.status).toLowerCase())}">${escapeHTML(TX_STATUS_TEXT[t.status] || t.status)}</span></td>
            <td>${payBadge}</td>
            <td>${iAmBuyer ? "You requested it" : "Request for your book"}</td>
            <td class="transaction-action"></td>`;
        const action = row.querySelector(".transaction-action");
        // "Pay Now" button for unpaid purchases
        if (iAmBuyer && t.transaction_type === "Purchase" && (t.payment_status === "Unpaid" || (!t.payment_status && t.status === "Pending"))) {
            const payBtn = document.createElement("a");
            payBtn.href = `checkout.html?tx=${t.transaction_id}`;
            payBtn.className = "btn-small";
            payBtn.style.cssText = "background:#8b5e3c;color:#fff;text-decoration:none;padding:6px 12px;border-radius:6px;font-size:12px;font-weight:700;";
            payBtn.textContent = "💳 Pay Now";
            action.appendChild(payBtn);
        } else if (iAmBuyer && t.status === "Pending") {
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = "btn-small";
            btn.textContent = "Cancel";
            btn.addEventListener("click", () => cancelTransaction(t.transaction_id, btn));
            action.appendChild(btn);
        } else if (iAmBuyer && t.status === "Completed" && t.transaction_type === "Purchase") {
            // Offer feedback on completed purchases
            const fbBtn = document.createElement("button");
            fbBtn.type = "button";
            fbBtn.className = "btn-small feedback-btn";
            fbBtn.dataset.txId = t.transaction_id;
            fbBtn.textContent = "⭐ Feedback";
            fbBtn.title = "Leave feedback for this transaction";
            fbBtn.addEventListener("click", () => {
                if (window.openFeedbackModal) window.openFeedbackModal({ transactionId: Number(t.transaction_id) });
            });
            action.appendChild(fbBtn);
        } else {
            action.textContent = "—";
        }
        list.appendChild(row);
    });
    document.dispatchEvent(new CustomEvent('librowse:transactions-loaded'));
}

async function cancelTransaction(transactionId, button) {
    if (!confirm("Cancel this request? The book will go back on the shelf.")) return;
    if (button) { button.disabled = true; button.textContent = "Cancelling…"; }
    try {
        await apiRequest(`transactions.php?id=${transactionId}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ status: "Cancelled" })
        });
        await loadBooks();
        await loadTransactions();
    } catch (error) {
        alert("Unable to cancel this request.\n\n" + error.message);
        if (button) { button.disabled = false; button.textContent = "Cancel"; }
    }
}


/* ==========================================================================
   REFUND FORM: pick from your completed purchases (support.html)
   ========================================================================== */
async function loadRefundOptions() {
    const select = document.getElementById("refund-transaction-id");
    if (!select || select.tagName !== "SELECT") return;
    const help = document.getElementById("refund-tx-help");
    select.disabled = true;
    select.innerHTML = `<option value="">Loading your completed purchases…</option>`;
    try {
        const [txs, refunds] = await Promise.all([
            apiRequest("transactions.php"),
            apiRequest("refund_request.php")
        ]);
        const me = Number(currentUser.user_id);
        const blocked = new Set((refunds || [])
            .filter(r => ["Pending", "Approved"].includes(r.status))
            .map(r => Number(r.transaction_id)));
        const eligible = (txs || []).filter(t =>
            Number(t.buyer_id) === me && t.transaction_type === "Purchase" &&
            t.status === "Completed" && !blocked.has(Number(t.transaction_id)));
        if (!eligible.length) {
            select.innerHTML = `<option value="">No completed purchases to refund</option>`;
            if (help) help.textContent = "Only completed purchases without an open refund appear here.";
            return;
        }
        select.innerHTML = `<option value="">Choose a purchase</option>` + eligible.map(t =>
            `<option value="${escapeHTML(t.transaction_id)}">#${escapeHTML(t.transaction_id)} — ${escapeHTML(listingTitle(t.requested_inventory_id))} (${escapeHTML(formatPrice(t.amount_paid))})</option>`
        ).join("");
        select.disabled = false;
    } catch (error) {
        select.innerHTML = `<option value="">Couldn't load purchases — refresh to try again</option>`;
    }
}

/* REFUND REQUEST */

async function submitRefund(event) {

    event.preventDefault();


    const transactionId =
        document
            .getElementById(
                "refund-transaction-id"
            )
            .value;


    const customerId =
        currentUser
            ? currentUser.user_id
            : document
                .getElementById(
                    "customer-id"
                )
                .value;


    const reason =
        document
            .getElementById(
                "refund-reason"
            )
            .value
            .trim();


    const refundData = {

        transaction_id:
            Number(transactionId),

        customer_id:
            Number(customerId),

        reason:
            reason,

        status:
            "Pending"

    };


    try {

        await apiRequest(
            "refund_request.php",
            {

                method: "POST",

                headers: {
                    "Content-Type":
                        "application/json"
                },

                body:
                    JSON.stringify(
                        refundData
                    )

            }
        );


        alert(
            "Refund request submitted!"
        );


        document
            .getElementById(
                "refund-form"
            )
            .reset();
        await loadRefundOptions();


    } catch (error) {

        alert(
            "Unable to submit refund.\n\n" +
            error.message
        );

    }

}


/* SUBMIT REPORT */

async function submitReport(event) {

    event.preventDefault();


    const submittedBy =
        currentUser
            ? currentUser.user_id
            : document
                .getElementById(
                    "submitted-by"
                )
                .value;


    const category =
        document
            .getElementById(
                "report-category"
            )
            .value;


    const relatedEntity =
        document
            .getElementById(
                "related-entity"
            )
            .value;


    const details =
        document
            .getElementById(
                "report-details"
            )
            .value
            .trim();


    /* form_data can contain JSON */

    const reportData = {

        submitted_by_id:
            Number(submittedBy),

        report_category:
            category,

        related_entity_type:
            relatedEntity,

        form_data: {
            details:
                details
        },

        status:
            "Pending"

    };


    try {

        await apiRequest(
            "reports.php",
            {

                method: "POST",

                headers: {
                    "Content-Type":
                        "application/json"
                },

                body:
                    JSON.stringify(
                        reportData
                    )

            }
        );


        alert(
            "Report submitted successfully!"
        );


        document
            .getElementById(
                "report-form"
            )
            .reset();


    } catch (error) {

        alert(
            "Unable to submit report.\n\n" +
            error.message
        );

    }

}


/* EVENT LISTENERS */
document.addEventListener("DOMContentLoaded", async function () {
    if (window.librowseAuthReady) {
        if (!await window.librowseAuthReady) return;
    }

    loadCurrentUser();
    if (!requireAuthenticatedCustomer()) return;

    updateAuthStatusUI();
    applyCurrentUserToForms();

    const searchForm = document.getElementById("search-form");
    searchForm?.addEventListener("submit", event => { event.preventDefault(); filterBooks(); });
    document.getElementById("filter-type")?.addEventListener("change", filterBooks);
    document.getElementById("filter-condition")?.addEventListener("change", filterBooks);
    document.getElementById("search-book")?.addEventListener("input", filterBooks);
    document.getElementById("list-book-form")?.addEventListener("submit", submitBookListing);
    document.getElementById("refund-form")?.addEventListener("submit", submitRefund);
    document.getElementById("report-form")?.addEventListener("submit", submitReport);

    const hasBrowse = !!document.getElementById("book-list");
    const hasTransactions = !!document.getElementById("transaction-list");
    const hasCategories = !!document.getElementById("book-category-options") || !!document.getElementById("filter-category-options");

    const hasRefundPicker = !!document.getElementById("refund-transaction-id");
    if (hasBrowse || hasTransactions || hasRefundPicker) await loadBooks();
    if (hasRefundPicker) await loadRefundOptions();
    if (hasTransactions) await loadTransactions();
    if (hasCategories) await loadCategories();
});


/* ==========================================================================
   DOUBLE-SUBMISSION GUARD
   A form that is already sending is locked: extra clicks, Enter presses or
   Ctrl+S are ignored until the server answers, and the button shows
   "Processing…". This stops the same listing/refund/report being saved twice.
   ========================================================================== */
function guardFormSubmit(handler) {
    return async function (event) {
        if (event && typeof event.preventDefault === "function") event.preventDefault();
        const form = event && event.currentTarget instanceof HTMLFormElement
            ? event.currentTarget
            : (event && event.target && event.target.closest ? event.target.closest("form") : null);

        if (form && form.dataset.submitting === "true") return;   // already sending — ignore
        const button = form ? form.querySelector('button[type="submit"]') : null;
        const label = button ? button.textContent : "";

        if (form) {
            form.dataset.submitting = "true";
            form.setAttribute("aria-busy", "true");
        }
        if (button) {
            button.disabled = true;
            button.textContent = "Processing…";
        }
        try {
            return await handler.call(this, event);
        } finally {
            if (form) {
                delete form.dataset.submitting;
                form.removeAttribute("aria-busy");
            }
            if (button) {
                button.disabled = false;
                button.textContent = label;
            }
        }
    };
}

submitBookListing = guardFormSubmit(submitBookListing);
submitRefund = guardFormSubmit(submitRefund);
submitReport = guardFormSubmit(submitReport);
