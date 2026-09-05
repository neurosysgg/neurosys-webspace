<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use NeuroSYS\Model\Genre;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\ReleaseFormat;

/**
 * The ReleaseFolder class. Everything a prepared release folder says about itself.
 *
 * Six of a `Release`'s nine facts are already in the folder it was exported into — in the master's
 * Vorbis comments, in the filename convention, and in which files exist at all. This reads them, and
 * records **where each one came from**, because a bpm taken from a tag and a bpm taken from a
 * filename are not equally trustworthy. See `docs/authoring.md`.
 *
 * Each fact reads its sources in order and stops at the first hit. Where none hits, the property is
 * null and {@link Preflight} says so — nothing is guessed, on the same reasoning that has
 * `HttpMethod::tryFrom()` return null rather than assume GET.
 *
 * The three facts a folder cannot know are not here at all: the description is editorial, the
 * HiDrive share ids are minted in a web UI, and the SoundCloud ids do not exist until the track is
 * uploaded. `Release` already models that half-state, so {@link EntryWriter} can emit a renderable
 * entry without them.
 */
final readonly class ReleaseFolder
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param string      $path
     * @param string|null $master   The release's FLAC, or null if it has none.
     * @param string|null $title
     * @param int|null    $bpm
     * @param MusicalKey|null $key
     * @param Genre|null  $genre
     * @param Cover|null  $cover
     * @param string|null $date     The master's `DATE`, which `Release` has no field for.
     * @param array<string, string> $audio ReleaseFormat value => path, in the order the catalogue lists them.
     * @param array<string, string> $raw   Fact value => the string that resolved to nothing.
     * @param array<string, Source> $sources Fact value => where it was read from.
     */
    public function __construct(
        public string $path,
        public ?string $master,
        public ?string $title,
        public ?int $bpm,
        public ?MusicalKey $key,
        public ?Genre $genre,
        public ?Cover $cover,
        public ?string $date,
        public array $audio,
        private array $raw = [],
        private array $sources = [],
    ) {}

    /**
     * Reads a folder.
     *
     * @param string $path
     * @return self
     */
    public static function at(string $path): self
    {
        $path    = rtrim($path, '/');
        $master  = self::fileWith($path, [ReleaseFormat::FLAC->value]);
        $tags    = $master !== null ? Probe::tags($master) : [];
        $sources = [];
        $raw     = [];

        $title = $tags[FlacTag::Title->value] ?? null;

        if ($title !== null) {
            $sources[Fact::Title->value] = Source::FlacTitleTag;
            $sources[Fact::Slug->value]  = Source::DerivedFromTitle;
        }

        $bpm    = isset($tags[FlacTag::Bpm->value]) ? (int) $tags[FlacTag::Bpm->value] : null;
        $rawKey = $tags[FlacTag::InitialKey->value] ?? null;

        if ($bpm !== null) {
            $sources[Fact::Bpm->value] = Source::FlacBpmTag;
        }

        if ($rawKey !== null) {
            $sources[Fact::Key->value] = Source::FlacKeyTag;
        }

        // The filename convention — `140 D#Min ill remix package.zip` — was the older releases' only
        // record of either fact, so it stays as a fallback now that both masters carry the tags.
        if ($bpm === null || $rawKey === null) {
            foreach (glob($path . '/*') ?: [] as $file) {
                if (preg_match('/(\d{2,3})\s+([A-G][#b]?(?:maj|min))/i', basename($file), $match) !== 1) {
                    continue;
                }

                if ($bpm === null) {
                    $bpm                       = (int) $match[1];
                    $sources[Fact::Bpm->value] = Source::Filename;
                }

                if ($rawKey === null) {
                    $rawKey                    = $match[2];
                    $sources[Fact::Key->value] = Source::Filename;
                }

                break;
            }
        }

        $rawGenre = $tags[FlacTag::Genre->value] ?? null;

        if ($rawGenre !== null) {
            $sources[Fact::Genre->value] = Source::FlacGenreTag;
        }

        $key   = $rawKey !== null ? KeyNotation::parse($rawKey) : null;
        $genre = $rawGenre !== null ? Genre::tryFrom($rawGenre) : null;

        // The string that resolved to nothing is kept beside the null, so the report can say what
        // the folder actually contained instead of only that it did not understand it.
        if ($key === null && $rawKey !== null) {
            $raw[Fact::Key->value] = $rawKey;
        }

        if ($genre === null && $rawGenre !== null) {
            $raw[Fact::Genre->value] = $rawGenre;
        }

        $cover = self::coverIn($path, $master);

        if ($cover !== null) {
            $sources[Fact::Cover->value] = $cover->source;
        }

        $sources[Fact::Formats->value] = Source::FilesPresent;

        return new self(
            path:    $path,
            master:  $master,
            title:   $title,
            bpm:     $bpm,
            key:     $key,
            genre:   $genre,
            cover:   $cover,
            date:    $tags[FlacTag::Date->value] ?? null,
            audio:   self::audioIn($path),
            raw:     $raw,
            sources: $sources,
        );
    }

    /**
     * The slug that keys this release in `data/releases.php` and addresses it in a URL.
     *
     * The trailing punctuation goes first and deliberately: `ReleaseView` splits a trailing `!`, `.`
     * or `?` off the title to accent it, so it is presentation rather than identity — `ill.` is
     * `/releases/ill`.
     *
     * @return string|null
     */
    public function slug(): ?string
    {
        return $this->title !== null ? self::slugFor($this->title) : null;
    }

    /**
     * The slug a title would produce.
     *
     * @param string $title
     * @return string
     */
    public static function slugFor(string $title): string
    {
        $slug = strtolower(rtrim($title, '!.?'));

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
    }

    /**
     * Where a fact was read from, or null if the folder did not supply it.
     *
     * @param Fact $fact
     * @return Source|null
     */
    public function sourceOf(Fact $fact): ?Source
    {
        return $this->sources[$fact->value] ?? null;
    }

    /**
     * What the folder contained for a fact that resolved to nothing.
     *
     * @param Fact $fact
     * @return string|null
     */
    public function raw(Fact $fact): ?string
    {
        return $this->raw[$fact->value] ?? null;
    }

    /**
     * Whether a fact was resolved.
     *
     * @param Fact $fact
     * @return bool
     */
    public function has(Fact $fact): bool
    {
        return match ($fact) {
            Fact::Title   => $this->title !== null,
            Fact::Slug    => $this->slug() !== null,
            Fact::Bpm     => $this->bpm !== null,
            Fact::Key     => $this->key !== null,
            Fact::Genre   => $this->genre !== null,
            Fact::Formats => $this->audio !== [],
            Fact::Cover   => $this->cover !== null,
        };
    }

    /**
     * The required facts this folder could not supply.
     *
     * @return list<Fact>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            Fact::cases(),
            fn(Fact $fact): bool => $fact->isRequired() && !$this->has($fact),
        ));
    }

    /**
     * The path of a downloadable format, or null if the folder has no file for it.
     *
     * @param ReleaseFormat $format
     * @return string|null
     */
    public function fileFor(ReleaseFormat $format): ?string
    {
        return $this->audio[$format->value] ?? null;
    }

    /**
     * The formats this folder can offer, in the order the catalogue lists them.
     *
     * @return list<ReleaseFormat>
     */
    public function formats(): array
    {
        return array_map(ReleaseFormat::from(...), array_keys($this->audio));
    }

    /**
     * Finds the release's downloadable files, keyed by the {@link ReleaseFormat} they satisfy.
     *
     * A format is present because its file is, which is the only claim a folder can make. The stems
     * package is the zip rather than the loose folder beside it: the zip is what gets uploaded, and
     * the two are free to drift — which is what {@link Preflight} then checks.
     *
     * @param string $path
     * @return array<string, string>
     */
    private static function audioIn(string $path): array
    {
        $found = [];

        // Filtered from the cases rather than listed, the way `HttpMethod::allowed()` builds the
        // Allow header: a format added to the enum is looked for here without this being touched.
        foreach (ReleaseFormat::cases() as $format) {
            if ($format === ReleaseFormat::STEMS) {
                continue;
            }

            if (($file = self::fileWith($path, [$format->value])) !== null) {
                $found[$format->value] = $file;
            }
        }

        foreach (glob($path . '/*.zip') ?: [] as $zip) {
            if (stripos(basename($zip), 'remix package') !== false) {
                $found[ReleaseFormat::STEMS->value] = $zip;
                break;
            }
        }

        // Ordered the way the catalogue already offers them rather than the way the enum declares
        // them: the lossless masters, then the lossy copy, then the remix package, which is a
        // different kind of thing from a master and reads last. Emitting `cases()` order instead
        // would silently reshuffle the download cards of any release regenerated through this tool.
        $rank = static fn(string $format): int => match (true) {
            $format === ReleaseFormat::STEMS->value    => 2,
            ReleaseFormat::from($format)->isLossless() => 0,
            default                                    => 1,
        };

        // PHP's sorts are stable, so formats of equal rank keep the enum's own order.
        uksort($found, static fn(string $a, string $b): int => $rank($a) <=> $rank($b));

        return $found;
    }

    /**
     * Finds the cover to publish, preferring the export that was prepared for the web.
     *
     * Three rungs, because the two releases carried their art in different places. A `web/` JPEG is
     * what belongs on the site; a master at the folder root is usable but large; and the master's
     * own embedded picture is the last resort, which is where `hello world!` kept its only copy
     * until one was exported.
     *
     * @param string      $path
     * @param string|null $master Consulted only if no image file exists.
     * @return Cover|null
     */
    private static function coverIn(string $path, ?string $master): ?Cover
    {
        $images = ['jpg', 'jpeg', 'png'];

        if (($web = self::fileWith($path . '/web', $images)) !== null) {
            return new Cover($web, Source::WebExport);
        }

        if (($root = self::fileWith($path, $images)) !== null) {
            return new Cover($root, Source::FolderRoot);
        }

        if ($master !== null && Probe::hasPicture($master)) {
            return new Cover($master, Source::EmbeddedPicture);
        }

        return null;
    }

    /**
     * The first file directly in a folder whose name ends with one of the given extensions.
     *
     * @param string       $folder
     * @param list<string> $extensions Without the dot, lower case.
     * @return string|null
     */
    private static function fileWith(string $folder, array $extensions): ?string
    {
        foreach (glob($folder . '/*') ?: [] as $file) {
            foreach ($extensions as $extension) {
                if (is_file($file) && str_ends_with(strtolower($file), '.' . $extension)) {
                    return $file;
                }
            }
        }

        return null;
    }
}
