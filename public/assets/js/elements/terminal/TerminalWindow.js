import { Tag } from '../../model/Tag.js';
import { TerminalAttribute } from '../../model/TerminalAttribute.js';
import { TerminalFieldAttribute } from '../../model/TerminalFieldAttribute.js';
import { TerminalFieldKey } from '../../model/TerminalFieldKey.js';
import { TerminalTone } from '../../model/TerminalTone.js';
export class TerminalWindow extends HTMLElement {
    built = false;
    connectedCallback() {
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
        const tone = data[TerminalFieldKey.Tone];
        if (tone !== TerminalTone.Plain)
            field.setAttribute(TerminalFieldAttribute.Tone, tone);
        field.append(key, value);
        return field;
    }
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