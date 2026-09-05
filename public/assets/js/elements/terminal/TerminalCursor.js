import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { TerminalWindow } from './TerminalWindow.js';
export class TerminalCursor extends NestedElement {
    parent() { return TerminalWindow; }
}
customElements.define(Tag.TerminalCursor, TerminalCursor);
//# sourceMappingURL=TerminalCursor.js.map