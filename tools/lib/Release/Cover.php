<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use NeuroSYS\Support\File;

/**
 * The Cover class. The image a release will publish, and which rung of the ladder it came off.
 *
 * The two are one object because the second decides what {@link Preflight} says about the first: an
 * image found in `web/` is ready to upload, and the same image still inside the FLAC is not.
 */
final readonly class Cover
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param File   $file
     * @param Source $source
     */
    public function __construct(public File $file, public Source $source) {}

    /**
     * @return string
     */
    public function name(): string
    {
        return $this->file->name();
    }

    /**
     * Whether this is the export prepared for the web, rather than a master or an embedded picture.
     *
     * @return bool
     */
    public function isWebExport(): bool
    {
        return $this->source === Source::WebExport;
    }
}
