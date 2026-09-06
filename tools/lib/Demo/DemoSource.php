<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Demo;

use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Release\AudioStream;
use NeuroSYS\Tool\Release\Probe;

/**
 * The DemoSource class. One master named on the command line, and what becomes of it.
 *
 * A demo is several bounces of the same idea, so this is the part that repeats — and unlike a
 * release folder, **nothing here is discovered**. `stage-demo` takes the files it is given, in the
 * order it is given them, because `~/Music/neuro.SYS/demos/` holds four `V_RIOT_…_RC.wav`s beside
 * eight numbered bounces and a mastering export, and no rule over that directory picks the two you
 * meant to send. Choosing is the author's job; deriving a label from what they chose is this one's.
 */
final readonly class DemoSource
{
    /**
     * A version marker in a file name: `v3`, ` V17`, `_v12`, and an `RC` immediately after one.
     *
     * Anchored on a non-word character or the start so `Riot` does not read as a version, and taking
     * the **last** match so `V_RIOT_178_..._v12_RC` resolves on `v12-rc` rather than on the `V` of
     * the artist's name. Every file in that folder that carries a version carries it this way; one
     * that does not is not guessed at — see {@link self::labelFor()}.
     */
    private const string VERSION_PATTERN = '/(?:^|[^a-z0-9])v(\d{1,3})(?:[ _-]*(rc))?/i';

    /** What a file with no version marker is called. There is usually exactly one of these. */
    private const string UNVERSIONED = 'mix';

    /**
     * Constructs an instance of {@link self}.
     *
     * @param File             $file     The master, where it sits on this machine.
     * @param string           $label    What this mix is called in the URL and on the page.
     * @param Encoding         $encoding How it gets into `data/demos/`.
     * @param AudioStream|null $stream   What ffprobe says, or null if it could not read the file.
     */
    private function __construct(
        public File         $file,
        public string       $label,
        public Encoding     $encoding,
        public ?AudioStream $stream,
    ) {}

    /**
     * Reads one named file.
     *
     * @param string $path
     * @param string $label
     * @return self
     */
    public static function at(string $path, string $label): self
    {
        $file = new File($path);

        return new self($file, $label, Encoding::forSource($file), Probe::stream($file));
    }

    /**
     * Labels for a list of paths, in order, each unique.
     *
     * The uniqueness pass is the half worth having. Two files can perfectly reasonably derive the
     * same label — a `.wav` and a `.flac` of `v12`, or two bounces neither of which is versioned —
     * and a repeated label is not a cosmetic problem: the staged file is named for it, so the
     * second would overwrite the first and the page would list one mix twice.
     * {@link \NeuroSYS\Model\Demo} refuses a repeat outright; this is what stops one being written.
     *
     * @param list<string> $paths
     * @return list<string> One label per path, in the same order.
     */
    public static function labelsFor(array $paths): array
    {
        $labels = [];
        $seen   = [];

        foreach ($paths as $path) {
            $label = self::labelFor($path);
            $taken = $label;

            for ($n = 2; isset($seen[$taken]); $n++) {
                $taken = $label . '-' . $n;
            }

            $seen[$taken] = true;
            $labels[]     = $taken;
        }

        return $labels;
    }

    /**
     * Whether this is actually audio, rather than merely a file ffprobe did not refuse.
     *
     * **The obvious check does not work, and quietly.** `ffprobe` exits 0 on a text file called
     * `bounce v3.flac`: it takes the codec from the extension, reports `flac`, and answers `N/A`
     * for everything it would have had to decode to know. So `Probe::stream()` hands back a
     * perfectly-formed {@link AudioStream} describing nothing, and a demo stages "successfully"
     * with a player that will not start.
     *
     * A sample rate is what it cannot guess from a name. Zero means it never read a frame.
     *
     * @return bool
     */
    public function isReadable(): bool
    {
        return $this->stream !== null && $this->stream->rate > 0;
    }

    /**
     * How long this mix runs, rounded to whole seconds, or 0 where ffprobe said nothing.
     *
     * Zero is what {@link \NeuroSYS\Model\DemoTrack} reads as "not measured" and renders as no
     * duration at all, rather than as `0:00`.
     *
     * @return int
     */
    public function seconds(): int
    {
        return $this->stream === null ? 0 : (int) round($this->stream->duration);
    }

    /**
     * Where this mix ends up, named for its label.
     *
     * **The file is named for the label rather than for the master**, which is what keeps the URL
     * and the file in step: the page addresses `/demos/<slug>/<label>` and the controller resolves
     * `<label>.<ext>` beside it. It also means nothing of the original file name — which on these
     * masters is `V_RIOT_178_NIGHTS_ON_FIRE_REMIX_LIQUID_DNB_V17_MASTERING_EXPORT` — reaches the
     * server or a listener.
     *
     * @param Directory $into
     * @return File
     */
    public function target(Directory $into): File
    {
        return $into->file($this->label . '.' . $this->encoding->extensionFor($this->file));
    }

    /**
     * Writes this mix into $into, encoding or remuxing as its source calls for.
     *
     * @param Directory $into
     * @return bool
     */
    public function stage(Directory $into): bool
    {
        return Probe::encode($this->file, $this->target($into), $this->encoding->arguments());
    }

    /**
     * The label a file name suggests, or `mix` where it suggests none.
     *
     * @param string $path
     * @return string
     */
    private static function labelFor(string $path): string
    {
        $name = pathinfo($path, PATHINFO_FILENAME);

        if (preg_match_all(self::VERSION_PATTERN, $name, $matches, PREG_SET_ORDER) < 1) {
            return self::UNVERSIONED;
        }

        $last = $matches[array_key_last($matches)];

        return 'v' . (int) $last[1] . (($last[2] ?? '') !== '' ? '-rc' : '');
    }
}
