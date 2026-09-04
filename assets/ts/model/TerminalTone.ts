/**
 * Mirror of NeuroSYS\View\Terminal\TerminalTone — how a terminal row reads.
 *
 * The tone lands on the row, and CSS decides which half of it takes the accent: Ok colours the
 * value, Error colours the key. Kept in step with the PHP enum by test/js/enum-parity.test.mjs.
 */
export enum TerminalTone {
  Plain = 'plain',
  Ok    = 'ok',
  Error = 'error',
}
