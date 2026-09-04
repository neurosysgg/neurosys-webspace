import { TerminalTone } from '../model/TerminalTone.js';
import { NestedElement } from './NestedElement.js';
/**
 * <terminal-window label="release.log" command="…" fields="[…]" [narrow]></terminal-window>
 *
 * Builds its whole subtree. A view declares a Terminal — a label, a command line and typed rows —
 * and every node below this element is made here: the command, each row, the trailing cursor. The
 * title bar and its three lights are CSS, and the `$` sigil and blinking underscore are too.
 *
 * With no JS the window is empty. The rows are the release's metadata and the 404's error line, so
 * that is a real cost and not only a cosmetic one — see CLAUDE.md.
 */
export class TerminalWindow extends HTMLElement {
    built = false;
    connectedCallback() {
        // connectedCallback fires again if the element is ever moved in the DOM.
        if (this.built)
            return;
        this.built = true;
        this.replaceChildren(this.buildCommand(), ...this.fields().map((field) => TerminalWindow.buildField(field)), document.createElement('terminal-cursor'));
    }
    buildCommand() {
        const command = document.createElement('terminal-command');
        command.textContent = this.getAttribute('command') ?? '';
        return command;
    }
    static buildField(data) {
        const field = document.createElement('terminal-field');
        const key = document.createElement('terminal-key');
        const value = document.createElement('terminal-value');
        key.textContent = data.key;
        value.textContent = data.value;
        // The tone goes on the row; the stylesheet decides which half of it takes the accent.
        if (data.tone !== TerminalTone.Plain)
            field.setAttribute('tone', data.tone);
        field.append(key, value);
        return field;
    }
    /**
     * The rows, as Terminal::toElement() wrote them.
     *
     * Anything malformed throws rather than rendering a half-terminal: this attribute is written by
     * our own server, so a bad one is a bug worth hearing about, not input worth tolerating.
     */
    fields() {
        const raw = this.getAttribute('fields');
        if (raw === null || raw === '')
            return [];
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed) || !parsed.every(TerminalWindow.isFieldData)) {
            throw new Error('<terminal-window> got a fields attribute that is not a list of rows.');
        }
        return parsed;
    }
    static isFieldData(value) {
        if (typeof value !== 'object' || value === null)
            return false;
        const row = value;
        return typeof row['key'] === 'string'
            && typeof row['value'] === 'string'
            && Object.values(TerminalTone).includes(row['tone']);
    }
}
/** The command line above the output. CSS draws the `$` in front of it. */
export class TerminalCommand extends NestedElement {
    parent() { return TerminalWindow; }
}
/** One key/value row. `tone` says how it reads — see TerminalTone. */
export class TerminalField extends NestedElement {
    parent() { return TerminalWindow; }
}
/** The row's label, in the fixed-width first column. */
export class TerminalKey extends NestedElement {
    parent() { return TerminalField; }
}
/** The row's value. */
export class TerminalValue extends NestedElement {
    parent() { return TerminalField; }
}
/** The trailing prompt. CSS draws both the `$` and the blinking underscore. */
export class TerminalCursor extends NestedElement {
    parent() { return TerminalWindow; }
}
customElements.define('terminal-window', TerminalWindow);
customElements.define('terminal-command', TerminalCommand);
customElements.define('terminal-field', TerminalField);
customElements.define('terminal-key', TerminalKey);
customElements.define('terminal-value', TerminalValue);
customElements.define('terminal-cursor', TerminalCursor);
//# sourceMappingURL=TerminalWindow.js.map