import { Tag } from '../../model/Tag.js';
import { NestedElement } from '../NestedElement.js';
import { TerminalField } from './TerminalField.js';

/** <terminal-key> — the row's label, in the first column, as wide as the window's widest label. */
export class TerminalKey extends NestedElement {
  protected parent(): CustomElementConstructor { return TerminalField; }
}

customElements.define(Tag.TerminalKey, TerminalKey);
