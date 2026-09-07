<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Production\Arrangement;
use NeuroSYS\Model\Production\Plugin;
use NeuroSYS\Model\Production\ProductionTime;
use NeuroSYS\Model\Production\Section;
use NeuroSYS\Model\Release;
use NeuroSYS\Support\Collection;
use NeuroSYS\Tool\Flp\TimeMarker;
use NeuroSYS\Tool\Php\Argument;
use NeuroSYS\Tool\Php\Call;
use NeuroSYS\Tool\Php\ClassConstant;
use NeuroSYS\Tool\Php\Entry;
use NeuroSYS\Tool\Php\Value;

/**
 * The EntryWriter class. Renders the `data/releases.php` entry for a folder.
 *
 * What it cannot know it says so about, in place, naming the file whose share id is wanted — which
 * is the thing worth having to hand while standing in HiDrive's web UI. The entry is valid as it
 * stands: a `Format` with no link renders its card and answers a click with a 503, a null cover
 * renders the placeholder, and an absent `embed:` renders no player.
 *
 * **Nothing here writes PHP as a string.** It composes {@link \NeuroSYS\Tool\Php\Expression}s and
 * one renderer turns them into source — the same arrangement the markup tree has, for the same
 * reason. This used to be a heredoc with `%s` holes, which meant `MusicalKey::DSharpMinor` was
 * assembled by concatenating a class name onto `$key->name`: a spelling nothing checked, in the one
 * file whose failure mode is a data file that will not parse. Now the case is passed as a real
 * `MusicalKey` and `Value` asks it what it is called.
 *
 * **`var_export()` on the whole `Release` was the obvious version of that idea, and it is the wrong
 * output.** It works — PHP emits `\NeuroSYS\Model\Release::__set_state(array(…))` and would
 * round-trip given a `__set_state()` on each class — but `ill.` comes out as 191 lines against 35,
 * with `Collection`'s private `items` and its `type` string on show, every `SoundCloudEmbed` default
 * spelled out, and no comment anywhere. `data/releases.php` is ordered and edited by hand, and the
 * three things it most needs are the share-id comments, the named arguments and the commented-out
 * lines for facts that do not exist yet — none of which an exported object can carry. So the tree
 * emits what a person would have typed, and `var_export()` does what it is genuinely good at:
 * quoting the leaves, in {@link Value}.
 */
