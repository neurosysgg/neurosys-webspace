<?php

namespace NeuroSYS\Model;

/**
 * The Format class. Represents a single downloadable format for a release.
 */
readonly class Format
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param ReleaseFormat $type The format type.
     * @param string        $url  The direct download URL; empty string if not yet available.
     */
    public function __construct(
        public ReleaseFormat $type,
        public string        $url,
    ) {}
}