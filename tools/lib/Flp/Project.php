<?php

declare(strict_types=1);

namespace NeuroSYS\Tool\Flp;

use NeuroSYS\Model\MusicalKey;

/**
 * The Project class. Everything a `.flp` says about the music in it.
 *
 * The typed half of the reader: {@link FlpFile} knows where one event ends and the next begins, and
 * this knows what any of them mean. Splitting them is what lets the byte-level rules be tested
 * against a project assembled by hand while the musical ones are checked against real releases.
 *
 * This sits **upstream of everything `ReleaseFolder` reads**. `docs/authoring.md` puts it plainly —
 * *FL Studio writes the tags this reads* — and the corpus bears it out exactly: `alien house.flp`
 * carries the genre `bass house?`, which is the same unvalidated free text that arrives in the
 * FLAC's `GENRE` tag and the same reason `Genre` has no fallback. So these are not competing
 * answers to the folder's questions; they are where the folder's answers came from.
 *
 * **What is deliberately not here**: the FL registration id, the author's data path, and every
 * sample's absolute path — see {@link EventId} for the three ids and why none of them is named.
 * A fact that must never be published should not be reachable from the object the entry writer
 * reads.
 */
final readonly class Project
{
    /** Days per the two floats in {@link EventId::Timestamp}, which Delphi counts from 1899-12-30. */
    private const int SECONDS_PER_DAY = 86400;

    /** The timestamp event's two doubles: created, then time spent. */
    private const int TIMESTAMP_SIZE = 16;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param string|null      $title       FL's project title; null when never filled in.
     * @param string|null      $genre       Free text, unresolved — `Genre::tryFrom()` is the caller's.
     * @param string|null      $artists     FL's author field.
     * @param float|null       $tempo       In BPM. Present in every project tested.
     * @param MusicalKey|null  $key         From the scale markers, and only when they agree.
     * @param KeyEstimate|null $keyEstimate What the notes suggest — derived, never read.
     * @param int|null         $timeSpent   Seconds spent on the project.
     * @param string|null      $version     The FL Studio version that last saved it.
     * @param int              $ppq
     * @param int              $channelCount
     * @param list<TimeMarker> $markers
     * @param list<string>     $mixerTracks
     * @param list<string>     $patterns
     * @param list<string>     $plugins
     */
    private function __construct(
        public ?string $title,
        public ?string $genre,
        public ?string $artists,
        public ?float $tempo,
        public ?MusicalKey $key,
        public ?KeyEstimate $keyEstimate,
        public ?int $timeSpent,
        public ?string $version,
        public int $ppq,
        public int $channelCount,
        public array $markers,
        public array $mixerTracks,
        public array $patterns,
        public array $plugins,
    ) {}

    /**
     * Reads a project file.
     *
     * @param string $path
     * @return self
     * @throws FlpException if the file cannot be read or does not parse.
     */
    public static function open(string $path): self
    {
        return self::of(FlpFile::open($path));
    }

    /**
     * Reads a parsed project.
     *
     * @param FlpFile $flp
     * @return self
     */
    public static function of(FlpFile $flp): self
    {
        $markers = self::markers($flp);
        $tempo   = $flp->first(EventId::Tempo)?->number();

        return new self(
            title:        self::text($flp, EventId::Title),
            genre:        self::text($flp, EventId::Genre),
            artists:      self::text($flp, EventId::Artists),
            tempo:        $tempo !== null ? $tempo / 1000 : null,
            key:          self::agreedKey($markers),
            keyEstimate:  KeyEstimate::of($flp),
            timeSpent:    self::timeSpent($flp),
            version:      $flp->first(EventId::Version)?->text(ascii: true),
            ppq:          $flp->ppq,
            channelCount: $flp->channelCount,
            markers:      $markers,
            mixerTracks:  self::names($flp, EventId::InsertName),
            patterns:     self::names($flp, EventId::PatternName),
            plugins:      Plugins::of($flp),
        );
    }

    /**
     * The markers of one type, in the order the project holds them.
     *
     * @param MarkerType $type
     * @return list<TimeMarker>
     */
    public function markersOf(MarkerType $type): array
    {
        return array_values(array_filter($this->markers, static fn(TimeMarker $m): bool => $m->type === $type));
    }

    /**
     * The arrangement — `INTRO`, `BUILDUP`, `DROP` — in playing order.
     *
     * Deduplicated on tick, because FL rewrites the whole marker list on each arrangement and a
     * project with two arrangements carries both.
     *
     * @return list<TimeMarker>
     */
    public function structure(): array
    {
        $byTick = [];

        foreach ($this->markersOf(MarkerType::Structure) as $marker) {
            $byTick[$marker->tick] ??= $marker;
        }

        ksort($byTick);

        return array_values($byTick);
    }

    /**
     * The time signature as FL writes it — `4/4` — or null where the project sets no marker.
     *
     * @return string|null
     */
    public function timeSignature(): ?string
    {
        return $this->markersOf(MarkerType::TimeSignature)[0]->name ?? null;
    }

    /**
     * Whether the piano roll's key lock is set anywhere in this project.
     *
     * @return bool
     */
    public function hasKeyLock(): bool
    {
        return $this->markersOf(MarkerType::Scale) !== [];
    }

    /**
     * Assembles the markers, which arrive as three separate events.
     *
     * A position opens a marker, a root note may follow, and the name closes it. The root is only
     * meaningful on a scale marker — FL writes a zero there for the other two kinds.
     *
     * @param FlpFile $flp
     * @return list<TimeMarker>
     */
    private static function markers(FlpFile $flp): array
    {
        $markers  = [];
        $position = null;
        $root     = 0;

        foreach ($flp->events as $event) {
            if ($event->is(EventId::Marker)) {
                $position = $event->number();
                $root     = 0;
                continue;
            }

            if ($event->is(EventId::MarkerRoot)) {
                $root = $event->number();
                continue;
            }

            if (!$event->is(EventId::MarkerName) || $position === null) {
                continue;
            }

            $type = MarkerType::of($position);

            if ($type !== null) {
                $markers[] = new TimeMarker($type, MarkerType::tickOf($position), $event->text(), $root);
            }

            $position = null;
        }

        return $markers;
    }

    /**
     * The key the scale markers name, and only where every one of them names the same.
     *
     * A project that locks two different keys has not told us its key, it has told us it changes —
     * so this hands back null and {@link \NeuroSYS\Tool\Release\Preflight} says which ones it saw.
     * The corpus has nothing but unanimous projects; `ill` carries the same D# Minor 51 times.
     *
     * @param list<TimeMarker> $markers
     * @return MusicalKey|null
     */
    private static function agreedKey(array $markers): ?MusicalKey
    {
        $keys = [];

        foreach ($markers as $marker) {
            if ($marker->type !== MarkerType::Scale) {
                continue;
            }

            $key = ScaleNotation::parse($marker->name);

            if ($key !== null) {
                $keys[$key->value] = $key;
            }
        }

        return count($keys) === 1 ? reset($keys) : null;
    }

    /**
     * Seconds spent on the project, from the second of the timestamp event's two doubles.
     *
     * The first double is the creation date, and it is **not** read: four of the seven projects
     * tested share one, because saving a copy carries the original's date with it. A date that is
     * really the ancestor's is worse than no date.
     *
     * @param FlpFile $flp
     * @return int|null
     */
    private static function timeSpent(FlpFile $flp): ?int
    {
        $value = $flp->first(EventId::Timestamp)?->value;

        if (!is_string($value) || strlen($value) < self::TIMESTAMP_SIZE) {
            return null;
        }

        $days = unpack('d', substr($value, 8, 8))[1];

        return $days > 0 ? (int) round($days * self::SECONDS_PER_DAY) : null;
    }

    /**
     * A text event's value, or null where it is absent or empty.
     *
     * FL writes the field whether or not it was filled in, so an untouched title arrives as a
     * lone NUL rather than as no event at all — which is a difference the caller does not want.
     *
     * @param FlpFile $flp
     * @param EventId $id
     * @return string|null
     */
    private static function text(FlpFile $flp, EventId $id): ?string
    {
        $text = $flp->first($id)?->text();

        return $text === null || trim($text) === '' ? null : $text;
    }

    /**
     * Every non-empty name under an id, deduplicated and in file order.
     *
     * @param FlpFile $flp
     * @param EventId $id
     * @return list<string>
     */
    private static function names(FlpFile $flp, EventId $id): array
    {
        $names = [];

        foreach ($flp->all($id) as $event) {
            $name = trim($event->text());

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }
}
