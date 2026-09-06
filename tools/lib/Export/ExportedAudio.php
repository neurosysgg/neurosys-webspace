<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Export;

use NeuroSYS\Support\File;
use NeuroSYS\Tool\Http\FilePart;

/**
 * The ExportedAudio class. A file to upload, and how it came to exist.
 *
 * The second half is the one worth carrying. `stage-release` already reports where every fact came
 * from, on the reasoning that a bpm from a tag and a bpm from a filename are not the same claim;
 * this is that column for the audio itself, and today it always reads *prepared by hand*, because
 * {@link FlStudioExport} cannot run yet. A report that said nothing about it would read as though a
 * render had happened.
 */
final readonly class ExportedAudio
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File         $file
     * @param RenderFormat $format
     * @param ExportSource $source
     */
    public function __construct(
        public File         $file,
        public RenderFormat $format,
        public ExportSource $source,
    ) {}

    /**
     * A file that was already on disk.
     *
     * @param File $file
     * @return self
     * @throws ExportException if the file is not there, or is not a format anything renders to.
     */
    public static function prepared(File $file): self
    {
        if (!$file->exists()) {
            throw new ExportException(sprintf('%s is not a file.', $file->path));
        }

        $format = RenderFormat::of($file);

        if ($format === null) {
            throw new ExportException(sprintf(
                '%s is not audio this can publish. Expected one of: %s.',
                $file->name(),
                implode(', ', array_column(RenderFormat::cases(), 'value')),
            ));
        }

        return new self($file, $format, ExportSource::Prepared);
    }

    /**
     * The file's name on disk.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->file->name();
    }

    /**
     * The file's size in bytes, or 0 if it has gone away since.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->file->size();
    }

    /**
     * This audio as the field of a multipart request.
     *
     * **Named for the release rather than for the file on disk**, where the caller says what the
     * release is called: a working master is `ill (140 d#min skrillie dubstep).wav` in a folder and
     * `ill.wav` on somebody else's server, and those are the same recording under a working name
     * and a published one. The paragraph said so for as long as the only call site passed nothing,
     * which made it a description of a parameter rather than of a behaviour.
     *
     * **The stem, not the whole filename**, because the extension is not the caller's to choose:
     * {@link self::$format} is what the file on disk actually is, and a name disagreeing with it
     * would be the one thing about this part that was not read off the bytes. That is also what
     * gives `$format` a reader — it was carried and never asked.
     *
     * The slug rather than the title, because a title is arbitrary text — `ill.` ends in the
     * character that separates a name from its extension, and nothing stops one holding a slash.
     * {@link \NeuroSYS\Tool\Release\ReleaseFolder::slugFor()} is already the site's answer to
     * "what is this release called where a name has to be safe", and it is the same string
     * `track[permalink]` carries.
     *
     * @param string|null $name The release's slug, without an extension. Null keeps the name on disk.
     * @return FilePart
     */
    public function part(?string $name = null): FilePart
    {
        return FilePart::at($this->file, $name !== null ? $name . '.' . $this->format->value : null);
    }
}
