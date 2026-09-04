/**
 * Mirrors NeuroSYS\View\Terminal\TerminalAttribute — what the server tells <terminal-window>.
 *
 * `Command` and `Fields` are read here; `Label` and `Narrow` are read by the stylesheet, which the
 * parity test cannot see. They are mirrored anyway, so this file is the whole list either way.
 */
export enum TerminalAttribute {
  Label   = 'label',
  Command = 'command',
  Fields  = 'fields',
  Narrow  = 'narrow',
}
