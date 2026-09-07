<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Demo;

use NeuroSYS\Tool\Release\Finding;

/**
 * The DemoPreflight class. Checks the files named are files worth putting behind a password.
 *
 * Thinner than {@link \NeuroSYS\Tool\Release\Preflight} and for a different reason. That one runs
 * before a HiDrive share link is minted, and a link is bound to the bytes it was minted for, so a
 * re-export after the fact costs an upload and an edit. Nothing here is that expensive — restaging
 * is one command. What it is guarding against is the other thing: a demo that looks staged and is
 * not, sent to somebody with a password, who opens a page of dead players.
 *
 * The checks are the ones that folder actually suggests. `alien house.flac` in it is **zero bytes**;
 * three of the four `V_RIOT_…` release candidates are the same length within a second of each other;
 * and two of the demos are `.wav` at 1.8 Mbit/s, which is a fine master and a terrible thing to hand
 * to a browser.
 */
final readonly class DemoPreflight
{
    /** Under this, a "mix" is more likely a bounce that stopped early than a track. */
    private const int SHORT = 30;

    /**
     * @param DemoStage $stage
     * @return list<Finding> In reporting order.
     */
    public static function check(DemoStage $stage): array
    {
        if ($stage->sources->isEmpty()) {
            return [Finding::fail('no files named — a demo is the mixes it carries')];
        }

        return [
            ...self::identity($stage),
            ...self::files($stage),
            ...self::consistency($stage),
        ];
    }

    /**
     * The two facts that decide the URL.
     *
     * @param DemoStage $stage
     * @return list<Finding>
     */
    private static function identity(DemoStage $stage): array
    {
        if (trim($stage->title) === '') {
            return [Finding::fail('no title — nothing in the file name or its tags said what this is; pass --title')];
        }

        if ($stage->slug === '') {
            return [Finding::fail(sprintf(
                "'%s' produces an empty slug — there is no URL for it; pass --slug",
                $stage->title,
            ))];
        }

        $findings = [Finding::ok(sprintf("/demos/%s — '%s'", $stage->slug, $stage->title))];

        // Restaging is normal — a new bounce of the same idea — but it is worth saying out loud,
        // because the audio is overwritten and any mix not named this time is left behind as a file
        // no entry points at.
        if ($stage->directory()->exists()) {
            $findings[] = Finding::warn(sprintf(
                'data/demos/%s/ already exists — its audio will be overwritten, and any mix not '
                . 'named on this command line is left behind unreferenced',
                $stage->slug,
            ));
        }

        return $findings;
    }

    /**
     * Every named file, one line each: is it there, can it be read, how long is it.
     *
     * @param DemoStage $stage
     * @return list<Finding>
     */
    private static function files(DemoStage $stage): array
    {
        $findings = [];

        foreach ($stage->sources as $source) {
            $name = $source->file->name();

            if (!$source->file->exists()) {
                $findings[] = Finding::fail(sprintf("%s — no such file", $source->file->path));
                continue;
            }

            // The folder holds a zero-byte `alien house.flac`. ffprobe reads nothing out of it and
            // ffmpeg would write nothing out of it, so without this the demo stages "successfully"
            // and the page carries a player that will not start.
            if ($source->file->size() === 0) {
                $findings[] = Finding::fail(sprintf('%s — empty file', $name));
                continue;
            }

            // Not `stream === null`: ffprobe answers a text file named `.flac` with a stream it
            // inferred from the extension and no sample rate at all. See DemoSource::isReadable().
            if (!$source->isReadable()) {
                $findings[] = Finding::fail(sprintf('%s — ffprobe cannot read this as audio', $name));
                continue;
            }

            $seconds = $source->seconds();

            if ($seconds < self::SHORT) {
                $findings[] = Finding::warn(sprintf(
                    '%s → %s — only %ds; is this the bounce you meant?',
                    $name,
                    $source->label,
                    $seconds,
                ));
                continue;
            }

            $findings[] = Finding::ok(sprintf(
                '%s → %s  %d:%02d  %s',
                $name,
                $source->label,
                intdiv($seconds, 60),
                $seconds % 60,
                $source->encoding === Encoding::Mp3 ? 'encode' : 'remux',
            ));
        }

        return $findings;
    }

    /**
     * Whether the mixes are plausibly mixes of the same thing.
     *
     * A page listing `v3` and `v4` says they are two versions of one track, and nothing else on the
     * site checks that. Durations minutes apart usually mean a file was picked out of the wrong
     * folder — which in a directory holding four different bootlegs is an easy thing to do.
     *
     * A warning rather than a failure: an intro-less edit really can be a minute shorter, and this
     * cannot tell that from a mistake. It only has to make the author look.
     *
     * @param DemoStage $stage
     * @return list<Finding>
     */
    private static function consistency(DemoStage $stage): array
    {
        $lengths = $stage->sources
            ->map(static fn(DemoSource $source): int => $source->seconds())
            ->where(static fn(int $seconds): bool => $seconds !== 0)
            ->toValues();

        if (count($lengths) < 2) {
            return [];
        }

        $spread = max($lengths) - min($lengths);

        if ($spread <= 60) {
            return [];
        }

        return [Finding::warn(sprintf(
            'the mixes are %d:%02d apart in length — check they are versions of the same track',
            intdiv($spread, 60),
            $spread % 60,
        ))];
    }
}
