<?php

declare(strict_types=1);

namespace NeuroSYS\View\Html;

/**
 * The WaveformAttribute enum. What a view tells `<demo-waveform>`.
 *
 * Both are read by the element rather than by the stylesheet, which puts this with
 * {@link CoverArtAttribute} rather than with {@link ArrangementAttribute}: get a name wrong and
 * `getAttribute` answers null, the element falls back, and the card draws with no waveform on it —
 * which looks exactly like a demo staged before waveforms existed.
 */
enum WaveformAttribute: string implements AttributeName
{
    /**
     * The whole waveform, base64'd — see {@link \NeuroSYS\Model\Waveform::base64()}.
     *
     * ~2,700 characters per mix. It is an attribute rather than a fetch because it is smaller than
     * the request that would go and get it, and because a second route into `data/` is a second
     * thing the demo gate has to cover.
     */
    case Peaks = 'peaks';

    /**
     * How long the mix runs, in seconds.
     *
     * **Not redundant with the `<audio>` element's own duration.** The player carries
     * `preload="none"` — see {@link MediaPreload} — so its `duration` is `NaN` until somebody
     * presses play, and clicking the waveform to seek has to work before that.
     */
    case Duration = 'duration';

    /**
     * @return string
     */
    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * Never. Neither is dereferenced, and `peaks` is deliberately **not** a URL: the bytes travel
     * in the attribute precisely so nothing has to be fetched to draw the card.
     *
     * @return bool
     */
    public function isUrl(): bool
    {
        return false;
    }
}
