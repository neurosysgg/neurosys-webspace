import { CustomProperty } from '../../model/CustomProperty.js';
import { HtmlTag } from '../../model/HtmlTag.js';
import { Tag } from '../../model/Tag.js';
import { WaveformAttribute } from '../../model/WaveformAttribute.js';
import { STRIDE, WaveformBand } from '../../model/WaveformBand.js';

/**
 * <demo-waveform class="demo-track" peaks="…" duration="157"> … </demo-waveform>
 *
 * A demo mix's whole shape, drawn behind the player: the card becomes a deck screen. Each column is
 * as tall as that slice is loud and coloured by which of three frequency bands the slice is made
 * of, low nearest the middle and high at the tips. Played columns are lit and the rest are dimmed,
 * with a line where the two meet, and clicking anywhere on it needle-drops.
 *
 * **It is the one element here that neither builds its whole subtree nor builds nothing.** It
 * prepends a canvas and leaves the server's children exactly where they were, because those
 * children are the content — the label, the duration and a native <audio> — and the canvas is
 * decoration. That is what keeps the demo page's exception intact: CLAUDE.md accepts an empty box
 * on a release page because the page is still a page, and refuses it here because a demo *is* the
 * audio. With no JS this card lays out and plays exactly as it did before waveforms existed.
 *
 * The numbers are computed nowhere near here. `php tools/stage-demo.php --waveforms` spends ~10
 * seconds an FFT per mix and writes 2 KB beside the audio; this reads it out of an attribute.
 */
export class DemoWaveform extends HTMLElement {
  /**
   * The three bands, in the order they stack outward from the centre, and where each takes its
   * colour from. The stylesheet owns which colour that is; this owns only the order.
   */
  private static readonly PALETTE: ReadonlyArray<readonly [WaveformBand, CustomProperty]> = [
    [WaveformBand.Low, CustomProperty.WaveLow],
    [WaveformBand.Mid, CustomProperty.WaveMid],
    [WaveformBand.High, CustomProperty.WaveHigh],
  ];

  /**
   * What a band is drawn in when the stylesheet has not told it — the --fg token's own value.
   * A page whose stylesheet did not load has larger problems than the colour of this, and the one
   * thing worth guaranteeing is that it is not invisible. Same kind of literal as SoundCloudWidget's
   * accent, and for the same reason: a colour the element must have even with nothing to ask.
   */
  private static readonly UNSTYLED = '#e8e8f0';

  /** How much of its colour an unplayed column keeps. */
  private static readonly DIMMED = 0.3;

  /**
   * How much of the half-height the loudest column is allowed to reach.
   *
   * The card is not empty behind this: a label row sits at the top and a native <audio> control at
   * the bottom, and both were measured rather than guessed at — on a card at its minimum height the
   * label ends a quarter of the way down. A waveform that reached the full half-height would put
   * its brightest tips behind that text, which measured 3.26:1 against it once a track had been
   * played through, under AA's 4.5. Half the half-height is what clears the row exactly.
   *
   * It is also what the picture wants. A drop that touches both edges of its box reads as clipped;
   * headroom is what makes it read as loud.
   */
  private static readonly REACH = 0.52;

  /**
   * The columns, one character per byte.
   *
   * Kept as the string atob() answered with rather than as a Uint8Array, which is the shape it
   * obviously wants to be, for a reason worth stating: `charCodeAt()` is typed `number` where an
   * index into a typed array is `number | undefined` under noUncheckedIndexedAccess. The fallback
   * that difference demands can never be taken — decode() has already refused anything that is not
   * a whole number of columns — and an unreachable branch fails the coverage gate, which is the
   * gate doing its job. So the shape that needs no fallback is the right one.
   */
  private columns: string | null = null;
  private canvas: HTMLCanvasElement | null = null;
  private audio: HTMLAudioElement | null = null;
  private built = false;

  /** Held as one reference because both the player's events and the observer below take it. */
  private readonly repaint = (): void => { this.paint(); };

  /**
   * Watches the card's own box rather than the window's.
   *
   * **A window listener was not enough, and the pixels said so.** The backing store was being sized
   * from a card 8px shorter than the one that ended up on screen, because connectedCallback runs
   * before the native <audio> control has been laid out — so the drawing was scaled to a box that
   * did not exist yet, and nothing resized the window to correct it. This fires when the element's
   * box actually changes, which covers that, a font arriving late, and a container resize that
   * moves no window edge.
   *
   * Assumed present rather than guarded: the stylesheet already uses CSS nesting, which is three
   * years younger than this.
   */
  private readonly resizes = new ResizeObserver(this.repaint);

  connectedCallback(): void {
    if (!this.built) {
      this.built = true;
      this.build();
    }

    // Both, and both are load-bearing. The paint is what puts something on screen at once, since a
    // ResizeObserver is delivered on the rendering lifecycle and a page that is not producing frames
    // — a background tab, a pane that is not on screen — gets no callback until it is. Observing is
    // what corrects that first paint once the layout settles. Re-observing is a no-op.
    this.resizes.observe(this);
    this.paint();
  }

  disconnectedCallback(): void {
    this.resizes.disconnect();
  }

