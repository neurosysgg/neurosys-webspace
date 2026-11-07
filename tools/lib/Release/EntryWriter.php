<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Production\Arrangement;
use NeuroSYS\Model\Production\ProductionTime;
use NeuroSYS\Model\Production\Section;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Service\Data\Record;
use NeuroSYS\Service\Data\RecordKey;
use NeuroSYS\Service\Data\ReleaseCodec;
use NeuroSYS\Tool\Flp\TimeMarker;
use stdClass;

/**
 * The EntryWriter class. The `data/releases.json` entry for a folder, and what is left to fill in.
 *
 * **The entry is a {@link Release}**, built from what the folder knows and written by the one codec
 * the site reads it with, so a staged entry cannot be one the site would refuse. It is valid as it
 * stands: a format with no link renders its card and answers a click with a 503, a null cover
 * renders the placeholder, an absent embed renders no player, and an empty description renders
 * nothing.
 *
 * **What it cannot know it lists, rather than writes** — {@link self::notes()}. JSON has no comment
 * to leave beside a field, so the share id each link still wants, the SoundCloud ids that do not
 * exist before an upload, and the plugin names scraped out of the project (candidates for a person
 * to trim, not facts) are said on stderr, naming the file each one is for: the thing worth having
 * to hand while standing in HiDrive's web UI.
 */
final readonly class EntryWriter
{
    /**
     * The release the folder makes.
     *
     * @param ReleaseFolder        $folder Must have every required {@link Fact}; see {@link ReleaseFolder::missing()}.
     * @param SoundCloudEmbed|null $embed  The player, once the track exists. Null for one that has
     *                                     not been uploaded yet: the three SoundCloud ids are the
     *                                     one thing about a release a folder cannot supply, and
     *                                     `release-track` hands the uploaded track's back here.
     * @return Release
     */
    public static function release(ReleaseFolder $folder, ?SoundCloudEmbed $embed = null): Release
    {
        $project = $folder->projectFile?->project;

        return new Release(
            title:       (string) $folder->title,
            bpm:         (int) $folder->bpm,
            key:         $folder->key ?? throw self::unready(Fact::Key),
            genre:       $folder->genre ?? throw self::unready(Fact::Genre),
            description: '',
            cover:       null,
            formats:     $folder->formats()->map(static fn(ReleaseFormat $format): Format => new Format($format)),
            embed:       $embed,
            arrangement: $project === null || $project->structure()->isEmpty()
                ? null
                : new Arrangement(
                    $project->structure()->map(
                        static fn(TimeMarker $marker): Section => Section::named($marker->name, $marker->tick),
                    ),
                    $project->ppq,
                ),
            // To the minute, which is all the page shows of it.
            timeSpent:   $project?->timeSpent === null
                ? null
                : ProductionTime::of(intdiv($project->timeSpent, 3600), intdiv($project->timeSpent % 3600, 60)),
        );
    }

    /**
     * The entry as `data/releases.json` holds it, on its own: `{"slug": {…}}`, for `--print`.
     *
     * @param ReleaseFolder        $folder
     * @param SoundCloudEmbed|null $embed
     * @return string
     */
    public static function write(ReleaseFolder $folder, ?SoundCloudEmbed $embed = null): string
    {
        $entry = new stdClass();
        $entry->{(string) $folder->slug()} = ReleaseCodec::releaseValue(self::release($folder, $embed));

        return Record::encode($entry);
    }

    /**
     * What the entry still wants, by hand, one line each.
     *
     * @param ReleaseFolder        $folder
     * @param SoundCloudEmbed|null $embed
     * @return list<string>
     */
    public static function notes(ReleaseFolder $folder, ?SoundCloudEmbed $embed = null): array
    {
        $notes = [sprintf('%s: editorial — nothing in the folder supplies it', RecordKey::Description->value)];
        $notes[] = sprintf(
            '%s: the share id for %s',
            RecordKey::Cover->value,
            $folder->cover?->name() ?? 'the cover',
        );

        foreach ($folder->formats() as $format) {
            $notes[] = sprintf(
                '%s %s: the share id for %s',
                RecordKey::Formats->value,
                $format->value,
                $folder->fileFor($format)?->name() ?? $format->value,
            );
        }

        if ($embed === null) {
            $notes[] = sprintf(
                '%s: the SoundCloud ids exist only once the track is uploaded — release-track --upload'
                . ' writes them in',
                RecordKey::Embed->value,
            );
        }

        $plugins = $folder->projectFile?->project->plugins ?? [];
        if ($plugins !== []) {
            $notes[] = sprintf(
                '%s: candidates the project names — credit the ones worth crediting: %s',
                RecordKey::MadeWith->value,
                implode(', ', $plugins),
            );
        }

        return $notes;
    }

    /**
     * A folder handed here without a fact {@link Preflight} requires — a caller's mistake, since
     * both commands check the folder first.
     *
     * @param Fact $fact
     * @return ReleaseVerificationException
     */
    private static function unready(Fact $fact): ReleaseVerificationException
    {
        return new ReleaseVerificationException(
            sprintf('The folder has no %s: check it before staging.', $fact->value),
        );
    }
}
