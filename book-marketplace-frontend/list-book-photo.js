/* =========================================================================
   LIBROWSE — cover photo picker on list-book.html
   Shows a live shelf preview (placeholder cover built from the title and
   author as you type, or your photo). The shrunken photo is kept in
   window.librowsePendingCover and sent with the listing by script.js.
   ========================================================================= */
(function () {
    const input = document.getElementById("cover-photo");
    if (!input) return;
    const preview = document.getElementById("cover-preview");
    const tag = document.getElementById("cover-preview-tag");
    const status = document.getElementById("cover-status");
    const removeBtn = document.getElementById("cover-remove");
    const dropzone = document.getElementById("photo-dropzone");
    const form = document.getElementById("list-book-form");
    const titleEl = document.getElementById("book-title");
    const authorEl = document.getElementById("book-author");
    const defaultHint = status.textContent;

    window.librowsePendingCover = null;

    function selectedCategory() {
        const checked = document.querySelector("#book-category-options input:checked");
        return checked ? checked.closest("label")?.textContent.trim() : "";
    }
    function drawPlaceholder() {
        if (window.librowsePendingCover) return;
        preview.src = librowsePlaceholderCover(
            titleEl.value.trim() || "Your book title",
            authorEl.value.trim() || "Author name",
            selectedCategory()
        );
    }

    async function useFile(file) {
        if (!file) return;
        status.textContent = "Preparing photo…";
        try {
            const data = await librowseCompressImage(file);
            window.librowsePendingCover = data;
            preview.src = data;
            tag.textContent = "Your photo";
            removeBtn.hidden = false;
            status.textContent = `Photo ready (${Math.round(data.length * 0.75 / 1024)} KB). It will be saved with your listing.`;
        } catch (err) {
            status.textContent = err.message;
        }
    }

    function clearPhoto() {
        window.librowsePendingCover = null;
        input.value = "";
        removeBtn.hidden = true;
        tag.textContent = "Placeholder cover";
        status.textContent = defaultHint;
        drawPlaceholder();
    }

    input.addEventListener("change", () => useFile(input.files && input.files[0]));
    removeBtn.addEventListener("click", clearPhoto);
    titleEl.addEventListener("input", drawPlaceholder);
    authorEl.addEventListener("input", drawPlaceholder);
    document.getElementById("book-category-options")?.addEventListener("change", drawPlaceholder);
    form.addEventListener("reset", () => setTimeout(clearPhoto, 0));
    document.getElementById("list-book-clear")?.addEventListener("click", () => setTimeout(clearPhoto, 0));

    ["dragenter", "dragover"].forEach(t => dropzone.addEventListener(t, e => { e.preventDefault(); dropzone.classList.add("is-dragging"); }));
    ["dragleave", "drop"].forEach(t => dropzone.addEventListener(t, e => { e.preventDefault(); dropzone.classList.remove("is-dragging"); }));
    dropzone.addEventListener("drop", e => useFile(e.dataTransfer.files && e.dataTransfer.files[0]));

    drawPlaceholder();
})();
