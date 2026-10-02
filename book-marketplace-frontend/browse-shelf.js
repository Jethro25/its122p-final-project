/* =========================================================================
   LIBROWSE — interactive bookshelf (browse.html)
   Loaded after script.js. It wraps script.js's renderBooks() so every search
   or filter redraws both the shelf view and the original catalog table.
   Buying and trading still go through script.js (buyBook / tradeBook).
   ========================================================================= */
(function () {
    if (!document.getElementById("bookshelf")) return;

    const shelf = document.getElementById("bookshelf");
    const listWrap = document.getElementById("catalog-list");
    const countEl = document.getElementById("shelf-count");
    const sortEl = document.getElementById("shelf-sort");
    const mineEl = document.getElementById("filter-mine");
    const dialog = document.getElementById("book-dialog");
    let lastRendered = [];
    let typeFilter = "all";          // all | sale | trade
    let selectMode = false;          // "Manage my listings" mode
    let mineGroup = "active";        // active | removed | history (inside "Only my listings")
    const mineTabs = document.getElementById("mine-tabs");
    function groupOf(l) {
        const st = l.status || "Available";
        if (st === "Removed") return "removed";
        if (st === "Sold" || st === "Traded") return "history";
        return "active";             // Available + On hold (pending request)
    }
    const selected = new Set();      // inventory_ids picked in that mode
    const typeSelect = document.getElementById("filter-type");

    /* Sold, traded and on-hold books are taken off the shelves automatically.
       Sellers still see their own via "Only my listings". */
    function isAvailable(l) { return (l.status || "Available") === "Available"; }
    function forSale(l) { return l.listing_type === "For_sale" || l.listing_type === "Both"; }
    function forTrade(l) { return l.listing_type === "For_trade" || l.listing_type === "Both"; }

    function esc(s) {
        return String(s ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
    }
    function me() { return typeof currentUser !== "undefined" ? currentUser : null; }
    function isMine(listing) { return me() && Number(listing.seller_id) === Number(me().user_id); }
    function bookOf(listing) { return (typeof bookMap !== "undefined" && bookMap[listing.book_id]) || null; }
    function categoryText(book) {
        try { return book ? formatCategoryNames(book) : "Uncategorized"; } catch (_) { return "Uncategorized"; }
    }
    function sellerName(listing) {
        const u = typeof userMap !== "undefined" ? userMap[listing.seller_id] : null;
        return u ? u.username : `User #${listing.seller_id}`;
    }
    function callNumber(listing, book) {
        const cat = categoryText(book).replace(/[^A-Za-z]/g, "").slice(0, 3).toUpperCase() || "GEN";
        const author = (book && book.author ? book.author.split(" ").pop() : "X").slice(0, 3).toUpperCase();
        return `${cat} ${String(listing.inventory_id).padStart(4, "0")} ${author}`;
    }
    function statusInfo(listing) {
        const s = listing.status || "Available";
        if (s === "Available") return { label: "Available", out: false };
        if (s === "In_transaction") return { label: "On hold", out: true };
        if (s === "Sold") return { label: "Sold", out: true };
        if (s === "Traded") return { label: "Traded", out: true };
        return { label: s.replace("_", " "), out: true };
    }
    function shortPrice(listing) {
        if (listing.price === null || listing.price === "" || listing.price === undefined) return "Trade only";
        return "₱" + Number(listing.price).toLocaleString("en-PH", { maximumFractionDigits: 0 });
    }
    function typeRibbon(type) {
        return type === "For_sale" ? "For sale" : type === "For_trade" ? "For trade" : "Sale or trade";
    }

    /* ---------- sort + "only mine" (applied to both views) ---------- */
    function arrange(listings) {
        let list = listings.slice();
        if (mineEl && mineEl.checked) list = list.filter(l => isMine(l) && groupOf(l) === mineGroup);
        else list = list.filter(isAvailable);
        if (typeFilter === "sale") list = list.filter(forSale);
        if (typeFilter === "trade") list = list.filter(forTrade);
        const by = sortEl ? sortEl.value : "newest";
        const title = l => (bookOf(l)?.title || "").toLowerCase();
        const price = l => (l.price === null || l.price === "" || l.price === undefined) ? Infinity : Number(l.price);
        if (by === "title") list.sort((a, b) => title(a).localeCompare(title(b)));
        if (by === "price-asc") list.sort((a, b) => price(a) - price(b));
        if (by === "price-desc") list.sort((a, b) => (price(b) === Infinity ? -1 : price(b)) - (price(a) === Infinity ? -1 : price(a)));
        if (by === "newest") list.sort((a, b) => String(b.listed_at || b.inventory_id).localeCompare(String(a.listed_at || a.inventory_id)) || b.inventory_id - a.inventory_id);
        return list;
    }

    /* ---------- shelf rendering ---------- */
    function renderShelf(listings) {
        lastRendered = listings;
        const all = typeof bookListings !== "undefined" ? bookListings : listings;
        const onShelf = all.filter(isAvailable);
        const setCount = (id, n) => { const el = document.getElementById(id); if (el) el.textContent = n; };
        setCount("count-all", onShelf.length);
        setCount("count-sale", onShelf.filter(forSale).length);
        setCount("count-trade", onShelf.filter(forTrade).length);
        const mineAll = all.filter(isMine);
        ["active", "removed", "history"].forEach(g =>
            setCount(`mine-count-${g}`, mineAll.filter(l => groupOf(l) === g).length));
        if (countEl) {
            const what = typeFilter === "sale" ? "for sale" : typeFilter === "trade" ? "for trade" : "available";
            const n = listings.length, s = n === 1 ? "" : "s";
            const mineText = {
                active:  `${n} of your book${s} on the shelves`,
                removed: `${n} book${s} you took off the shelves`,
                history: `${n} book${s} you sold or traded`
            };
            countEl.textContent = mineEl && mineEl.checked
                ? mineText[mineGroup]
                : `${n} book${s} ${what} on the shelves`;
        }

        if (!listings.length && mineEl && mineEl.checked) {
            const empty = {
                active:  ["Nothing on the shelves yet", "You don't have any books listed right now.", `<a class="photo-btn" href="list-book.html">List a book</a>`],
                removed: ["No removed books", "Books you take off the shelves will wait here, so you can put them back anytime.", ""],
                history: ["No sales or trades yet", "Books you sell or trade will be kept here as your history.", ""]
            }[mineGroup];
            shelf.innerHTML = `
                <div class="shelf-empty">
                    <img src="assets/books-stack.svg" alt="" width="160" height="135">
                    <h3>${empty[0]}</h3>
                    <p>${empty[1]}</p>
                    ${empty[2]}
                </div>`;
            return;
        }
        if (!listings.length) {
            shelf.innerHTML = `
                <div class="shelf-empty">
                    <img src="assets/books-stack.svg" alt="" width="160" height="135">
                    <h3>No books on this shelf</h3>
                    <p>Try a different search, or clear the filters to see every book.</p>
                    <button type="button" id="shelf-clear-filters">Clear filters</button>
                </div>`;
            document.getElementById("shelf-clear-filters")?.addEventListener("click", clearFilters);
            return;
        }

        shelf.innerHTML = listings.map((listing, i) => {
            const book = bookOf(listing);
            const st = statusInfo(listing);
            const tilt = ((listing.inventory_id * 37) % 5 - 2) * 0.6;
            const pickable = selectMode && isPickable(listing);
            const picked = pickable && selected.has(listing.inventory_id);
            return `
                <button type="button" class="shelf-book${st.out ? " is-out" : ""}${isMine(listing) ? " is-mine" : ""}${selectMode ? (pickable ? " is-pickable" : " is-locked") : ""}${picked ? " is-picked" : ""}"
                        data-id="${listing.inventory_id}" style="--tilt:${tilt}deg; --i:${i}"
                        ${selectMode ? `aria-pressed="${picked}" ${pickable ? "" : 'aria-disabled="true"'}` : ""}
                        aria-label="${selectMode ? (pickable ? "Select " : "Can't select ") : ""}${esc(book?.title || "Unknown book")} by ${esc(book?.author || "unknown author")}, ${esc(shortPrice(listing))}, ${esc(st.label)}">
                    <span class="book-cover">
                        ${selectMode && pickable ? `<span class="pick-check" aria-hidden="true"></span>` : ""}
                        <img src="${esc(librowseCoverFor(listing, book, categoryText(book)))}" alt="" loading="lazy">
                        <span class="book-ribbon ribbon-${listing.listing_type}">${typeRibbon(listing.listing_type)}</span>
                        ${st.out ? `<span class="book-stamp">${esc(st.label)}</span>` : ""}
                        ${isMine(listing) ? `<span class="book-mine">Yours</span>` : ""}
                    </span>
                    <span class="book-label">
                        <span class="book-title">${esc(book?.title || "Unknown book")}</span>
                        <span class="book-author">${esc(book?.author || "Unknown author")}</span>
                        <span class="book-price">${esc(shortPrice(listing))}</span>
                    </span>
                </button>`;
        }).join("");
    }

    function clearFilters() {
        ["search-book"].forEach(id => { const el = document.getElementById(id); if (el) el.value = ""; });
        ["filter-type", "filter-condition"].forEach(id => { const el = document.getElementById(id); if (el) el.value = ""; });
        document.querySelectorAll("#filter-category-options input[type=checkbox]").forEach(cb => { cb.checked = false; });
        if (mineEl) mineEl.checked = false;
        if (selectMode) setSelectMode(false, false);
        syncMineTabs();
        setTypeFilter("all", false);
        filterBooks();
    }

    /* Wrap script.js renderBooks so both views stay in sync */
    const originalRender = window.renderBooks || renderBooks;
    window.renderBooks = function (listings) {
        const arranged = arrange(listings || []);
        originalRender(arranged);
        renderShelf(arranged);
        if (selectMode) updateBar();
    };
    // Function declarations in script.js resolve through the global object,
    // so reassigning the global makes script.js call the wrapped version.
    try { renderBooks = window.renderBooks; } catch (_) {}

    function setTypeFilter(value, rerender = true) {
        typeFilter = value;
        document.querySelectorAll(".type-chip").forEach(chip => {
            const on = chip.dataset.type === value;
            chip.classList.toggle("active", on);
            chip.setAttribute("aria-pressed", String(on));
        });
        // The chips replace the "Listing type" dropdown, so keep that at "All"
        if (typeSelect && value !== "all") typeSelect.value = "";
        if (rerender) filterBooks();
    }
    document.querySelectorAll(".type-chip").forEach(chip =>
        chip.addEventListener("click", () => setTypeFilter(chip.dataset.type)));
    typeSelect?.addEventListener("change", () => { if (typeSelect.value) setTypeFilter("all", false); });

    sortEl?.addEventListener("change", () => filterBooks());
    function syncMineTabs() {
        if (mineTabs) mineTabs.hidden = !(mineEl && mineEl.checked);
    }
    function setMineGroup(group, rerender = true) {
        mineGroup = group;
        document.querySelectorAll(".mine-tab").forEach(t => {
            const on = t.dataset.group === group;
            t.classList.toggle("active", on);
            t.setAttribute("aria-selected", String(on));
        });
        selected.clear();
        if (typeof hideConfirm === "function") hideConfirm();
        if (rerender) filterBooks();
    }
    document.querySelectorAll(".mine-tab").forEach(t =>
        t.addEventListener("click", () => setMineGroup(t.dataset.group)));

    mineEl?.addEventListener("change", () => {
        if (!mineEl.checked && selectMode) setSelectMode(false, false);
        if (mineEl.checked) setMineGroup("active", false);
        syncMineTabs();
        filterBooks();
    });

    /* ---------- view toggle ---------- */
    document.querySelectorAll(".view-toggle button").forEach(btn => {
        btn.addEventListener("click", () => {
            const view = btn.dataset.view;
            document.querySelectorAll(".view-toggle button").forEach(b => {
                b.classList.toggle("active", b === btn);
                b.setAttribute("aria-pressed", String(b === btn));
            });
            shelf.hidden = view !== "shelf";
            listWrap.hidden = view !== "list";
        });
    });

    /* ---------- detail "catalog card" ---------- */
    shelf.addEventListener("click", e => {
        const card = e.target.closest(".shelf-book");
        if (!card) return;
        if (selectMode) { togglePick(Number(card.dataset.id), card); return; }
        const listing = (typeof inventoryMap !== "undefined" && inventoryMap[card.dataset.id])
            || lastRendered.find(l => String(l.inventory_id) === card.dataset.id);
        if (listing) openDetail(listing);
    });

    function openDetail(listing) {
        const book = bookOf(listing);
        const st = statusInfo(listing);
        const mine = isMine(listing);
        const canBuy = !st.out && !mine && (listing.listing_type === "For_sale" || listing.listing_type === "Both");
        const canTrade = !st.out && !mine && (listing.listing_type === "For_trade" || listing.listing_type === "Both");
        const listed = listing.listed_at ? new Date(String(listing.listed_at).replace(" ", "T")).toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" }) : "—";

        dialog.innerHTML = `
            <div class="book-dialog-inner">
                <button type="button" class="dialog-close" aria-label="Close">&times;</button>
                <div class="dialog-cover">
                    <img id="dialog-cover-img" src="${esc(librowseCoverFor(listing, book, categoryText(book)))}" alt="Cover of ${esc(book?.title || "this book")}">
                    ${mine ? `
                    <div class="owner-photo">
                        <p>${listing.cover_image ? "Your photo is showing." : "Showing a library-style placeholder."} Add a real photo of your copy so others can see its condition.</p>
                        <label class="photo-btn" for="dialog-photo-input">${listing.cover_image ? "Change photo" : "Add a photo"}</label>
                        <input type="file" id="dialog-photo-input" accept="image/*" class="visually-hidden">
                        ${listing.cover_image ? `<button type="button" class="btn-clear small-btn" id="dialog-photo-remove">Remove photo</button>` : ""}
                        <span class="owner-photo-status" id="dialog-photo-status" role="status"></span>
                    </div>` : ""}
                </div>
                <div class="catalog-card">
                    <div class="catalog-card-head">
                        <span class="call-no">${esc(callNumber(listing, book))}</span>
                        <span class="status-stamp ${st.out ? "out" : "in"}">${esc(st.label)}</span>
                    </div>
                    <h3 id="book-dialog-title">${esc(book?.title || "Unknown book")}</h3>
                    <p class="by">by ${esc(book?.author || "Unknown author")}</p>
                    <dl class="catalog-fields">
                        <div><dt>Category</dt><dd>${esc(categoryText(book))}</dd></div>
                        <div><dt>Condition</dt><dd>${esc(listing.condition || "—")}</dd></div>
                        <div><dt>Listing</dt><dd>${esc(formatListingType(listing.listing_type))}</dd></div>
                        <div><dt>ISBN</dt><dd>${esc(book?.isbn || "—")}</dd></div>
                        <div><dt>Seller</dt><dd>${esc(sellerName(listing))}${mine ? " (you)" : ""}</dd></div>
                        <div><dt>Shelved</dt><dd>${esc(listed)}</dd></div>
                    </dl>
                    <div class="catalog-price">${esc(formatPrice(listing.price))}</div>
                    <div class="dialog-actions">
                        ${canBuy ? `<button type="button" id="dialog-buy">Buy this book</button>` : ""}
                        ${canTrade ? `<button type="button" id="dialog-trade" class="btn-clear">Offer a trade</button>` : ""}
                        ${mine && listing.status === "Removed" ? `<p class="dialog-note">You took this book off the shelves. Nobody else can see it.</p>
                            <button type="button" id="dialog-relist">Put back on the shelf</button>
                            <button type="button" id="dialog-delete" class="btn-danger">Delete permanently</button>` : ""}
                        ${mine && (listing.status || "Available") === "Available" ? `<p class="dialog-note">This is your listing. Others can buy or trade for it from the shelves.</p>
                            <button type="button" id="dialog-remove" class="btn-danger">Remove listing</button>` : ""}
                        ${mine && listing.status === "In_transaction" ? `<p class="dialog-note">Someone has requested this book, so it can't be removed until that request is completed or cancelled.</p>` : ""}
                        ${mine && (listing.status === "Sold" || listing.status === "Traded") ? `<p class="dialog-note">This book has been ${esc(listing.status.toLowerCase())}. It stays in your history.</p>` : ""}
                        ${st.out && !mine ? `<p class="dialog-note">This book is currently ${esc(st.label.toLowerCase())}, so it can't be requested right now.</p>` : ""}
                    </div>
                    <div class="remove-confirm" id="remove-confirm" hidden role="alertdialog" aria-labelledby="remove-confirm-text">
                        <p id="remove-confirm-text"><strong>Take &ldquo;${esc(book?.title || "this book")}&rdquo; off the shelves?</strong>
                        Other readers won&rsquo;t be able to see, buy or trade for it. You can put it back later from <em>Only my listings</em>.</p>
                        <div class="remove-confirm-actions">
                            <button type="button" id="remove-yes" class="btn-danger-solid">Yes, remove it</button>
                            <button type="button" id="remove-no" class="btn-clear">Keep it listed</button>
                        </div>
                        <span class="remove-status" id="remove-status" role="status"></span>
                    </div>
                    <div class="remove-confirm delete-confirm" id="delete-confirm" hidden role="alertdialog" aria-labelledby="delete-confirm-text">
                        <p id="delete-confirm-text"><strong>Delete &ldquo;${esc(book?.title || "this book")}&rdquo; permanently?</strong>
                        The listing and its photo will be erased for good. <strong class="inline-strong">This can&rsquo;t be undone.</strong></p>
                        <div class="remove-confirm-actions">
                            <button type="button" id="delete-yes" class="btn-danger-solid">Yes, delete forever</button>
                            <button type="button" id="delete-no" class="btn-clear">Keep it</button>
                        </div>
                        <span class="remove-status" id="delete-status" role="status"></span>
                    </div>
                    <div class="trade-picker" id="trade-picker" hidden></div>
                </div>
            </div>`;
        dialog.setAttribute("aria-labelledby", "book-dialog-title");

        dialog.querySelector(".dialog-close").addEventListener("click", () => dialog.close());
        dialog.querySelector("#dialog-buy")?.addEventListener("click", async () => {
            dialog.close();
            await buyBook(listing);
            await refreshShelf();
        });
        dialog.querySelector("#dialog-trade")?.addEventListener("click", () => showTradePicker(listing));
        if (mine) wireOwnerPhoto(listing, book);
        if (mine) wireRemove(listing, book);

        if (typeof dialog.showModal === "function") dialog.showModal(); else dialog.setAttribute("open", "");
    }

    dialog.addEventListener("click", e => { if (e.target === dialog) dialog.close(); });

    function showTradePicker(listing) {
        const picker = dialog.querySelector("#trade-picker");
        const mineAvailable = (typeof bookListings !== "undefined" ? bookListings : [])
            .filter(l => isMine(l) && (l.status || "Available") === "Available");
        picker.hidden = false;
        if (!mineAvailable.length) {
            picker.innerHTML = `<p class="dialog-note">To trade, you need one of your own books listed and available.
                <a href="list-book.html">List a book first</a>.</p>`;
            return;
        }
        picker.innerHTML = `
            <p class="trade-question">Which of your books will you offer?</p>
            <div class="trade-options" role="radiogroup" aria-label="Your books">
                ${mineAvailable.map((l, i) => {
                    const b = bookOf(l);
                    return `<label class="trade-option">
                        <input type="radio" name="trade-offer" value="${l.inventory_id}" ${i === 0 ? "checked" : ""}>
                        <img src="${esc(librowseCoverFor(l, b, categoryText(b)))}" alt="">
                        <span><strong>${esc(b?.title || "Book #" + l.inventory_id)}</strong><small>${esc(l.condition || "")}</small></span>
                    </label>`;
                }).join("")}
            </div>
            <button type="button" id="trade-send">Send trade request</button>`;
        picker.querySelector("#trade-send").addEventListener("click", async () => {
            const chosen = picker.querySelector('input[name="trade-offer"]:checked');
            if (!chosen) return;
            dialog.close();
            // tradeBook() in script.js asks for the offered Inventory ID with prompt();
            // answer it with the book picked here.
            const originalPrompt = window.prompt;
            window.prompt = () => chosen.value;
            try { await tradeBook(listing); }
            finally { window.prompt = originalPrompt; }
            await refreshShelf();
        });
    }

    function wireOwnerPhoto(listing, book) {
        const input = dialog.querySelector("#dialog-photo-input");
        const status = dialog.querySelector("#dialog-photo-status");
        const img = dialog.querySelector("#dialog-cover-img");

        async function save(value, doneText) {
            await apiRequest(`user_books.php?id=${listing.inventory_id}`, {
                method: "PUT",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ cover_image: value })
            });
            listing.cover_image = value;
            img.src = librowseCoverFor(listing, book, categoryText(book));
            status.textContent = doneText;
            filterBooks();
        }

        input?.addEventListener("change", async () => {
            const file = input.files && input.files[0];
            if (!file) return;
            status.textContent = "Preparing photo…";
            try {
                const data = await librowseCompressImage(file);
                img.src = data;
                status.textContent = "Saving…";
                await save(data, "Photo saved. It now shows on the shelves.");
            } catch (err) {
                status.textContent = err.message || "Could not save the photo.";
            }
        });
        dialog.querySelector("#dialog-photo-remove")?.addEventListener("click", async () => {
            status.textContent = "Removing…";
            try { await save(null, "Photo removed. Showing the placeholder cover."); }
            catch (err) { status.textContent = err.message || "Could not remove the photo."; }
        });
    }

    /* ---------- remove / put back a listing ---------- */
    async function setListingStatus(listing, status) {
        await apiRequest(`user_books.php?id=${listing.inventory_id}`, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ status })
        });
        listing.status = status;
        filterBooks();
    }

    function wireRemove(listing, book) {
        const title = book?.title || "Your book";
        const confirmBox = dialog.querySelector("#remove-confirm");
        const statusEl = dialog.querySelector("#remove-status");
        const removeBtn = dialog.querySelector("#dialog-remove");

        removeBtn?.addEventListener("click", () => {
            confirmBox.hidden = false;
            removeBtn.hidden = true;
            dialog.querySelector("#remove-no").focus();
        });
        dialog.querySelector("#remove-no")?.addEventListener("click", () => {
            confirmBox.hidden = true;
            if (removeBtn) { removeBtn.hidden = false; removeBtn.focus(); }
        });
        dialog.querySelector("#remove-yes")?.addEventListener("click", async (e) => {
            const yes = e.currentTarget;
            if (yes.disabled) return;                       // no double clicks
            yes.disabled = true; yes.textContent = "Removing…";
            try {
                await setListingStatus(listing, "Removed");
                dialog.close();
                toast(`“${title}” was taken off the shelves.`, "Undo", async () => {
                    await setListingStatus(listing, "Available");
                    toast(`“${title}” is back on the shelves.`);
                });
            } catch (err) {
                statusEl.textContent = err.message || "Could not remove the listing.";
                yes.disabled = false; yes.textContent = "Yes, remove it";
            }
        });
        const deleteBtn = dialog.querySelector("#dialog-delete");
        const deleteBox = dialog.querySelector("#delete-confirm");
        deleteBtn?.addEventListener("click", () => {
            deleteBox.hidden = false;
            deleteBtn.hidden = true;
            dialog.querySelector("#delete-no").focus();
        });
        dialog.querySelector("#delete-no")?.addEventListener("click", () => {
            deleteBox.hidden = true;
            deleteBtn.hidden = false;
            deleteBtn.focus();
        });
        dialog.querySelector("#delete-yes")?.addEventListener("click", async (e) => {
            const yes = e.currentTarget;
            if (yes.disabled) return;
            yes.disabled = true; yes.textContent = "Deleting…";
            try {
                const { deleted, skipped } = await deleteListings([listing.inventory_id]);
                if (deleted.length) {
                    dialog.close();
                    toast(`“${title}” was deleted permanently.`);
                } else {
                    dialog.querySelector("#delete-status").textContent = skipReason(skipped[0]);
                    yes.disabled = false; yes.textContent = "Yes, delete forever";
                }
            } catch (err) {
                dialog.querySelector("#delete-status").textContent = err.message || "Could not delete the listing.";
                yes.disabled = false; yes.textContent = "Yes, delete forever";
            }
        });
        dialog.querySelector("#dialog-relist")?.addEventListener("click", async (e) => {
            const btn = e.currentTarget;
            if (btn.disabled) return;
            btn.disabled = true; btn.textContent = "Putting it back…";
            try {
                await setListingStatus(listing, "Available");
                dialog.close();
                toast(`“${title}” is back on the shelves.`);
            } catch (err) {
                btn.disabled = false; btn.textContent = "Put back on the shelf";
                alert(err.message || "Could not put the listing back.");
            }
        });
    }

    /* Small message at the bottom of the screen, with an optional action */
    let toastTimer = null;
    function toast(message, actionLabel, onAction) {
        let el = document.getElementById("shelf-toast");
        if (!el) {
            el = document.createElement("div");
            el.id = "shelf-toast";
            el.className = "shelf-toast";
            el.setAttribute("role", "status");
            document.body.appendChild(el);
        }
        el.innerHTML = `<span>${esc(message)}</span>${actionLabel ? `<button type="button">${esc(actionLabel)}</button>` : ""}`;
        el.classList.add("show");
        el.querySelector("button")?.addEventListener("click", async () => {
            el.classList.remove("show");
            try { await onAction(); } catch (err) { toast(err.message || "That didn't work."); }
        }, { once: true });
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => el.classList.remove("show"), 7000);
    }

    /* ---------- "Manage my listings": select several, act once ---------- */
    const bar = document.getElementById("bulk-bar");
    const manageBtn = document.getElementById("manage-btn");
    const els = id => document.getElementById(id);

    function isPickable(l) {
        return isMine(l) && (l.status === "Available" || l.status === "Removed" || !l.status);
    }
    function pickedListings() {
        return [...selected].map(id => inventoryMap[id]).filter(Boolean);
    }
    function updateBar() {
        const picked = pickedListings();
        const toRemove = picked.filter(l => (l.status || "Available") === "Available");
        const toRestore = picked.filter(l => l.status === "Removed");
        els("bulk-count").textContent = picked.length
            ? `${picked.length} selected`
            : "Tap your books to select them";
        els("bulk-remove").disabled = !toRemove.length;
        els("bulk-remove").textContent = toRemove.length ? `Remove ${toRemove.length}` : "Remove";
        els("bulk-restore").disabled = !toRestore.length;
        els("bulk-restore").textContent = toRestore.length ? `Put back ${toRestore.length}` : "Put back";
        els("bulk-remove").hidden = mineGroup !== "active";
        els("bulk-restore").hidden = mineGroup !== "removed";
        els("bulk-delete").hidden = mineGroup !== "removed";
        els("bulk-delete").disabled = !toRestore.length;
        els("bulk-delete").textContent = toRestore.length ? `Delete ${toRestore.length} permanently` : "Delete permanently";
        els("bulk-all").hidden = els("bulk-none").hidden = mineGroup === "history";
        if (mineGroup === "history") els("bulk-count").textContent = "Sold and traded books are kept as history";
    }
    function togglePick(id, card) {
        const listing = inventoryMap[id];
        if (!listing || !isPickable(listing)) return;
        if (selected.has(id)) selected.delete(id); else selected.add(id);
        const on = selected.has(id);
        card.classList.toggle("is-picked", on);
        card.setAttribute("aria-pressed", String(on));
        updateBar();
    }
    function setSelectMode(on, rerender = true) {
        selectMode = on;
        selected.clear();
        bar.hidden = !on;
        manageBtn.classList.toggle("active", on);
        manageBtn.setAttribute("aria-pressed", String(on));
        manageBtn.textContent = on ? "Done managing" : "Manage my listings";
        shelf.classList.toggle("select-mode", on);
        hideConfirm();
        if (on && mineEl && !mineEl.checked) { mineEl.checked = true; setMineGroup("active", false); }   // only your books can be picked
        syncMineTabs();
        if (rerender) filterBooks();
        updateBar();
    }
    function showConfirm(text) {
        els("bulk-row").hidden = true;
        els("bulk-confirm").hidden = false;
        els("bulk-confirm-text").textContent = text;
        els("bulk-confirm-no").focus();
    }
    function hideConfirm() {
        els("bulk-row").hidden = false;
        els("bulk-confirm").hidden = true;
        els("bulk-bar").classList.remove("is-danger");
    }

    async function bulkSetStatus(ids, status) {
        const result = await apiRequest("user_books.php?action=bulk_status", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ ids, status })
        });
        const changed = (result && result.updated_ids) || [];
        changed.forEach(id => { if (inventoryMap[id]) inventoryMap[id].status = status; });
        return { changed, skipped: (result && result.skipped_ids) || [] };
    }

    manageBtn?.addEventListener("click", () => setSelectMode(!selectMode));
    els("bulk-done")?.addEventListener("click", () => setSelectMode(false));
    els("bulk-none")?.addEventListener("click", () => { selected.clear(); filterBooks(); updateBar(); });
    els("bulk-all")?.addEventListener("click", () => {
        lastRendered.filter(isPickable).forEach(l => selected.add(l.inventory_id));
        filterBooks(); updateBar();
    });
    let confirmAction = null;
    els("bulk-remove")?.addEventListener("click", () => {
        const n = pickedListings().filter(l => (l.status || "Available") === "Available").length;
        showConfirm(`Take ${n} book${n === 1 ? "" : "s"} off the shelves? Other readers won't see ${n === 1 ? "it" : "them"} anymore. You can put ${n === 1 ? "it" : "them"} back later.`);
        els("bulk-confirm-yes").textContent = `Yes, remove ${n}`;
        els("bulk-bar").classList.remove("is-danger");
        confirmAction = btn => runBulk("Removed", btn);
    });
    els("bulk-delete")?.addEventListener("click", () => {
        const n = pickedListings().filter(l => l.status === "Removed").length;
        showConfirm(`Permanently delete ${n} book${n === 1 ? "" : "s"}? ${n === 1 ? "It" : "They"} will be erased for good — this can't be undone.`);
        els("bulk-confirm-yes").textContent = `Yes, delete ${n} forever`;
        els("bulk-bar").classList.add("is-danger");
        confirmAction = btn => runBulkDelete(btn);
    });

    async function runBulkDelete(button) {
        const ids = pickedListings().filter(l => l.status === "Removed").map(l => l.inventory_id);
        if (!ids.length || button.disabled) return;
        const label = button.textContent;
        button.disabled = true;
        button.textContent = "Deleting…";
        try {
            const { deleted, skipped } = await deleteListings(ids);
            selected.clear();
            hideConfirm();
            filterBooks();
            updateBar();
            const n = deleted.length;
            const history = skipped.filter(x => x.reason === "has_history").length;
            let msg = n ? `${n} book${n === 1 ? "" : "s"} deleted permanently.` : "Nothing was deleted.";
            if (history) msg += ` ${history} kept in Removed because ${history === 1 ? "it has" : "they have"} purchase or trade history.`;
            toast(msg);
        } catch (err) {
            toast(err.message || "Nothing was deleted. Please try again.");
        } finally {
            button.disabled = false;
            button.textContent = label;
            updateBar();
        }
    }
    els("bulk-confirm-no")?.addEventListener("click", hideConfirm);

    async function runBulk(status, button) {
        const ids = pickedListings()
            .filter(l => status === "Removed" ? (l.status || "Available") === "Available" : l.status === "Removed")
            .map(l => l.inventory_id);
        if (!ids.length || button.disabled) return;
        const label = button.textContent;
        button.disabled = true;
        button.textContent = status === "Removed" ? "Removing…" : "Putting back…";
        try {
            const { changed, skipped } = await bulkSetStatus(ids, status);
            selected.clear();
            hideConfirm();
            filterBooks();
            updateBar();
            const n = changed.length;
            const note = skipped.length ? ` ${skipped.length} couldn't be changed (they may have a pending request).` : "";
            if (status === "Removed") {
                toast(`${n} book${n === 1 ? "" : "s"} moved to Removed.${note}`, n ? "Undo" : null, async () => {
                    await bulkSetStatus(changed, "Available");
                    filterBooks(); updateBar();
                    toast(`${n} book${n === 1 ? "" : "s"} back on the shelves.`);
                });
            } else {
                toast(`${n} book${n === 1 ? "" : "s"} back on the shelves.${note}`, n ? "View" : null, () => setMineGroup("active"));
            }
        } catch (err) {
            toast(err.message || "Nothing was changed. Please try again.");
        } finally {
            button.disabled = false;
            button.textContent = label;
            updateBar();
        }
    }
    els("bulk-confirm-yes")?.addEventListener("click", e => { if (confirmAction) confirmAction(e.currentTarget); });
    els("bulk-restore")?.addEventListener("click", e => runBulk("Available", e.currentTarget));
    document.addEventListener("keydown", e => {
        if (e.key === "Escape" && selectMode && !dialog.open) setSelectMode(false);
    });

    /* ---------- permanent delete ---------- */
    function skipReason(skip) {
        if (!skip) return "This listing couldn't be deleted.";
        return {
            has_history: "This book has purchase or trade history, so it can't be deleted — it will stay in Removed to keep everyone's records.",
            not_removed: "Only books in Removed can be deleted. Remove it from the shelves first.",
            not_yours: "You can only delete your own listings."
        }[skip.reason] || "This listing couldn't be deleted.";
    }
    async function deleteListings(ids) {
        const result = await apiRequest("user_books.php?action=bulk_delete", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ ids })
        });
        const deleted = (result && result.deleted_ids) || [];
        // Drop deleted listings from the page's data, then redraw
        deleted.forEach(id => {
            delete inventoryMap[id];
            selected.delete(id);
            const at = bookListings.findIndex(l => Number(l.inventory_id) === Number(id));
            if (at > -1) bookListings.splice(at, 1);
        });
        filterBooks();
        return { deleted, skipped: (result && result.skipped) || [] };
    }

    async function refreshShelf() {
        try { await loadBooks(); filterBooks(); } catch (_) {}
    }
})();
