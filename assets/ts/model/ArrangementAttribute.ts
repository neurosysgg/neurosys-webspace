/**
 * What <arrangement-section> carries about itself.
 *
 * Written by the server and read only by the stylesheet — `kind` picks the accent, `offset` is the
 * custom property the timeline positions each marker with. Named here for the reason
 * TerminalFieldAttribute is: no test can follow a CSS selector to the markup it matches, so the
 * name existing in one place is the whole guard there is.
 */
export enum ArrangementAttribute {
  Kind = 'kind',
}
