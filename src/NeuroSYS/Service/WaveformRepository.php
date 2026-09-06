<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Config;
use NeuroSYS\Model\Demo;
use NeuroSYS\Model\DemoTrack;
use NeuroSYS\Model\Waveform;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\SearchableCollection;

/**
 * The WaveformRepository class. Reads a demo's waveform sidecars, keyed by the mix they belong to.
 *
 * The third of the repositories and the only one that reads **generated** data rather than a
 * hand-authored file. That is the whole difference between it and {@link DemoRepository}, which
 * `require`s one PHP file and gets every demo at once: a waveform is 2 KB of bytes written beside
 * the audio by `php tools/stage-demo.php --waveforms`, so there is one file per mix and the demo is
 * what says which mixes there are.
 *
 * **A missing sidecar is a demo without a waveform, not an error.** Every demo staged before the
 * format existed is in that state, and so is one whose master would not decode. The collection
 * simply has no entry under that label and {@link \NeuroSYS\View\DemoView} draws the card it always
 * drew. Same shape as {@link ProfileRepository}'s guard and {@link DemoRepository}'s: absent is a
 * valid answer, because the file is not in git and cannot be assumed.
 *
 * It reads no audio and computes nothing — the analysis is ~10 seconds of FFT per mix and lives in
 * `tools/`. See {@link Waveform}.
 */
class WaveformRepository
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param Directory|null $demos Where the per-demo directories are, or null for `data/demos/`.
     *                              Only tests pass this; the site has exactly one answer.
     */
    public function __construct(private readonly ?Directory $demos = null) {}

    /**
     * Every waveform this demo has one for, keyed by the mix's label.
     *
     * Keyed by label rather than by file name because that is what the page addresses a mix by, and
     * what the sidecar is named for — see {@link Waveform::fileIn()}.
     *
     * @param string $slug The demo's slug, which is also its directory.
     * @param Demo   $demo The demo itself, which is what says which mixes to look for.
     * @return SearchableCollection<Waveform>
     */
    public function forDemo(string $slug, Demo $demo): SearchableCollection
    {
        $directory  = $this->demos?->directory($slug) ?? Config::demoDir($slug);
        $collection = new SearchableCollection(Waveform::class);

        foreach ($demo->tracks as $track) {
            $waveform = $this->read($directory, $track);

            if ($waveform !== null) {
                $collection = $collection->with($track->label, $waveform);
            }
        }

        return $collection;
    }

    /**
     * One mix's waveform, or null for every way there might not be one.
     *
     * @param Directory $directory
     * @param DemoTrack $track
     * @return Waveform|null
     */
    private function read(Directory $directory, DemoTrack $track): ?Waveform
    {
        return Waveform::parse(Waveform::fileIn($directory, $track->label)->read());
    }
}
