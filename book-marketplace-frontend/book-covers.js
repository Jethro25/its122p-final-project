/* =========================================================================
   LIBROWSE — book covers
   1) librowseCoverFor(listing, book): the listing's uploaded photo, or a
      generated cloth-bound "library book" cover (no external images).
   2) librowseCompressImage(file): shrinks a photo in the browser to a small
      JPEG data URL that can be stored with the listing.
   ========================================================================= */
(function () {
    const CLOTH = {
        "fiction":                    ["#7b4a3a", "#e9c98f"],
        "non-fiction":                ["#3f5a4c", "#e7d6ad"],
        "science fiction & fantasy":  ["#33475e", "#d9c27e"],
        "mystery & thriller":         ["#3b3434", "#d8b16a"],
        "romance":                    ["#9a5866", "#f3dcc3"],
        "biography & memoir":         ["#6b5a3e", "#efdcb2"],
        "children's books":           ["#5f8a6e", "#fbe7b5"],
        "academic & textbooks":       ["#2f4f5f", "#e3cf9a"],
        "self-help":                  ["#a0703f", "#f6e6c4"],
        "comics & graphic novels":    ["#8b3b3b", "#f3d9a2"]
    };
    const FALLBACK = [["#6d4c3d", "#ead3a6"], ["#4b5e4a", "#e8d8b0"], ["#3e4f63", "#e2cd94"],
                      ["#7a3f46", "#f0d6b9"], ["#5b4a6b", "#e9d3b0"], ["#2f5a5a", "#e6d29f"]];

    function hash(str) {
        let h = 0;
        for (let i = 0; i < str.length; i++) h = (h * 31 + str.charCodeAt(i)) >>> 0;
        return h;
    }
    function xml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" }[c]));
    }
    function wrap(text, max, maxLines) {
        const words = String(text).split(/\s+/).filter(Boolean);
        const lines = [];
        let line = "";
        words.forEach(w => {
            if ((line + " " + w).trim().length > max && line) { lines.push(line); line = w; }
            else line = (line + " " + w).trim();
        });
        if (line) lines.push(line);
        if (lines.length > maxLines) {
            lines.length = maxLines;
            lines[maxLines - 1] = lines[maxLines - 1].replace(/.{0,2}$/, "") + "…";
        }
        return lines;
    }

    /** Generated cloth-bound cover as an SVG data URL */
    function placeholderCover(title, author, category) {
        title = title || "Untitled";
        author = author || "Unknown author";
        const key = String(category || "").toLowerCase().split(",")[0].trim();
        const [cloth, foil] = CLOTH[key] || FALLBACK[hash(title) % FALLBACK.length];
        const variant = hash(title + author) % 3;

        const lines = wrap(title, 13, 4);
        const size = lines.length > 3 ? 24 : lines.length > 2 ? 27 : 31;
        const startY = 170 - ((lines.length - 1) * size * 1.15) / 2;
        const titleSvg = lines.map((l, i) =>
            `<text x="160" y="${(startY + i * size * 1.15).toFixed(1)}" font-size="${size}">${xml(l)}</text>`).join("");

        const ornament = [
            `<path d="M160 70 l10 10 -10 10 -10 -10z" fill="none" stroke="${foil}" stroke-width="2"/><circle cx="160" cy="80" r="3" fill="${foil}"/>`,
            `<path d="M128 80 h64 M140 74 h40 M140 86 h40" stroke="${foil}" stroke-width="2"/>`,
            `<circle cx="160" cy="80" r="11" fill="none" stroke="${foil}" stroke-width="2"/><circle cx="160" cy="80" r="4" fill="${foil}"/>`
        ][variant];

        const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 440">
<defs>
  <pattern id="linen" width="6" height="6" patternUnits="userSpaceOnUse">
    <path d="M0 3h6M3 0v6" stroke="#000" stroke-opacity=".07" stroke-width="1"/>
  </pattern>
  <linearGradient id="shade" x1="0" x2="1">
    <stop offset="0" stop-color="#000" stop-opacity=".28"/>
    <stop offset=".08" stop-color="#fff" stop-opacity=".10"/>
    <stop offset=".14" stop-color="#000" stop-opacity="0"/>
    <stop offset="1" stop-color="#000" stop-opacity=".12"/>
  </linearGradient>
</defs>
<rect width="320" height="440" fill="${cloth}"/>
<rect width="320" height="440" fill="url(#linen)"/>
<rect x="34" y="26" width="262" height="388" fill="none" stroke="${foil}" stroke-width="2.5"/>
<rect x="42" y="34" width="246" height="372" fill="none" stroke="${foil}" stroke-opacity=".55" stroke-width="1"/>
${ornament}
<g fill="${foil}" font-family="Georgia, 'Times New Roman', serif" font-weight="700" text-anchor="middle">${titleSvg}</g>
<path d="M120 ${startY + lines.length * size * 1.15 + 6} h80" stroke="${foil}" stroke-width="1.5"/>
<text x="160" y="${(startY + lines.length * size * 1.15 + 34).toFixed(1)}" fill="${foil}" font-family="Georgia, serif" font-size="14" letter-spacing="2" text-anchor="middle">${xml(String(author).toUpperCase().slice(0, 26))}</text>
<text x="160" y="388" fill="${foil}" fill-opacity=".75" font-family="Georgia, serif" font-size="10" letter-spacing="4" text-anchor="middle">LIBROWSE</text>
<rect width="320" height="440" fill="url(#shade)"/>
</svg>`;
        return "data:image/svg+xml;charset=utf-8," + encodeURIComponent(svg);
    }

    window.librowsePlaceholderCover = placeholderCover;

    window.librowseCoverFor = function (listing, book, categoryText) {
        if (listing && listing.cover_image) return listing.cover_image;
        return placeholderCover(book && book.title, book && book.author, categoryText);
    };

    /** Shrink a photo to <= 480x640 JPEG; resolves to a data URL */
    window.librowseCompressImage = function (file) {
        return new Promise((resolve, reject) => {
            if (!file || !/^image\/(jpeg|png|webp|gif|heic|heif)$/i.test(file.type || "")) {
                reject(new Error("Please choose a JPG, PNG or WebP photo."));
                return;
            }
            if (file.size > 12 * 1024 * 1024) {
                reject(new Error("That photo is larger than 12 MB. Please choose a smaller one."));
                return;
            }
            const url = URL.createObjectURL(file);
            const img = new Image();
            img.onload = () => {
                const maxW = 480, maxH = 640;
                const scale = Math.min(1, maxW / img.width, maxH / img.height);
                const canvas = document.createElement("canvas");
                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);
                const ctx = canvas.getContext("2d");
                ctx.fillStyle = "#fff";
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                let quality = 0.8, data = canvas.toDataURL("image/jpeg", quality);
                while (data.length > 450000 && quality > 0.4) {
                    quality -= 0.1;
                    data = canvas.toDataURL("image/jpeg", quality);
                }
                resolve(data);
            };
            img.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new Error("That file could not be read as an image."));
            };
            img.src = url;
        });
    };
})();
