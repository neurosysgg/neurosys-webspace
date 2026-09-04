/**
 * <cover-art src="…" fallback="/assets/img/cover-placeholder.svg" alt="…"></cover-art>
 *
 * The release cover, and its fallback when the file host 404s. It builds its own <img>, so
 * ReleaseView emits one tag rather than a wrapper around an image it also has to describe.
 *
 * The error listener is attached before src is assigned, so a response that fails immediately
 * cannot beat it — which is what the old complete && naturalWidth check was working around.
 * The fallback was an inline onerror= attribute once; as a listener it survives a strict script-src.
 */
export class CoverArt extends HTMLElement {
  private wired = false;

  connectedCallback(): void {
    if (this.wired) return;
    this.wired = true;

    const src = this.getAttribute('src');

    if (src === null || src === '') return;

    const img      = document.createElement('img');
    const fallback = this.getAttribute('fallback');

    img.alt = this.getAttribute('alt') ?? '';

    // once: true, so a fallback that is itself missing fails quietly instead of looping.
    if (fallback !== null && fallback !== '') {
      img.addEventListener('error', () => { img.src = fallback; }, { once: true });
    }

    img.src = src;
    this.replaceChildren(img);
  }
}

customElements.define('cover-art', CoverArt);
