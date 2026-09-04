import { NestedElement } from '../NestedElement.js';
import { TerminalField } from './TerminalField.js';
/** <terminal-key> — the row's label, in the fixed-width first column. */
export class TerminalKey extends NestedElement {
    parent() { return TerminalField; }
}
customElements.define('terminal-key', TerminalKey);
//# sourceMappingURL=TerminalKey.js.map