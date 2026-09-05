import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { TerminalField } from './TerminalField.js';
export class TerminalValue extends NestedElement {
    parent() { return TerminalField; }
}
customElements.define(Tag.TerminalValue, TerminalValue);
//# sourceMappingURL=TerminalValue.js.map