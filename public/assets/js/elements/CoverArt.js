import { CoverArtAttribute } from '../model/CoverArtAttribute.js';
import { HtmlTag } from '../model/HtmlTag.js';
import { Tag } from '../model/Tag.js';
export class CoverArt extends HTMLElement {
    wired = false;
    connectedCallback() {
        if (this.wired)
            return;
        this.wired = true;
        const src = this.getAttribute(CoverArtAttribute.Src);
        if (src === null || src === '')
            return;
        const img = document.createElement(HtmlTag.Img);
        const fallback = this.getAttribute(CoverArtAttribute.Fallback);
        img.alt = this.getAttribute(CoverArtAttribute.Alt) ?? '';
        if (fallback !== null && fallback !== '') {
            img.addEventListener('error', () => { img.src = fallback; }, { once: true });
        }
        img.src = src;
        this.replaceChildren(img);
    }
}
customElements.define(Tag.CoverArt, CoverArt);
//# sourceMappingURL=CoverArt.js.map