/**
 * Mirrors NeuroSYS\View\Terminal\TerminalAttribute — what the server tells <terminal-window>.
 *
 * `Command` and `Fields` are read here; `Label` and `Narrow` are read by the stylesheet, which the
 * parity test cannot see. They are mirrored anyway, so this file is the whole list either way.
 */
export var TerminalAttribute;
(function (TerminalAttribute) {
    TerminalAttribute["Label"] = "label";
    TerminalAttribute["Command"] = "command";
    TerminalAttribute["Fields"] = "fields";
    TerminalAttribute["Narrow"] = "narrow";
})(TerminalAttribute || (TerminalAttribute = {}));
//# sourceMappingURL=TerminalAttribute.js.map