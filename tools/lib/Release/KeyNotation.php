<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Release;

use NeuroSYS\Model\MusicalKey;

/**
 * The KeyNotation class. Reads a written key — `D#Min`, `f#maj`, `Ebm` — as a {@link MusicalKey}.
 *
 * Two normalisations, and both exist because of what writes the string. Case varies: the filename
 * convention has produced `140 d#min` far more often than `140 D#Min`. And `MusicalKey` spells only
 * sharps, so a flat has to be folded to its enharmonic equivalent or it resolves to nothing at all
 * — quietly, which is the failure this tooling is arranged to avoid.
 */
final readonly class KeyNotation
{
    /** Note, optional accidental, optional mode. Anchored, so `140 D#Min` is not a key. */
    private const string PATTERN = '/^([A-G])([#b]?)\s*(maj|min|m)?\b/i';

    /** The white key a flat actually names, since `MusicalKey` has no flats to offer. */
    private const array ENHARMONIC = [
        'C' => 'B', 'D' => 'C#', 'E' => 'D#', 'F' => 'E', 'G' => 'F#', 'A' => 'G#', 'B' => 'A#',
    ];

    /**
     * @param string $written
     * @return MusicalKey|null null if the string names no key this site can render.
     */
    public static function parse(string $written): ?MusicalKey
    {
        if (preg_match(self::PATTERN, trim($written), $match) !== 1) {
            return null;
        }

        $note       = strtoupper($match[1]);
        $accidental = strtolower($match[2]);
        $mode       = strtolower($match[3] ?? '');

        $note = match ($accidental) {
            'b'     => self::ENHARMONIC[$note],
            '#'     => $note . '#',
            default => $note,
        };

        return MusicalKey::tryFrom($note . (in_array($mode, ['min', 'm'], true) ? ' Minor' : ' Major'));
    }
}
