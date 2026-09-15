import { HtmlAttribute } from '../model/HtmlAttribute.js';
import { MachineAttribute } from '../model/MachineAttribute.js';
import { MachineTag } from '../model/MachineTag.js';
export class MachineFilter extends HTMLElement {
    typed = (event) => { this.filter(event.target.value); };
    connectedCallback() {
        this.removeAttribute(HtmlAttribute.Hidden);
        this.addEventListener('input', this.typed);
    }
    disconnectedCallback() {
        this.removeEventListener('input', this.typed);
    }
    filter(value) {
        const wanted = value.trim().toLowerCase();
        this.parentNode.querySelectorAll(`[${MachineAttribute.Entry}]`).forEach((row) => {
            row.toggleAttribute(HtmlAttribute.Hidden, !row.getAttribute(MachineAttribute.Entry).includes(wanted));
        });
    }
}
customElements.define(MachineTag.Filter, MachineFilter);
//# sourceMappingURL=MachineFilter.js.map