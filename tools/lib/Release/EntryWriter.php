<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

/**
 * The EntryWriter class. Renders the `data/releases.php` entry for a folder.
 *
 * What it cannot know it says so about, in place, naming the file whose share id is wanted — which
 * is the thing worth having to hand while standing in HiDrive's web UI. The entry is valid as it
 * stands: a `Format` with no link renders its card and answers a click with a 503, a null cover
 * renders the placeholder, and an absent `embed:` renders no player.
 *
 * This is the one place in the repo that writes PHP source, which is what its heredoc is for. It is
 * also the reason this layer is not under `src/` — not because a heredoc is banned there (the verify
 * script's markup check is narrower than that), but because `deploy.sh` would ship it and
 * `phpunit.xml.dist` would count it.
 */
final readonly class EntryWriter
{
    /**
     * @param ReleaseFolder $folder Must have every required {@link Fact}; see {@link ReleaseFolder::missing()}.
     * @return string
     */
    public static function write(ReleaseFolder $folder): string
    {
        $calls = [];

        foreach ($folder->formats() as $format) {
            $calls[$format->value] = sprintf('new Format(ReleaseFormat::%s),', $format->name);
        }

        $width = $calls === [] ? 0 : max(array_map(strlen(...), $calls));
        $lines = [];

        foreach ($calls as $format => $call) {
            $lines[] = sprintf(
                '            %s  // share id for %s',
                str_pad($call, $width),
                basename((string) $folder->audio[$format]),
            );
        }

        // The format lines carry their own full indentation and `%s` therefore sits at the closing
        // marker's column: a heredoc indents only the *first* line of an interpolated block, so a
        // placeholder at body indent would push that one line four columns past its siblings.
        return sprintf(
            <<<'PHP'
                    '%s' => new Release(
                        title:       %s,
                        bpm:         %d,
                        key:         MusicalKey::%s,
                        genre:       Genre::%s,
                        description: '',   // editorial — nothing in the folder supplies this
                        cover:       null, // share id for %s
                        formats: new Collection(Format::class)->with(
                %s
                        ),
                        // SoundCloud ids exist only once the track is uploaded; the permalink is usually the slug.
                        // embed: new SoundCloudEmbed(trackId: 0, permalink: '%s', secretToken: ''),
                    ),
                PHP,
            $folder->slug(),
            var_export($folder->title, true),
            $folder->bpm,
            $folder->key?->name,
            $folder->genre?->name,
            $folder->cover?->name() ?? 'the cover',
            implode("\n", $lines),
            $folder->slug(),
        );
    }
}
