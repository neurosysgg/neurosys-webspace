<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Demo;

use NeuroSYS\Config;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Release\FlacTag;
use NeuroSYS\Tool\Release\Probe;
use NeuroSYS\Tool\Release\ReleaseFolder;

/**
 * The DemoStage class. Everything one `stage-demo` run is about: which files, called what, as what.
 *
 * The counterpart of {@link \NeuroSYS\Tool\Release\ReleaseFolder}, and the difference between them
 * is the whole reason it is a separate class rather than a mode of that one. A release folder is
 * *read*: it is a prepared directory with a convention behind it, and six of a release's nine facts
 * are already in it. A demo is *chosen*: `~/Music/neuro.SYS/demos/` is a working directory, with
 * eight bounces of one bootleg and four release candidates beside them, and no rule over it picks
 * the two mixes worth sending. So this takes a list of files and derives only what a file can
 * honestly say — a title, a slug, a label, a duration — and everything else is a flag.
 *
 * The one fact it invents rather than reads is the password. See {@link Password}.
 */
final readonly class DemoStage
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string           $title   What the demo is called.
     * @param string           $slug    Its URL, and the directory its audio is staged into.
     * @param Collection<DemoSource> $sources The mixes, in the order they were named.
     */
    private function __construct(
        public string $title,
        public string $slug,
        public Collection $sources,
    ) {}

    /**
     * Reads the files named on the command line.
     *
     * The title ladder is two rungs and stops there, deliberately. A FLAC's `TITLE` comment is what
     * FL Studio wrote at export and is right when it is there; it is **empty on at least one master
     * in this folder** and absent from every non-FLAC, so the file name is the fallback — with the
     * version marker taken back off, since `alien house v3` is a file and `alien house` is a track.
     * There is no third rung guessing at anything, and `--title` is how a wrong answer is fixed.
     *
     * @param list<string> $paths  The masters, in the order they should appear on the page.
     * @param string|null  $title  From `--title`, where the derivation is wrong.
     * @param string|null  $slug   From `--slug`, where the derived one is unwieldy.
     * @return self
     */
    public static function of(array $paths, ?string $title = null, ?string $slug = null): self
    {
        $labels  = DemoSource::labelsFor($paths);
        $sources = [];

        foreach ($paths as $index => $path) {
            $sources[] = DemoSource::at($path, $labels[$index]);
        }

        $title ??= self::derivedTitle($paths[0] ?? '');

        return new self(
            $title,
            $slug ?? ReleaseFolder::slugFor($title),
            new Collection(DemoSource::class)->with(...$sources),
        );
    }

    /**
     * Where this demo's audio is staged — `data/demos/{slug}/`.
     *
     * Read off {@link Config} rather than derived here, because the site reads the same path to
     * serve it: a tool that staged somewhere else would produce a page of missing audio and no
     * error anywhere.
     *
     * @return Directory
     */
    public function directory(): Directory
    {
        return Config::demoDir($this->slug);
    }

    /**
     * Writes every mix into `data/demos/{slug}/`, creating the directory if it is not there.
     *
     * **Creating it is asked for here rather than done quietly further down**, which is the rule
     * {@link \NeuroSYS\Support\File} states in the negative: `write()` and `append()` both fail on a
     * missing directory, because an `@mkdir` added to "fix" the downloads log once made a directory
     * on the live server that had to be deleted by hand. This is a caller that genuinely wants one.
     *
     * Every mix is staged before this returns — see the `settled()` on the way out.
     *
     * @return Collection<DemoSource> The ones that failed, which is empty when all of them worked.
     */
    public function write(): Collection
    {
        $directory = $this->directory();

        if (!$directory->exists() && !$directory->create()) {
            return $this->sources;
        }

        // `settled()` because this predicate *does* the staging rather than describing it: it is
        // the one side-effecting callback on the site, and a lazy filter cannot carry one. See
        // {@link \NeuroSYS\Support\TypedItems::settled()}, which is named for this method.
        return $this->sources->where(fn(DemoSource $source): bool => !$source->stage($directory))->settled();
    }

    /**
     * The title a path suggests: the FLAC's own, or the file name with its version taken off.
     *
     * @param string $path
     * @return string
     */
    private static function derivedTitle(string $path): string
    {
        $tagged = Probe::tags(new File($path))[FlacTag::Title->value] ?? '';

        if (trim($tagged) !== '') {
            return trim($tagged);
        }

        $name = pathinfo($path, PATHINFO_FILENAME);

        // The same marker DemoSource labels on, taken back off — `alien house v3` is the name of a
        // file and `alien house` is the name of the track in it.
        return trim((string) preg_replace('/(?:^|[^a-z0-9])v\d{1,3}(?:[ _-]*rc)?\s*$/i', '', $name));
    }
}
