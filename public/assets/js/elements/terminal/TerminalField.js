import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { TerminalWindow } from './TerminalWindow.js';
export class TerminalField extends NestedElement {
    parent() { return TerminalWindow; }
}
customElements.define(Tag.TerminalField, TerminalField);
//# sourceMappingURL=TerminalField.js.map