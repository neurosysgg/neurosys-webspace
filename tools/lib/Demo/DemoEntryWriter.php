<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Demo;

use NeuroSYS\Model\Demo;
use NeuroSYS\Model\DemoTrack;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\PasswordHash;
use NeuroSYS\Tool\Php\Argument;
use NeuroSYS\Tool\Php\Call;
use NeuroSYS\Tool\Php\ClassConstant;
use NeuroSYS\Tool\Php\Entry;
use NeuroSYS\Tool\Php\Value;

/**
 * The DemoEntryWriter class. Renders the `data/demos.php` entry for a staged demo.
 *
 * The same arrangement as {@link \NeuroSYS\Tool\Release\EntryWriter}, for the same reasons — it
 * composes {@link \NeuroSYS\Tool\Php\Expression}s and one renderer turns them into source, so
 * nothing here writes PHP as a string. What differs is what it cannot know, which is almost
 * nothing: a demo has one editorial field, and it is optional.
 *
 * **It prints; it does not write `data/demos.php`.** The tool writes audio and does not write that
 * file, which reads as an inconsistency and is not one: the audio is generated output with a name
 * derived from a label, and the data file is a hand-ordered list. Generating into it would leave it
 * half-authored and half-generated — the arrangement `tools/build-css.mjs` refuses when it rejects a
 * rule in a manifest, since a file either orders entries or is one.
 */
final readonly class DemoEntryWriter
{
    /**
     * @param DemoStage $stage
     * @param Password  $password The one minted for this demo — its hash, never its plaintext.
     * @return string
     */
    public static function write(DemoStage $stage, Password $password): string
    {
        return self::entry($stage, $password)->render();
    }

    /**
     * The classes `data/demos.php` has to import for the entry to parse.
     *
     * @param DemoStage $stage
     * @param Password  $password
     * @return list<string>
     */
    public static function imports(DemoStage $stage, Password $password): array
    {
        return self::entry($stage, $password)->imports();
    }

    /**
     * The `password:` argument on its own, for `--rotate`.
     *
     * Rotating is not restaging: the audio is where it was and the entry beside it is right except
     * for one line, so one line is what this prints. Anything more would invite pasting a whole
     * entry over a hand-written description.
     *
     * @param Password $password
     * @return string
     */
    public static function passwordArgument(Password $password): string
    {
        return self::password($password)->render();
    }

    /**
     * @param DemoStage $stage
     * @param Password  $password
     * @return Entry
     */
    private static function entry(DemoStage $stage, Password $password): Entry
    {
        return new Entry($stage->slug, Call::create(Demo::class, [
            new Argument(new Value($stage->title), 'title'),
            new Argument(self::password($password), 'password'),
            new Argument(self::tracks($stage), 'tracks'),
            // The one field nothing can derive, written out as the empty thing it is rather than
            // left off — a demo is sent with an ask attached, and a null here renders as
            // "work in progress", which is what a forgotten description looks like on the page.
            new Argument(new Value(null), 'description', comment: 'what you are asking them for'),
        ], stacked: true));
    }

    /**
     * `new PasswordHash('$2y$…')`.
     *
     * The digest is written into the entry rather than into a file of its own, because a demo's
     * credential belongs to the demo: one object, one password, and nothing to keep in step. The
     * plaintext is not here and is not anywhere — see {@link Password}.
     *
     * @param Password $password
     * @return Call
     */
    private static function password(Password $password): Call
    {
        return Call::create(PasswordHash::class, [new Argument(new Value($password->hash->digest()))]);
    }

    /**
     * `new Collection(DemoTrack::class)->with(…)`, one line per mix, newest first.
     *
     * @param DemoStage $stage
     * @return Call
     */
    private static function tracks(DemoStage $stage): Call
    {
        $arguments = [];

        foreach ($stage->sources as $source) {
            $arguments[] = new Argument(
                Call::create(DemoTrack::class, [
                    new Argument(new Value($source->label)),
                    new Argument(new Value($source->target($stage->directory())->name())),
                    new Argument(new Value($source->seconds())),
                ]),
                comment: $source->file->name(),
            );
        }

        return Call::onValue(
            Call::create(Collection::class, [new Argument(new ClassConstant(DemoTrack::class))]),
            'with',
            $arguments,
            stacked: true,
        );
    }
}
