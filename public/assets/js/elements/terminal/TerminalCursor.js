import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { TerminalWindow } from './TerminalWindow.js';
/** <terminal-cursor> — the trailing prompt. CSS draws both the `$` and the blinking underscore. */
export class TerminalCursor extends NestedElement {
    parent() { return TerminalWindow; }
}
customElements.define(Tag.TerminalCursor, TerminalCursor);
//# sourceMappingURL=TerminalCursor.js.map