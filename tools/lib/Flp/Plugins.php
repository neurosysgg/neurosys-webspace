<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

/**
 * The Plugins class. What a project was made with, as far as its bytes will honestly say.
 *
 * **This produces candidates, not facts, and the distinction is the whole design.** Everything else
 * in this reader answers a question the format answers exactly: the tempo is a dword, the key is a
 * marker, the time spent is a double. A hosted plugin's name is not like that. FL records
 * `Fruity Wrapper` as the plugin — that is the host — and the name of the thing actually loaded is
 * buried in the wrapper's own serialised state, whose layout varies per plugin and per version.
 *
 * What *is* dependable is the shape of a string inside that blob: an eight-byte little-endian
 * length followed by that many bytes of ASCII. Scanning for it recovers `Serum2`, `Xfer Records`,
 * `Kilohearts`, `Ozone Imager 2`, `iZotope` and the kHs effects from every project tested — and,
 * because the scan tries every offset rather than following a structure it cannot see, some
 * wreckage alongside them where eight unrelated bytes happened to read as a plausible length.
 *
 * So this ranks by how often a candidate appears and hands the list to
 * {@link \NeuroSYS\Tool\Release\EntryWriter}, which emits it **commented out** for a person to trim
 * — the same arrangement `description` already has, and for the same reason: a value nothing can
 * derive is one a person supplies. Nothing here reaches `data/releases.php` unattended, so the site
 * never renders a guess.
 */
final readonly class Plugins
{
    /** An eight-byte length, then the string. */
    private const int PREFIX_SIZE = 8;

    /** Shorter is noise; longer is a path or a preset blob. */
    private const int MIN_LENGTH = 4;
    private const int MAX_LENGTH = 48;

    /** How many times a candidate has to appear before it is worth showing. */
    private const int MIN_OCCURRENCES = 3;

    /** How many to offer. The tail is where the wreckage collects. */
    private const int LIMIT = 12;

    /** A product or vendor name: letters, digits, and the punctuation names actually use. */
    private const string NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9 .\'&+_-]*$/';

    /**
     * Five or more capitals running into a lowercase letter, digits allowed between.
     *
     * The signature of a false positive: `ZTSVIOO2zone Ima` is `Ozone Imager 2` with four bytes of
     * something else welded to the front, and the join is always a shout of capitals hitting normal
     * text. **Five and not three**, because `LFOTool` is a real Xfer plugin and a threshold of
     * three throws it away — the rule has to be looser than the first draft of it, which is the
     * kind of thing only a corpus tells you.
     */
    private const string WRECKAGE_PATTERN = '/[A-Z]{5,}[0-9]*[a-z]/';

    /**
     * The plugins a project appears to have been made with, most-used first.
     *
     * @param FlpFile $flp
     * @return list<string>
     */
    public static function of(FlpFile $flp): array
    {
        $counts = [];

        foreach ($flp->all(EventId::PluginData) as $event) {
            foreach (self::strings($event->value) as $name) {
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }
        }

        $counts = array_filter($counts, static fn(int $n): bool => $n >= self::MIN_OCCURRENCES);

        arsort($counts);

        return array_slice(array_keys($counts), 0, self::LIMIT);
    }

    /**
     * The seven high bytes of a length this scan would accept — all of them zero.
     *
     * A prefix is a little-endian 64-bit count and {@link self::MAX_LENGTH} is 48, so a length this
     * class can use has its whole value in the low byte and nothing above it. See
     * {@link self::strings()} for why that is worth writing down rather than leaving to `unpack()`.
     */
    private const string HIGH_BYTES = "\0\0\0\0\0\0\0";

    /**
     * Every length-prefixed name in one wrapper blob.
     *
     * **The scan tries every byte offset, so what it does per offset is the whole cost of this
     * class** — and it is the whole cost of reading a project, since the plugin blobs are most of
     * a `.flp`. `hello world 140 future bass id.flp` carries 12.7 MB of them across 169 events, and
     * `Project::of()` spent 4.97 of its 5.10 seconds here.
     *
     * So the scan does not `unpack('P', substr(…))` at each offset — that is two allocations a byte
     * to produce a number that is then thrown away 99.9% of the time. The bounds this class already
     * declares say the same thing more cheaply: a length in `[4, 48]` **is** one low byte in that
     * range followed by seven zeroes, so the range check can be `ord()` and the rest of the
     * prefix a string comparison, and no unpacking is needed at all. That is an identity rather
     * than an approximation — every eight-byte run either satisfies both readings or neither.
     *
     * Checked as such: over all six projects in the corpus the candidate lists are byte-identical,
     * at 1.9× the speed. The one-byte test comes first because it rejects four fifths of offsets on
     * its own, before anything allocates.
     *
     * @param int|string $blob
     * @return list<string>
     */
    private static function strings(int|string $blob): array
    {
        if (!is_string($blob)) {
            return [];
        }

        $found = [];
        $size  = strlen($blob);
        $limit = $size - self::PREFIX_SIZE;

        for ($offset = 0; $offset <= $limit; $offset++) {
            $length = ord($blob[$offset]);

            if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH || $blob[$offset + 1] !== "\0") {
                continue;
            }

            if (substr($blob, $offset + 1, 7) !== self::HIGH_BYTES || $offset + self::PREFIX_SIZE + $length > $size) {
                continue;
            }

            $candidate = substr($blob, $offset + self::PREFIX_SIZE, $length);

            if (self::looksLikeAName($candidate)) {
                $found[] = $candidate;
            }
        }

        return $found;
    }

    /**
     * Whether a run of bytes reads as a product or vendor name.
     *
     * @param string $candidate
     * @return bool
     */
    private static function looksLikeAName(string $candidate): bool
    {
        return preg_match(self::NAME_PATTERN, $candidate) === 1
            // A hex id or an ALLCAPS token is not a name anyone typed.
            && preg_match('/[a-z]/', $candidate) === 1
            && preg_match(self::WRECKAGE_PATTERN, $candidate) !== 1;
    }
}
