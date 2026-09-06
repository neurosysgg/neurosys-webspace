/**
 * Mirrors NeuroSYS\View\Html\WaveformAttribute — what a view tells <demo-waveform>.
 *
 * Read by the element rather than by the stylesheet, so drift here is a card with no waveform on
 * it: getAttribute answers null and the element draws nothing, which looks exactly like a demo
 * staged before waveforms existed.
 */
export enum WaveformAttribute {
  Peaks = 'peaks',
  Duration = 'duration',
}
