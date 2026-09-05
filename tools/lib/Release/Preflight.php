<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use FilesystemIterator;
use NeuroSYS\Model\ReleaseFormat;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The Preflight class. Checks a folder is actually ready to upload.
 *
 * This is the half that earns its keep, because it runs *before* anything reaches HiDrive. A share
 * link is minted by hand in a web UI and is bound to the bytes it was minted for, so a file
 * re-exported after the fact costs an upload, a new link and an edit to `data/releases.php` — which
 * is exactly what `ill.`'s WAV cost when it turned out to be 16-bit/44.1kHz beside a 24-bit/48kHz
 * FLAC. Every check here is one that a real discrepancy in a real folder suggested.
 */
final readonly class Preflight
{
    /** Beyond this many seconds apart, two exports are not the same recording. */
    private const float TOLERANCE = 0.5;

    /**
     * @param ReleaseFolder $folder
     * @return list<Finding> In reporting order.
     */
    public static function check(ReleaseFolder $folder): array
    {
        if ($folder->master === null) {
            return [Finding::fail('no FLAC in the folder — there is nothing to read a release out of')];
        }

        return [
            ...self::facts($folder),
            ...self::project($folder),
            ...self::audio($folder),
            ...self::stems($folder),
            ...self::cover($folder),
        ];
    }

    /**
     * The project against the tags exported from it.
     *
     * These are the checks that only exist because the `.flp` is read at all, and they are worth
     * more than the facts it supplies. A tag is written once, at export; the project keeps moving
     * afterwards. So a project at 150 beside a FLAC tagged 140 is not a disagreement about what the
     * tempo is — it is a master that was exported before the last change and never re-exported,
     * which nothing else in this folder can notice.
     *
     * @param ReleaseFolder $folder
     * @return list<Finding>
     */
    private static function project(ReleaseFolder $folder): array
    {
        $file = $folder->projectFile;

        if ($file === null) {
            return [Finding::warn(
                'project: no .flp in the folder, so bpm, key and genre rest on the tags alone',
            )];
        }

        if ($file->project === null) {
            return [Finding::fail(sprintf('project: %s could not be read — %s', $file->name, $file->error))];
        }

        $project  = $file->project;
        $findings = [];

        // The canary. Every project tested carries a tempo, across four FL Studio versions, so an
        // absent one means the walk lost its footing rather than that the project has no tempo —
        // see FlpFile, where this is the only guard against a desynchronised read.
        if ($project->tempo === null) {
            return [Finding::fail(sprintf(
                'project: %s parsed but carries no tempo, which means an event was sized wrongly — '
                . 'this FL Studio version writes something tools/lib/Flp/ does not know about yet',
                $file->name,
            ))];
        }

        $tags = $folder->master !== null ? Probe::tags($folder->master) : [];

        $findings[] = self::agrees(
            'bpm',
            isset($tags[FlacTag::Bpm->value]) ? (string) (int) $tags[FlacTag::Bpm->value] : null,
            (string) (int) round($project->tempo),
        );

        $findings[] = self::agrees(
            'genre',
            $tags[FlacTag::Genre->value] ?? null,
            $project->genre,
        );

        $findings[] = self::agrees(
            'key',
            isset($tags[FlacTag::InitialKey->value])
                ? KeyNotation::parse($tags[FlacTag::InitialKey->value])?->value
                : null,
            $project->key?->value,
        );

        $findings[] = $project->hasKeyLock()
            ? Finding::ok(sprintf('project: %s, key lock set', $file->name))
            : Finding::warn(sprintf(
                'project: %s sets no key lock in the piano roll%s',
                $file->name,
                self::estimated($folder),
            ));

        return array_values(array_filter($findings));
    }

    /**
     * One project fact against the tag exported from it.
     *
     * Silent when either side has nothing to say: a missing tag is already the fact ladder's
     * business, and a project field left blank is not a disagreement.
     *
     * @param string      $fact
     * @param string|null $tagged
     * @param string|null $inProject
     * @return Finding|null
     */
    private static function agrees(string $fact, ?string $tagged, ?string $inProject): ?Finding
    {
        if ($tagged === null || $inProject === null || $tagged === $inProject) {
            return null;
        }

        return Finding::fail(sprintf(
            "%s: the project says '%s' and the FLAC is tagged '%s' — re-export the master, or "
            . 'correct the tag',
            $fact,
            $inProject,
            $tagged,
        ));
    }

    /**
     * What the notes suggest, for a project whose piano roll locks nothing.
     *
     * Offered as a sentence in a WARN and never as a value: the estimate agreed with three of the
     * four projects whose key is independently known, which is worth saying to a person and not
     * worth writing into `data/releases.php` unasked. See `KeyEstimate`.
     *
     * @param ReleaseFolder $folder
     * @return string
     */
    private static function estimated(ReleaseFolder $folder): string
    {
        $estimate = $folder->projectFile?->project?->keyEstimate;

        if ($estimate === null || !$estimate->isConfident()) {
            return '';
        }

        return sprintf(
            ' — its notes suggest %s (r=%.2f over %d notes), which is a guess, not a reading',
            $estimate->key->value,
            $estimate->correlation,
            $estimate->notes,
        );
    }

    /**
     * Every fact the entry needs, and what was in the folder when it could not be resolved.
     *
     * @param ReleaseFolder $folder
     * @return list<Finding>
     */
    private static function facts(ReleaseFolder $folder): array
    {
        $findings = [];

        foreach ($folder->missing() as $fact) {
            $raw = $folder->raw($fact);

            $findings[] = Finding::fail($raw === null
                ? sprintf('%s: nothing in the folder supplies it', $fact->value)
                : sprintf("%s: '%s' matches no case — add one, or correct the tag", $fact->value, $raw));
        }

        return $findings;
    }

    /**
     * Every claimed format has to be the same recording, at the master's resolution.
     *
     * @param ReleaseFolder $folder
     * @return list<Finding>
     */
    private static function audio(ReleaseFolder $folder): array
    {
        $master = $folder->master !== null ? Probe::stream($folder->master) : null;

        if ($master === null) {
            return [];
        }

        $findings = [];

        foreach ($folder->formats() as $format) {
            if ($format === ReleaseFormat::STEMS) {
                continue;
            }

            $file   = (string) $folder->fileFor($format);
            $probed = Probe::stream($file);

            if ($probed === null) {
                $findings[] = Finding::fail(sprintf('%s: %s could not be probed', $format->value, basename($file)));
                continue;
            }

            // A few milliseconds is encoder padding. Anything more means one file was re-exported
            // and the others were not, which nothing notices until a stranger downloads the odd one.
            if (abs($probed->duration - $master->duration) > self::TOLERANCE) {
                $findings[] = Finding::fail(sprintf(
                    "%s: %.2fs against the FLAC's %.2fs — one of them is a different export",
                    $format->value,
                    $probed->duration,
                    $master->duration,
                ));
            }

            // Lossless means lossless *of the master*. A 16-bit/44.1kHz WAV beside a 24-bit/48kHz
            // FLAC is still a lossless file, which is why nothing else here would call it wrong —
            // and it is still lower resolution than the FLAC offered next to it on the same page.
            if ($format->isLossless() && !$probed->matchesResolutionOf($master)) {
                $findings[] = Finding::fail(sprintf(
                    "%s: %s against the FLAC's %s — re-export it from the master",
                    $format->value,
                    $probed->resolution(),
                    $master->resolution(),
                ));
            }
        }

        return $findings === []
            ? [Finding::ok(sprintf(
                'every format is the same recording, and every lossless one is %s',
                $master->resolution(),
            ))]
            : $findings;
    }

    /**
     * The zip is what gets uploaded; the loose folder beside it is scratch, and the two can drift.
     *
     * @param ReleaseFolder $folder
     * @return list<Finding>
     */
    private static function stems(ReleaseFolder $folder): array
    {
        $zip = $folder->fileFor(ReleaseFormat::STEMS);

        if ($zip === null) {
            return [];
        }

        $entries = Probe::zipEntries($zip);

        if ($entries === null) {
            return [Finding::warn('stems: the zip could not be read, so it was not compared')];
        }

        // Root the walk where the zip is rooted, so both sides name their files the same way.
        $root  = strtok((string) array_key_first($entries), '/');
        $loose = $root !== false ? self::filesUnder($folder->path . '/' . $root, $root) : [];

        if ($loose === []) {
            return [Finding::ok(sprintf(
                'stems: %d files in the zip, with no loose folder to disagree',
                count($entries),
            ))];
        }

        return $entries == $loose
            ? [Finding::ok(sprintf('stems: the zip matches %s/, %d files', $root, count($entries)))]
            : [Finding::warn(sprintf(
                'stems: the zip and %s/ disagree — the zip is what ships, so rebuild it',
                $root,
            ))];
    }

    /**
     * @param ReleaseFolder $folder
     * @return list<Finding>
     */
    private static function cover(ReleaseFolder $folder): array
    {
        if ($folder->cover === null) {
            return [Finding::fail('cover: no image anywhere in the folder, and none embedded in the FLAC')];
        }

        return $folder->cover->isWebExport()
            ? [Finding::ok(sprintf('cover: %s, prepared for the web', $folder->cover->name()))]
            : [Finding::warn(sprintf(
                'cover: %s — export a web/ pair before uploading',
                $folder->cover->source->value,
            ))];
    }

    /**
     * Every file under a directory, keyed by its path relative to that directory's parent.
     *
     * @param string $directory
     * @param string $prefix    The directory's own name, so the keys match a zip's internal paths.
     * @return array<string, int>
     */
    private static function filesUnder(string $directory, string $prefix): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $walk  = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );
        $files = [];

        foreach ($walk as $file) {
            $files[$prefix . '/' . $walk->getSubPathname()] = $file->getSize();
        }

        return $files;
    }
}
