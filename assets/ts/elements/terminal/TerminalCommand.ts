import { NestedElement } from '../NestedElement.js';
import { TerminalWindow } from './TerminalWindow.js';

/**
 * <terminal-command> — the command line above the output. CSS draws the `$` in front of it.
 *
 * Built by {@link TerminalWindow}, never written by a view, so the only way one exists somewhere
 * else is by hand — which is what the inherited guard refuses.
 */
export class TerminalCommand extends NestedElement {
  protected parent(): CustomElementConstructor { return TerminalWindow; }
}

customElements.define('terminal-command', TerminalCommand);
