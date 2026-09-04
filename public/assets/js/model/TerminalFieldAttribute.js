/**
 * What <terminal-field> carries about itself.
 *
 * Client-only, so there is nothing to mirror: the server sends the tone inside the `fields` JSON
 * (see TerminalField::toArray()) and <terminal-window> is what turns it into an attribute. The
 * reader is the stylesheet — `terminal-field[tone="ok"] terminal-value` — which no test can follow,
 * so naming it here is the whole guard there is.
 */
export var TerminalFieldAttribute;
(function (TerminalFieldAttribute) {
    TerminalFieldAttribute["Tone"] = "tone";
})(TerminalFieldAttribute || (TerminalFieldAttribute = {}));
//# sourceMappingURL=TerminalFieldAttribute.js.map