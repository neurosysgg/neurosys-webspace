import { Tag } from '../../model/Tag.js';
import { TerminalAttribute } from '../../model/TerminalAttribute.js';
import { TerminalFieldAttribute } from '../../model/TerminalFieldAttribute.js';
import { TerminalFieldKey } from '../../model/TerminalFieldKey.js';
import { TerminalTone } from '../../model/TerminalTone.js';
/**
 * <terminal-window label="release.log" command="…" fields="[…]" [narrow]></terminal-window>
 *
 * Builds its whole subtree. A view declares a Terminal — a label, a command line and typed rows —
 * and every node below this element is made here: the command, each row, the trailing cursor. The
 * title bar and its three lights are CSS, and the `$` sigil and blinking underscore are too.
 *
 * The tags it builds are its neighbours in this directory, one class per file, the way
 * src/NeuroSYS/View/Terminal/ is laid out on the other side of the same feature.
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
        this.replaceChildren(this.buildCommand(), ...this.fields().map((field) => TerminalWindow.buildField(field)), document.createElement(Tag.TerminalCursor));
    }
    buildCommand() {
        const command = document.createElement(Tag.TerminalCommand);
        command.textContent = this.getAttribute(TerminalAttribute.Command) ?? '';
        return command;
    }
    static buildField(data) {
        const field = document.createElement(Tag.TerminalField);
        const key = document.createElement(Tag.TerminalKey);
        const value = document.createElement(Tag.TerminalValue);
        key.textContent = data[TerminalFieldKey.Key];
        value.textContent = data[TerminalFieldKey.Value];
        // The tone goes on the row; the stylesheet decides which half of it takes the accent.
        const tone = data[TerminalFieldKey.Tone];
        if (tone !== TerminalTone.Plain)
            field.setAttribute(TerminalFieldAttribute.Tone, tone);
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
        const raw = this.getAttribute(TerminalAttribute.Fields);
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
        return typeof row[TerminalFieldKey.Key] === 'string'
            && typeof row[TerminalFieldKey.Value] === 'string'
            && Object.values(TerminalTone).includes(row[TerminalFieldKey.Tone]);
    }
}
customElements.define(Tag.TerminalWindow, TerminalWindow);
//# sourceMappingURL=TerminalWindow.js.map