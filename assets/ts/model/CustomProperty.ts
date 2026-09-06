/**
 * The CSS custom properties that pass between an element and the stylesheet.
 *
 * Client-only, like TerminalFieldAttribute and EmbedAttribute: nothing server-side writes one, and
 * no test can follow a property from the file that declares it to the one that reads it. Naming
 * them here is the whole guard.
 *
 * **They travel in both directions**, and the two halves fail differently. PlayerHeight is set by
 * the gate from its own height attribute so the placeholder is exactly as tall as the iframe that
 * replaces it — get the name wrong and the stylesheet's fallback quietly takes over, and the page
 * jumps on load. The three below go the other way: waveform.css declares them on <demo-waveform>
 * and the element reads them, so the stylesheet stays the one place a colour is chosen. Get one of
 * those wrong and the waveform draws in its own fallback colour, on a page that still works.
 */
export enum CustomProperty {
  PlayerHeight = '--player-height',

  WaveLow = '--wave-low',
  WaveMid = '--wave-mid',
  WaveHigh = '--wave-high',
}
