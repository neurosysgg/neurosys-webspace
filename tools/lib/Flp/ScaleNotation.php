<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

use NeuroSYS\Model\MusicalKey;

/**
 * The ScaleNotation class. Reads FL's spelling of a key — `D# Minor Natural (Aeolian)`.
 *
 * A **sibling of** `\NeuroSYS\Tool\Release\KeyNotation` rather than a replacement for it. The two
 * read different grammars written by different hands: `KeyNotation` reads `140 D#Min ill.flac`, a
 * filename convention a person types, and this reads what FL Studio prints on a scale marker. They
 * agree on the one normalisation they both need — `MusicalKey` spells only sharps, so a flat has to
 * be folded to its enharmonic equivalent or it resolves to nothing at all, quietly.
 *
 * **Everything that is not major or minor is refused**, and that is the interesting half. FL offers
 * every mode, so a project can be locked to `D# Dorian` — which `MusicalKey` cannot express, and
 * which has no honest rounding: naming its relative major would print a key the author never chose.
 * Null here becomes a WARN in the preflight and the next rung of the chain gets its turn, on the
 * same reasoning that has `Genre` refuse `bass house?` rather than invent a case for it.
 */
final readonly class ScaleNotation
{
    /** Root, optional accidental, then either the quality word or the mode in parentheses. */
    private const string PATTERN = '/^([A-G])([#b]?)\s+(Major|Minor)\b|^([A-G])([#b]?)\s+\w+\s*\((\w+)\)/i';

    /** The white key a flat actually names, since `MusicalKey` has no flats to offer. */
    private const array ENHARMONIC = [
        'C' => 'B', 'D' => 'C#', 'E' => 'D#', 'F' => 'E', 'G' => 'F#', 'A' => 'G#', 'B' => 'A#',
    ];

    /** The only two modes that name a key this site can render. */
    private const array MODES = ['ionian' => 'Major', 'aeolian' => 'Minor'];

    /**
     * @param string $written
     * @return MusicalKey|null null if the marker names no key this site can render.
     */
    public static function parse(string $written): ?MusicalKey
    {
        if (preg_match(self::PATTERN, trim($written), $match) !== 1) {
            return null;
        }

        // Two alternatives, so the groups land in one half or the other: `D# Minor Natural
        // (Aeolian)` matches the first, a bare `D# Dorian (Dorian)` only the second.
        $note       = $match[1] !== '' ? $match[1] : ($match[4] ?? '');
        $accidental = $match[1] !== '' ? $match[2] : ($match[5] ?? '');
        $quality    = $match[1] !== '' ? $match[3] : (self::MODES[strtolower($match[6] ?? '')] ?? '');

        if ($quality === '') {
            return null;
        }

        $note = strtoupper($note);
        $note = match (strtolower($accidental)) {
            'b'     => self::ENHARMONIC[$note],
            '#'     => $note . '#',
            default => $note,
        };

        return MusicalKey::tryFrom($note . ' ' . ucfirst(strtolower($quality)));
    }
}
