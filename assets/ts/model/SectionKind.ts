/**
 * Mirror of NeuroSYS\Model\Production\SectionKind — what a part of an arrangement is doing.
 *
 * The reader is the stylesheet, not this side: `arrangement-section[kind="drop"]` is what gives a
 * drop its accent, and nothing in TypeScript ever asks. It is mirrored anyway because that is
 * exactly the kind of reader that fails in silence — a case renamed on the PHP side alone leaves
 * a rule matching nothing, which on a dark page reads as a layout bug rather than a typo.
 * test/js/enum-parity.test.mjs keeps the two in step.
 */
export enum SectionKind {
  Intro      = 'intro',
  Build      = 'build',
  Drop       = 'drop',
  Break      = 'break',
  Bridge     = 'bridge',
  Switchover = 'switch',
  BuildDown  = 'builddown',
  Outro      = 'outro',
}
