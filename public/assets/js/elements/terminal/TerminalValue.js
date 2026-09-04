import { NestedElement } from '../NestedElement.js';
import { TerminalField } from './TerminalField.js';
/** <terminal-value> — the row's value. */
export class TerminalValue extends NestedElement {
    parent() { return TerminalField; }
}
customElements.define('terminal-value', TerminalValue);
//# sourceMappingURL=TerminalValue.js.map