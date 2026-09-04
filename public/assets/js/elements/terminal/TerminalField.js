import { NestedElement } from '../NestedElement.js';
import { TerminalWindow } from './TerminalWindow.js';
/**
 * <terminal-field tone> — one key/value row.
 *
 * `tone` says how the row reads; the stylesheet decides which half of it takes the accent. Mirrors
 * NeuroSYS\View\Terminal\TerminalField, which is the row the server declares.
 */
export class TerminalField extends NestedElement {
    parent() { return TerminalWindow; }
}
customElements.define('terminal-field', TerminalField);
//# sourceMappingURL=TerminalField.js.map