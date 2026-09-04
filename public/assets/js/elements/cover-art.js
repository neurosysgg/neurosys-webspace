/**
 * <cover-art fallback="/assets/img/cover-placeholder.svg"><img src="…" alt="…"></cover-art>
 *
 * Falls back to the placeholder when the file host 404s. The <img> is server-rendered rather than
 * built here, so the cover still shows with no JS; this class only wires the error path. It was an
 * inline onerror= attribute once — as a listener it survives a strict script-src.
 */
export class CoverArt extends HTMLElement {
    wired = false;
    connectedCallback() {
        if (this.wired)
            return;
        this.wired = true;
        const img = this.querySelector('img');
        const fallback = this.getAttribute('fallback');
        // An empty fallback would set src="undefined" and 404 a second time.
        if (img === null || fallback === null || fallback === '')
            return;
        // once: true, so a fallback that is itself missing fails quietly instead of looping.
        img.addEventListener('error', () => { img.src = fallback; }, { once: true });
        // A broken image may have finished failing before this element upgraded.
        if (img.complete && img.naturalWidth === 0)
            img.src = fallback;
    }
}
customElements.define('cover-art', CoverArt);
//# sourceMappingURL=cover-art.js.map