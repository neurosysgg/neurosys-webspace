<?php
declare(strict_types=1);

namespace NeuroSYS\Model\Link;

/**
 * The FileLink interface. A file hosted somewhere off-site that the release links to.
 *
 * Implementations own their host's URL shape so that release data can name just the
 * file — an id, a key, a path — instead of repeating a full URL per entry.
 *
 * Deliberately not {@link \Stringable}: an implicit string conversion would let a link
 * slip into a heredoc unnoticed, and every use site should be visible as a call.
 */
interface FileLink
{
    /** Returns the absolute URL this link resolves to. */
    public function url(): string;
}
