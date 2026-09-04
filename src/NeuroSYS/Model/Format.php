<?php

declare(strict_types=1);

namespace NeuroSYS\Model;

use NeuroSYS\Model\Link\FileLink;

/**
 * The Format class. Represents a single downloadable format for a release.
 */
readonly class Format
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ReleaseFormat  $type The format type.
     * @param FileLink|null  $link Where the file is hosted, or null if it isn't uploaded yet.
     *                             A null link still renders the download card — clicking it
     *                             returns a 503 rather than a redirect, which is the useful
     *                             state while a release is staged.
     */
    public function __construct(
        public ReleaseFormat $type,
        public ?FileLink     $link = null,
    ) {}
}
