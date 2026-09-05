import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { TerminalWindow } from './TerminalWindow.js';
export class TerminalCommand extends NestedElement {
    parent() { return TerminalWindow; }
}
customElements.define(Tag.TerminalCommand, TerminalCommand);
//# sourceMappingURL=TerminalCommand.js.map