final readonly class EntryWriter
{
    /**
     * @param ReleaseFolder        $folder Must have every required {@link Fact}; see {@link ReleaseFolder::missing()}.
     * @param SoundCloudEmbed|null $embed  The player, once the track exists — see {@link self::entry()}.
     * @return string
     */
    public static function write(ReleaseFolder $folder, ?SoundCloudEmbed $embed = null): string
    {
        return self::entry($folder, $embed)->render();
    }

    /**
     * The classes `data/releases.php` has to import for the entry to parse.
     *
     * @param ReleaseFolder        $folder
     * @param SoundCloudEmbed|null $embed
     * @return list<string>
     */
    public static function imports(ReleaseFolder $folder, ?SoundCloudEmbed $embed = null): array
    {
        return self::entry($folder, $embed)->imports();
    }

    /**
     * The entry, with the player either written out or written down.
     *
     * **The second argument is the one thing about a release that used to have no source at all.**
     * The three SoundCloud ids do not exist until the track is uploaded, so this has always emitted
     * them commented out, as a line to fill in by hand from an embed dialog. `release-track` is
     * where they now come from, and it hands the resulting {@link SoundCloudEmbed} straight back
     * here — so the entry printed after an upload and the entry printed before one are the same
     * code with one argument different.
     *
     * @param ReleaseFolder        $folder
     * @param SoundCloudEmbed|null $embed Null for a track that has not been uploaded yet.
     * @return Entry
     */
    private static function entry(ReleaseFolder $folder, ?SoundCloudEmbed $embed = null): Entry
    {
        $slug = (string) $folder->slug();

        $arguments = [
            new Argument(new Value($folder->title), 'title'),
            new Argument(new Value($folder->bpm), 'bpm'),
            new Argument(new Value($folder->key), 'key'),
            new Argument(new Value($folder->genre), 'genre'),
            new Argument(new Value(''), 'description', 'editorial — nothing in the folder supplies this'),
            new Argument(new Value(null), 'cover', 'share id for ' . ($folder->cover?->name() ?? 'the cover')),
            new Argument(self::formats($folder), 'formats'),
            ...($embed !== null
                ? [new Argument(self::embed($embed, $slug), 'embed')]
                : [
                    Argument::comment(
                        'SoundCloud ids exist only once the track is uploaded; the permalink is usually the slug.',
                    ),
                    Argument::pending(self::embed($embed, $slug), 'embed'),
                ]),
            ...self::production($folder),
        ];

        return new Entry($slug, Call::create(
            Release::class,
            new Collection(Argument::class)->with(...$arguments),
            stacked: true,
        ));
    }

    /**
     * `new Collection(Format::class)->with(…)`, one line per format, each naming its own file.
     *
     * @param ReleaseFolder $folder
     * @return Call
     */
    private static function formats(ReleaseFolder $folder): Call
    {
        $arguments = [];

        foreach ($folder->formats() as $format) {
            $arguments[] = new Argument(
                Call::create(Format::class, new Collection(Argument::class)->with(new Argument(new Value($format)))),
                comment: 'share id for ' . ($folder->fileFor($format)?->name() ?? $format->value),
            );
        }

        return self::collection(Format::class, $arguments);
    }

    /**
     * The player: the real ids where there are some, and the shape to fill in where there are not.
     *
     * **`secretToken` is omitted for a public track rather than written empty.** That is what the
     * argument's own default says and what `docs/releases.md`'s worked example does — and the
     * difference is not cosmetic on the page: `SoundCloudEmbed::toElement()` sends no attribute at
     * all for an empty token, because the client reads an absent attribute and an empty one
     * differently. The commented-out form keeps it, since a scheduled track is the case it exists
     * for and a line to uncomment should hold every line you will want.
     *
     * **A real one is stacked and a pending one is not**, which is what each is for. `data/releases.php`
     * writes every player it has across four lines with the names in a column, so that is what an
     * entry with real ids has to look like to sit beside them. The pending one is a single line
     * because it is a line to uncomment, and four commented lines are four chances to uncomment
     * three.
     *
     * @param SoundCloudEmbed|null $embed
     * @param string               $slug Stands in for the permalink until there is a real one.
     * @return Call
     */
    private static function embed(?SoundCloudEmbed $embed, string $slug): Call
    {
        $arguments = [
            new Argument(new Value($embed?->trackId ?? 0), 'trackId'),
            new Argument(new Value($embed?->permalink ?? $slug), 'permalink'),
        ];

        if ($embed === null || $embed->secretToken !== '') {
            $arguments[] = new Argument(new Value($embed?->secretToken ?? ''), 'secretToken');
        }

        return Call::create(
            SoundCloudEmbed::class,
            new Collection(Argument::class)->with(...$arguments),
            stacked: $embed !== null,
        );
    }

    /**
     * The three arguments only the project file supplies, where the folder has a project to read.
     *
     * @param ReleaseFolder $folder
     * @return list<Argument>
     */
    private static function production(ReleaseFolder $folder): array
    {
        $project = $folder->projectFile?->project;

        if ($project === null) {
            return [];
        }

        $arguments = [];

        if (!$project->structure()->isEmpty()) {
            $arguments[] = new Argument(self::arrangement($project->structure(), $project->ppq), 'arrangement');
        }

        if ($project->timeSpent !== null) {
            $arguments[] = new Argument(
                Call::onClass(ProductionTime::class, 'of', new Collection(Argument::class)->with(
                    new Argument(new Value(intdiv($project->timeSpent, 3600))),
                    new Argument(new Value(intdiv($project->timeSpent % 3600, 60))),
                )),
                'timeSpent',
            );
        }

        // Commented, and that is the point: these are candidates scraped out of the project's
        // plugin blobs, not a fact the format states — see NeuroSYS\Tool\Flp\Plugins. The author
        // keeps the ones worth crediting and deletes the rest, the way `description` is filled in.
        if ($project->plugins !== []) {
            $credits = array_map(
                static fn(string $name): Argument => new Argument(
                    Call::create(Plugin::class, new Collection(Argument::class)->with(new Argument(new Value($name)))),
                ),
                $project->plugins,
            );

            $arguments[] = Argument::pending(self::collection(Plugin::class, $credits), 'madeWith');
        }

        return $arguments;
    }

    /**
     * `new Arrangement(new Collection(Section::class)->with(…))`.
     *
     * The ppq is named only when it differs from the default every project tested uses, which keeps
     * the entry as terse as the rest of the file.
     *
     * @param Collection<TimeMarker> $markers
     * @param int              $ppq
     * @return Call
     */
    private static function arrangement(Collection $markers, int $ppq): Call
    {
        $sections = $markers->map(
            static fn(TimeMarker $marker): Argument => new Argument(
                Call::onClass(Section::class, 'named', new Collection(Argument::class)->with(
                    new Argument(new Value($marker->name)),
                    new Argument(new Value($marker->tick)),
                )),
            ),
        )->toValues();

        $arguments = [new Argument(self::collection(Section::class, $sections))];

        if ($ppq !== 96) {
            $arguments[] = new Argument(new Value($ppq), 'ppq');
        }

        return Call::create(Arrangement::class, new Collection(Argument::class)->with(...$arguments));
    }

    /**
     * `new Collection(Thing::class)->with(…)`, stacked.
     *
     * @param class-string   $type
     * @param list<Argument> $items
     * @return Call
     */
    private static function collection(string $type, array $items): Call
    {
        return Call::onValue(
            Call::create(
                Collection::class,
                new Collection(Argument::class)->with(new Argument(new ClassConstant($type))),
            ),
            'with',
            new Collection(Argument::class)->with(...$items),
            stacked: true,
        );
    }
}
