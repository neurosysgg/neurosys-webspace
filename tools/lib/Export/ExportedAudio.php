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
     * Named for the release rather than for the file on disk: a working master called `ill..wav`
     * and a track called `ill.` are the same thing, and the far end is told the second.
     *
     * @param string|null $filename
     * @return FilePart
     */
    public function part(?string $filename = null): FilePart
    {
        return FilePart::at($this->file, $filename);
    }
}
