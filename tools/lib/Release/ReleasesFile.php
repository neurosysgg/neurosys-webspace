<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
use NeuroSYS\Support\File;

/**
 * The ReleasesFile class. The file a staged entry is pasted into.
 *
 * It answers one question, and only reading it: **which of the classes this entry names does
 * `data/releases.php` not import yet?** The entry is written with short names, because that is how
 * every entry beside it is written — so a class the file has never imported is a parse error rather
 * than a missing feature, and that is not hypothetical: the arrangement and the time spent are
 * `Model\Production` types, and no entry written before them imports anything from there.
 *
 * A class rather than a method on {@link EntryWriter}, because the writer is pure — it composes
 * expressions and renders them, and it has no business reading a file. And a class rather than the
 * same six lines in two commands, now that both `stage-release` and `release-track` print an entry.
 *
 * **It never writes.** `data/releases.php` is ordered by hand, newest first, and carries the one
 * field nothing can derive; generating into it would leave it half-authored and half-generated,
 * which is the arrangement `tools/build-css.mjs` already refuses when it rejects a rule in a
 * manifest.
 */
final readonly class ReleasesFile
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File $file
     */
    public function __construct(public File $file) {}

    /**
     * The site's own `data/releases.php`.
     *
     * @return self
     */
    public static function default(): self
    {
        return new self(Config::dataFile(DataFile::Releases));
    }

    /**
     * Which of these classes the file does not import.
     *
     * A file that cannot be read counts as importing nothing, so the answer is the whole list —
     * which is the right way round: being told to add an import that is already there costs a
     * glance, and not being told costs a data file that will not parse.
     *
     * @param list<string> $classes
     * @return list<string>
     */
    public function missingImports(array $classes): array
    {
        $source = $this->file->read() ?? '';

        return array_values(array_filter(
            $classes,
            static fn(string $class): bool => !str_contains($source, 'use ' . $class . ';'),
        ));
    }
}
