/**
 * <terminal-window label="release.log" [narrow]> and the tags that go inside it.
 *
 * The frame carries no behaviour: the title bar, its three lights, the `$` sigil and the blinking
 * cursor are all drawn by CSS, and the content is server-rendered. They are registered anyway, so
 * that the vocabulary a view may emit is declared in one place — and so that this file is what you
 * find when you go looking for where <terminal-cursor> comes from.
 */
export class TerminalWindow extends HTMLElement {}

/** A shell command line. CSS draws the `$` in front of it. */
export class TerminalCommand extends HTMLElement {}

/** One key/value row of output. */
export class TerminalField extends HTMLElement {}

/** The field's label. `error` colours it as a failure rather than a heading. */
export class TerminalKey extends HTMLElement {}

/** A value that reads as success. */
export class TerminalOk extends HTMLElement {}

/** The trailing prompt. CSS draws both the `$` and the blinking underscore. */
export class TerminalCursor extends HTMLElement {}

customElements.define('terminal-window', TerminalWindow);
customElements.define('terminal-command', TerminalCommand);
customElements.define('terminal-field', TerminalField);
customElements.define('terminal-key', TerminalKey);
customElements.define('terminal-ok', TerminalOk);
customElements.define('terminal-cursor', TerminalCursor);
