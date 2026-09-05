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
     * @param ReleaseFolder $folder Must have every required {@link Fact}; see {@link ReleaseFolder::missing()}.
     * @return string
     */
    public static function write(ReleaseFolder $folder): string
    {
        return self::entry($folder)->render();
    }

    /**
     * The classes `data/releases.php` has to import for the entry to parse.
     *
     * @param ReleaseFolder $folder
     * @return list<string>
     */
    public static function imports(ReleaseFolder $folder): array
    {
        return self::entry($folder)->imports();
    }

    /**
     * @param ReleaseFolder $folder
     * @return Entry
     */
    private static function entry(ReleaseFolder $folder): Entry
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
            Argument::comment(
                'SoundCloud ids exist only once the track is uploaded; the permalink is usually the slug.',
            ),
            Argument::pending(self::embed($slug), 'embed'),
            ...self::production($folder),
        ];

        return new Entry($slug, Call::create(Release::class, $arguments, stacked: true));
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
                Call::create(Format::class, [new Argument(new Value($format))]),
                comment: 'share id for ' . basename((string) $folder->audio[$format->value]),
            );
        }

        return self::collection(Format::class, $arguments);
    }

    /**
     * The player, written out but commented: none of its three ids exists until the track is up.
     *
     * @param string $slug
     * @return Call
     */
    private static function embed(string $slug): Call
    {
        return Call::create(SoundCloudEmbed::class, [
            new Argument(new Value(0), 'trackId'),
            new Argument(new Value($slug), 'permalink'),
            new Argument(new Value(''), 'secretToken'),
        ]);
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

        if ($project->structure() !== []) {
            $arguments[] = new Argument(self::arrangement($project->structure(), $project->ppq), 'arrangement');
        }

        if ($project->timeSpent !== null) {
            $arguments[] = new Argument(
                Call::onClass(ProductionTime::class, 'of', [
                    new Argument(new Value(intdiv($project->timeSpent, 3600))),
                    new Argument(new Value(intdiv($project->timeSpent % 3600, 60))),
                ]),
                'timeSpent',
            );
        }

        // Commented, and that is the point: these are candidates scraped out of the project's
        // plugin blobs, not a fact the format states — see NeuroSYS\Tool\Flp\Plugins. The author
        // keeps the ones worth crediting and deletes the rest, the way `description` is filled in.
        if ($project->plugins !== []) {
            $credits = array_map(
                static fn(string $name): Argument => new Argument(
                    Call::create(Plugin::class, [new Argument(new Value($name))]),
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
     * @param list<\NeuroSYS\Tool\Flp\TimeMarker> $markers
     * @param int                                 $ppq
     * @return Call
     */
    private static function arrangement(array $markers, int $ppq): Call
    {
        $sections = array_map(
            static fn($marker): Argument => new Argument(Call::onClass(Section::class, 'named', [
                new Argument(new Value($marker->name)),
                new Argument(new Value($marker->tick)),
            ])),
            $markers,
        );

        $arguments = [new Argument(self::collection(Section::class, $sections))];

        if ($ppq !== 96) {
            $arguments[] = new Argument(new Value($ppq), 'ppq');
        }

        return Call::create(Arrangement::class, $arguments);
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
            Call::create(Collection::class, [new Argument(new ClassConstant($type))]),
            'with',
            $items,
            stacked: true,
        );
    }
}
