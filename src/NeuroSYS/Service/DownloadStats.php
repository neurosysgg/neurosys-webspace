<?php

declare(strict_types=1);

namespace NeuroSYS\Service;

use NeuroSYS\Support\BareArray;
use NeuroSYS\Support\BareString;
use NeuroSYS\Support\SearchableCollection;

/**
 * The DownloadStats class. What the downloads log adds up to.
 *
 * A class rather than an `array{int, array<string, int>, array<string, int>}` — a tuple that only
 * reads correctly if you remember which of the three slots is which and what each map is keyed
 * by. The same objection {@link Finding} answers for the preflight's findings.
 *
 * **Absent and empty are different things here, and the page says so.** A `null` where one of these
 * is expected means the log was never read, because {@link \NeuroSYS\Config::DOWNLOAD_LOGGING} is
 * off; an instance with a total of zero means it was read and held nothing. Those render as
 * different sentences, deliberately — an empty stats page that cannot tell you which of the two it
 * is reads as a bug.
 */
#[BareString(
    'int',
    'the declared type of the collection this builds, spelled as TypedItems::SCALARS spells it. '
    . 'A collection is declared by a class-string and there is no case for a scalar, so the name '
    . 'is written out here the way it is at every other such call site.',
)]
final readonly class DownloadStats
{
    /**
     * Constructs an instance of {@link self}.
     *
     * @param int                          $total    How many entries were read.
     * @param SearchableCollection<int>      $byFormat Counts keyed by `slug/format`.
     * @param SearchableCollection<int>      $byDay    Counts keyed by date, **in date order** — the
     *                        sort lives here rather than in the view, because a tally by day that
     *                        arrives shuffled is not a tally by day.
     */
    private function __construct(
        public int                   $total,
        public SearchableCollection  $byFormat,
        public SearchableCollection  $byDay,
    ) {}

    /**
     * The log's lines, added up.
     *
     * A line that will not parse costs that line and nothing else — the log is append-only and the
     * last line of a file being written to when the process died is the one that will be half
     * there. See {@link DownloadLogEntry::fromJson()}, which answers null rather than throwing.
     *
     * @param iterable<string> $lines
     * @return self
     */
    public static function fromLines(iterable $lines): self
    {
        $total    = 0;
        $byFormat = [];
        $byDay    = [];

        foreach ($lines as $line) {
            $entry = DownloadLogEntry::fromJson(trim($line));

            if ($entry === null) {
                continue;
            }

            $total++;
            $key            = $entry->slug . '/' . $entry->format;
            $byFormat[$key] = ($byFormat[$key] ?? 0) + 1;
            $day            = substr($entry->time, 0, 10) ?: '?';
            $byDay[$day]    = ($byDay[$day] ?? 0) + 1;
        }

        ksort($byDay);

        return new self($total, self::counts($byFormat), self::counts($byDay));
    }

    /**
     * A tally as a collection keyed by whatever it was grouped by.
     *
     * **The accumulator above stays a plain array on purpose.** `$byFormat[$key] = … + 1` is a
     * counted read of an append-only file, and {@link SearchableCollection::with()} copies rather
     * than writes — so accumulating into one would clone the whole tally once per log line. This is
     * the adapter at the door: the array is local to the loop that fills it, and the collection is
     * what crosses the boundary.
     *
     * The `(string)` cast is here because PHP casts a decimal-looking array key to `int` on the way
     * in, so a tally is `array-key`-keyed however carefully it was built; a collection's keys are
     * strings, and casting here is what spares {@link \NeuroSYS\View\StatsView} from zipping two
     * arrays back together.
     *
     * @param array<array-key, int> $counts
     * @return SearchableCollection<int>
     */
    #[BareArray(
        'the tally accumulator, written to in a loop where with() would copy. It is an argument '
        . 'rather than a boundary: this method is the adapter that turns it into the collection '
        . 'the caller gets.',
    )]
    private static function counts(array $counts): SearchableCollection
    {
        $collection = new SearchableCollection('int');

        foreach ($counts as $key => $count) {
            $collection = $collection->with((string) $key, $count);
        }

        return $collection;
    }

    /**
     * Whether the log held nothing — as distinct from never having been read.
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->total === 0;
    }
}
