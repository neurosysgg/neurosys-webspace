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
     * Every length-prefixed name in one wrapper blob.
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
        $limit = strlen($blob) - self::PREFIX_SIZE;

        for ($offset = 0; $offset <= $limit; $offset++) {
            $length = unpack('P', substr($blob, $offset, self::PREFIX_SIZE))[1];

            if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
                continue;
            }

            if ($offset + self::PREFIX_SIZE + $length > strlen($blob)) {
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
