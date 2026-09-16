import { DropField } from '../model/DropField.js';
import { DropTag } from '../model/DropTag.js';
import { HtmlAttribute } from '../model/HtmlAttribute.js';
import { HtmlTag } from '../model/HtmlTag.js';
export class DropReveal extends HTMLElement {
    connectedCallback() {
        const token = location.hash.slice(1);
        const input = this.querySelector(`${HtmlTag.Input}[${HtmlAttribute.Name}="${DropField.Token}"]`);
        if (token === '' || input === null)
            return;
        input.setAttribute(HtmlAttribute.Value, token);
        this.toggleAttribute(HtmlAttribute.Hidden, true);
        history.replaceState(history.state, '', location.pathname + location.search);
    }
}
customElements.define(DropTag.Reveal, DropReveal);
//# sourceMappingURL=DropReveal.js.map