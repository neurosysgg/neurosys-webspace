import { TerminalTone } from '../../model/TerminalTone.js';

/** One output row, as Terminal::toElement() serialises it. */
interface TerminalFieldData {
  key: string;
  value: string;
  tone: TerminalTone;
}

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
  private built = false;

  connectedCallback(): void {
    // connectedCallback fires again if the element is ever moved in the DOM.
    if (this.built) return;
    this.built = true;

    this.replaceChildren(
      this.buildCommand(),
      ...this.fields().map((field) => TerminalWindow.buildField(field)),
      document.createElement('terminal-cursor'),
    );
  }

  private buildCommand(): HTMLElement {
    const command = document.createElement('terminal-command');
    command.textContent = this.getAttribute('command') ?? '';

    return command;
  }

  private static buildField(data: TerminalFieldData): HTMLElement {
    const field = document.createElement('terminal-field');
    const key   = document.createElement('terminal-key');
    const value = document.createElement('terminal-value');

    key.textContent   = data.key;
    value.textContent = data.value;

    // The tone goes on the row; the stylesheet decides which half of it takes the accent.
    if (data.tone !== TerminalTone.Plain) field.setAttribute('tone', data.tone);

    field.append(key, value);

    return field;
  }

  /**
   * The rows, as Terminal::toElement() wrote them.
   *
   * Anything malformed throws rather than rendering a half-terminal: this attribute is written by
   * our own server, so a bad one is a bug worth hearing about, not input worth tolerating.
   */
  private fields(): TerminalFieldData[] {
    const raw = this.getAttribute('fields');

    if (raw === null || raw === '') return [];

    const parsed: unknown = JSON.parse(raw);

    if (!Array.isArray(parsed) || !parsed.every(TerminalWindow.isFieldData)) {
      throw new Error('<terminal-window> got a fields attribute that is not a list of rows.');
    }

    return parsed;
  }

  private static isFieldData(value: unknown): value is TerminalFieldData {
    if (typeof value !== 'object' || value === null) return false;

    const row = value as Record<string, unknown>;

    return typeof row['key'] === 'string'
      && typeof row['value'] === 'string'
      && Object.values<unknown>(TerminalTone).includes(row['tone']);
  }
}

customElements.define('terminal-window', TerminalWindow);
