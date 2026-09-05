<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

/**
 * The Fact enum. One field of a `Release` that a prepared folder can supply.
 *
 * Exists so that a fact, its value and where it came from are addressed by the same thing. The
 * report used to key its provenance by field-name strings and reach the unresolved raw value with
 * `$facts['raw' . ucfirst($field)]` — an array key built by concatenation, which is one typo away
 * from a null that reads as "the folder did not say".
 */
enum Fact: string
{
    case Title   = 'title';
    case Slug    = 'slug';
    case Bpm     = 'bpm';
    case Key     = 'key';
    case Genre   = 'genre';
    case Formats = 'formats';
    case Cover   = 'cover';

    /**
     * Whether the staged entry cannot be written without this fact.
     *
     * The cover is not on this list: `Release` renders a placeholder for a null one, so a missing
     * cover is a thing to fix before uploading rather than a thing that stops the entry existing.
     *
     * @return bool
     */
    public function isRequired(): bool
    {
        return match ($this) {
            self::Title, self::Bpm, self::Key, self::Genre => true,
            self::Slug, self::Formats, self::Cover         => false,
        };
    }
}