  /** The canvas, and the player it follows. Nothing here touches the children the server wrote. */
  private build(): void {
    this.columns = DemoWaveform.decode(this.getAttribute(WaveformAttribute.Peaks));

    if (this.columns === null) return;

    this.canvas = document.createElement(HtmlTag.Canvas);
    this.canvas.addEventListener('click', (event) => { this.seek(event); });
    this.prepend(this.canvas);

    this.audio = this.querySelector(HtmlTag.Audio);

    // 'seeking' rather than 'seeked' so the line moves while a drag is in progress, and
    // 'loadedmetadata' because that is when a real duration arrives to replace the attribute's.
    for (const event of ['timeupdate', 'seeking', 'ended', 'loadedmetadata']) {
      this.audio?.addEventListener(event, this.repaint);
    }
  }

  /**
   * Base64 to bytes, or null for anything that is not a whole number of columns.
   *
   * Null is a card with no waveform rather than an error, which is what a demo staged before this
   * existed looks like — see NeuroSYS\Model\Waveform, whose reader collapses the same cases.
   */
  private static decode(encoded: string | null): string | null {
    if (encoded === null || encoded === '') return null;

    let binary: string;

    try {
      binary = atob(encoded);
    } catch {
      return null;
    }

    if (binary.length < STRIDE || binary.length % STRIDE !== 0) return null;

    return binary;
  }

  private paint(): void {
    const canvas = this.canvas;
    const columns = this.columns;

    if (canvas === null || columns === null) return;

    const width = this.clientWidth;
    const height = this.clientHeight;

    // A card that has not been laid out yet — display:none, or a document with no layout at all.
    if (width < 1 || height < 1) return;

    const ratio = window.devicePixelRatio || 1;

    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);

    const context = canvas.getContext('2d');

    if (context === null) return;

    // The backing store is in device pixels and everything below is in CSS pixels.
    context.setTransform(ratio, 0, 0, ratio, 0, 0);
    context.clearRect(0, 0, width, height);

    const colours = this.colours();
    const count = columns.length / STRIDE;
    const step = width / count;
    const middle = height / 2;
    const played = this.progress() * width;

    for (let column = 0; column < count; column++) {
      const offset = column * STRIDE;
      const left = column * step;
      const total = colours.reduce((sum, [band]) => sum + columns.charCodeAt(offset + band), 0);

      if (total === 0) continue;

      const reach = (columns.charCodeAt(offset + WaveformBand.Level) / 255) * middle * DemoWaveform.REACH;

      context.globalAlpha = left + step <= played ? 1 : DemoWaveform.DIMMED;

      let stacked = 0;

      for (const [band, colour] of colours) {
        const grow = reach * (columns.charCodeAt(offset + band) / total);

        // A band with nothing in it is skipped rather than filled with a zero-height rect. Two
        // wasted calls a band sounds like nothing and is four per column: over 512 columns, several
        // times a second while a mix plays, it is most of the work the canvas is asked to do.
        if (grow > 0) {
          context.fillStyle = colour;
          // Ceil the width so neighbouring columns meet rather than leaving a hairline of card
          // between them at fractional steps.
          context.fillRect(left, middle - stacked - grow, Math.ceil(step), grow);
          context.fillRect(left, middle + stacked, Math.ceil(step), grow);
        }

        stacked += grow;
      }
    }

    context.globalAlpha = 1;
    this.drawPlayhead(context, played, height);
  }

  /** The line between what has been heard and what has not. Absent before playback starts. */
  private drawPlayhead(context: CanvasRenderingContext2D, played: number, height: number): void {
    if (played <= 0) return;

    context.fillStyle = this.colour(CustomProperty.WaveHigh);
    context.fillRect(played, 0, 1, height);
  }

  /** How far through the mix the player is, 0 when there is nothing to be through. */
  private progress(): number {
    const audio = this.audio;
    const duration = this.duration();

    if (audio === null || duration <= 0) return 0;

    return Math.min(1, Math.max(0, audio.currentTime / duration));
  }

  /**
   * How long the mix runs.
   *
   * The player's own answer where it has one, and the server's otherwise. The fallback is the point:
   * preload="none" means duration is NaN until somebody presses play, and clicking to seek has to
   * work before that — which is the whole reason the view sends the number at all.
   */
  private duration(): number {
    const audio = this.audio;

    if (audio !== null && Number.isFinite(audio.duration) && audio.duration > 0) return audio.duration;

    return Number(this.getAttribute(WaveformAttribute.Duration)) || 0;
  }

  /**
   * Needle-drop. The native control still does everything it did; this is the CDJ half.
   *
   * Setting currentTime with nothing loaded is deliberate and is what the spec asks for: with
   * readyState HAVE_NOTHING it becomes the default playback start position, so the mix begins where
   * it was clicked once the bytes arrive. Calling load() first would fetch a file nobody has asked
   * to hear yet, which is the cost preload="none" exists to avoid.
   */
  private seek(event: MouseEvent): void {
    const audio = this.audio;
    const duration = this.duration();
    const box = this.canvas?.getBoundingClientRect();

    if (audio === null || duration <= 0 || box === undefined || box.width < 1) return;

    const fraction = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));

    audio.currentTime = fraction * duration;
    this.paint();
  }

  /** Each band paired with the colour the stylesheet gives it, in stacking order. */
  private colours(): ReadonlyArray<readonly [WaveformBand, string]> {
    return DemoWaveform.PALETTE.map(([band, property]) => [band, this.colour(property)] as const);
  }

  private colour(property: CustomProperty): string {
    return getComputedStyle(this).getPropertyValue(property).trim() || DemoWaveform.UNSTYLED;
  }
}

customElements.define(Tag.DemoWaveform, DemoWaveform);
