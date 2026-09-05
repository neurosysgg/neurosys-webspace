<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Tool\Flp\EventId;
use NeuroSYS\Tool\Flp\EventWidth;
use NeuroSYS\Tool\Flp\FlpException;
use NeuroSYS\Tool\Flp\FlpFile;
use NeuroSYS\Tool\Flp\MarkerType;
use NeuroSYS\Tool\Flp\Project;
use NeuroSYS\Tool\Flp\ScaleNotation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `tools/lib/Flp/` — the reader that turns a FL Studio project into the facts a release is made of.
 *
 * **Every project here is assembled byte by byte**, which is the one thing that makes this half
 * testable at all. The folder reader next door shells out to `metaflac` and `ffprobe` and so is
 * exercised by running the tool; a `.flp` is just bytes, so a fixture can be written in a line and
 * every branch reached — including the ones a real project would need a specific FL Studio version
 * to produce. It also keeps a repository whose largest real project is 13MB free of binary
 * fixtures.
 *
 * What is pinned here is chosen for how it fails rather than how likely it is. A `.flp` has no
 * separators between events and no checksums, so a reader that mis-sizes one event id reads
 * everything after it at the wrong offset — and, because a UTF-16 run of ASCII is `char, 00, char,
 * 00, …`, a parser one byte out lands on the zero high bytes and re-synchronises on its own a few
 * hundred bytes later. The file then parses, ends exactly where it said it would, and is simply
 * missing whatever sat in the desynchronised stretch. That is a project with no tempo and no error,
 * which is why {@link FlpFile} asserts it consumed the declared length and why the first test here
 * is about a single byte.
 */
final class FlpTest extends TestCase
{
    /** The preamble FL 26 writes, whose event 172 is one byte wide inside the four-byte band. */
    private const int NARROW_DWORD = 172;

    /**
     * A project file, assembled from raw event bytes.
     *
     * @param string $events
     * @param int    $ppq
     * @param int    $channels
     * @return string
     */
    private function flp(string $events, int $ppq = 96, int $channels = 26): string
    {
        return 'FLhd' . pack('V', 6) . pack('vvv', 0, $channels, $ppq)
            . 'FLdt' . pack('V', strlen($events)) . $events;
    }

    /**
     * One event, at a width this test states rather than asks for.
     *
     * Deriving the width from {@link EventWidth} would make the fixture agree with the reader by
     * construction, which is exactly the agreement these tests exist to check.
     *
     * @param int $id
     * @param int $value
     * @return string
     */
    private function byte(int $id, int $value): string
    {
        return chr($id) . chr($value);
    }

    /**
     * @param int $id
     * @param int $value
     * @return string
     */
    private function word(int $id, int $value): string
    {
        return chr($id) . pack('v', $value);
    }

    /**
     * @param int $id
     * @param int $value
     * @return string
     */
    private function dword(int $id, int $value): string
    {
        return chr($id) . pack('V', $value);
    }

    /**
     * A variable-width event, with the seven-bits-at-a-time length FL prefixes them with.
     *
     * @param int    $id
     * @param string $payload
     * @return string
     */
    private function data(int $id, string $payload): string
    {
        $length = strlen($payload);
        $varInt = '';

        do {
            $septet  = $length & 0x7F;
            $length >>= 7;
            $varInt .= chr($length > 0 ? $septet | 0x80 : $septet);
        } while ($length > 0);

        return chr($id) . $varInt . $payload;
    }

    /**
     * Text as FL writes it: UTF-16LE, NUL-terminated.
     *
     * @param int    $id
     * @param string $text
     * @return string
     */
    private function text(int $id, string $text): string
    {
        return $this->data($id, mb_convert_encoding($text, 'UTF-16LE', 'UTF-8') . "\0\0");
    }

    /**
     * A marker, which FL writes as the three separate events this reassembles.
     *
     * @param MarkerType $type
     * @param int        $tick
     * @param string     $name
     * @param int        $root
     * @return string
     */
    private function marker(MarkerType $type, int $tick, string $name, int $root = 0): string
    {
        return $this->dword(EventId::Marker->value, ($type->value << 24) | $tick)
            . $this->byte(EventId::MarkerRoot->value, $root)
            . $this->text(EventId::MarkerName->value, $name);
    }

    /**
     * The single byte that decides whether a project has a tempo.
     *
     * FL 26 writes event 172 one byte wide even though it sits in the band where every other id is
     * four. Read by the band alone it eats the id byte of the event after it — in a real project
     * the `FL Studio 26.1.0.5530` string — and everything downstream, the tempo included, is read
     * at the wrong offset.
     *
     * The fixture is the real shape: the preamble, then a text event, then the tempo. Finding 140
     * on the other side is the whole assertion.
     *
     * @return void
     */
    public function testTheFl26PreambleDoesNotSwallowTheEventAfterIt(): void
    {
        $flp = FlpFile::read($this->flp(
            $this->byte(self::NARROW_DWORD, 1)
            . $this->text(EventId::Title->value, 'FL Studio 26.1.0.5530.5530')
            . $this->dword(EventId::Tempo->value, 140000),
        ));

        $this->assertSame(EventWidth::Byte, EventWidth::of(self::NARROW_DWORD));
        $this->assertSame(140000, $flp->first(EventId::Tempo)?->number());
    }

    /**
     * The band rule, which is the only thing that says where one event ends and the next begins.
     *
     * @return void
     */
    public function testAnEventIdNamesItsOwnWidth(): void
    {
        $this->assertSame(EventWidth::Byte, EventWidth::of(0));
        $this->assertSame(EventWidth::Byte, EventWidth::of(63));
        $this->assertSame(EventWidth::Word, EventWidth::of(64));
        $this->assertSame(EventWidth::Word, EventWidth::of(127));
        $this->assertSame(EventWidth::Dword, EventWidth::of(128));
        $this->assertSame(EventWidth::Dword, EventWidth::of(191));
        $this->assertSame(EventWidth::Variable, EventWidth::of(192));
        $this->assertSame(EventWidth::Variable, EventWidth::of(255));

        $this->assertSame(1, EventWidth::Byte->size());
        $this->assertSame(2, EventWidth::Word->size());
        $this->assertSame(4, EventWidth::Dword->size());
        $this->assertNull(EventWidth::Variable->size());
    }

    /**
     * An event that wants more bytes than `FLdt` has left is refused where it is read.
     *
     * This is the bounds check doing the work an end-of-walk assertion cannot: by the time the walk
     * finishes the cursor is always exactly on the end, because it can never step past it. An
     * earlier draft asserted the landing instead and the assertion was unreachable — and, worse,
     * would not have caught the desync it was written for, which lands on the boundary too. See
     * {@link FlpFile}.
     *
     * @return void
     */
    public function testAnEventThatOverrunsTheDataChunkIsRefused(): void
    {
        $events = $this->dword(EventId::Tempo->value, 140000);
        $short  = 'FLhd' . pack('V', 6) . pack('vvv', 0, 26, 96)
            . 'FLdt' . pack('V', strlen($events) - 1) . $events;

        $this->expectException(FlpException::class);
        $this->expectExceptionMessageMatches('/wants 4 bytes with 3 left/');

        FlpFile::read($short);
    }

    /**
     * A variable-width event whose length prefix runs past the end of the chunk.
     *
     * @return void
     */
    public function testALengthPrefixThatRunsPastTheChunkIsRefused(): void
    {
        // A varint whose continuation bit promises another byte that the chunk does not have.
        $this->expectException(FlpException::class);
        $this->expectExceptionMessageMatches('/runs past FLdt/');

        FlpFile::read($this->flp(chr(EventId::Title->value) . chr(0x80)));
    }

    /**
     * @return void
     */
    public function testAFileThatIsNotAProjectIsRefused(): void
    {
        $this->expectException(FlpException::class);
        $this->expectExceptionMessageMatches('/no FLhd/');

        FlpFile::read('not a project at all');
    }

    /**
     * @return void
     */
    public function testATruncatedProjectIsRefused(): void
    {
        $this->expectException(FlpException::class);
        $this->expectExceptionMessageMatches('/truncated/');

        FlpFile::read('FLhd' . pack('V', 6) . pack('vvv', 0, 26, 96) . 'FLdt' . pack('V', 4096) . 'xy');
    }

    /**
     * A ppq of zero would put every marker at second zero rather than failing.
     *
     * @return void
     */
    public function testAProjectWithNoPulseRateIsRefused(): void
    {
        $this->expectException(FlpException::class);
        $this->expectExceptionMessageMatches('/ppq/');

        FlpFile::read($this->flp($this->dword(EventId::Tempo->value, 140000), ppq: 0));
    }

    /**
     * The header, and a length long enough to need more than one byte to say.
     *
     * @return void
     */
    public function testTheHeaderAndALongTextEventAreRead(): void
    {
        $long = str_repeat('a', 300);
        $flp  = FlpFile::read($this->flp($this->text(EventId::Title->value, $long), ppq: 192, channels: 114));

        $this->assertSame(192, $flp->ppq);
        $this->assertSame(114, $flp->channelCount);
        $this->assertSame(0, $flp->format);
        $this->assertSame($long, $flp->first(EventId::Title)?->text());
    }

    /**
     * FL kept the version string ASCII when everything else went UTF-16.
     *
     * @return void
     */
    public function testTheVersionStringIsAsciiAndTheRestIsUtf16(): void
    {
        $flp = FlpFile::read($this->flp(
            $this->data(EventId::Version->value, "26.1.0.5530\0")
            . $this->text(EventId::Artists->value, 'neuro.SYS'),
        ));

        $this->assertSame('26.1.0.5530', $flp->first(EventId::Version)?->text(ascii: true));
        $this->assertSame('neuro.SYS', $flp->first(EventId::Artists)?->text());
    }

    /**
     * All three marker kinds arrive under one event id and are told apart by the top byte.
     *
     * @return void
     */
    public function testMarkersAreSortedByTheTypePackedIntoTheirPosition(): void
    {
        $project = Project::of(FlpFile::read($this->flp(
            $this->marker(MarkerType::Scale, 0, 'D# Minor Natural (Aeolian)', root: 3)
            . $this->marker(MarkerType::TimeSignature, 0, '4/4')
            . $this->marker(MarkerType::Structure, 0, 'INTRO')
            . $this->marker(MarkerType::Structure, 6144, 'DROP')
            . $this->dword(EventId::Tempo->value, 140000),
        )));

        $this->assertSame(['INTRO', 'DROP'], array_map(static fn($m): string => $m->name, $project->structure()));
        $this->assertSame('4/4', $project->timeSignature());
        $this->assertSame(MusicalKey::DSharpMinor, $project->key);
        $this->assertTrue($project->hasKeyLock());
        $this->assertSame(3, $project->markersOf(MarkerType::Scale)[0]->root);
    }

    /**
     * A marker's tick becomes a time only once the tempo and the pulse rate are known.
     *
     * 6144 ticks at 96 ppq is 64 beats, which at 140 BPM is 27.43 seconds — the drop on `ill`.
     *
     * @return void
     */
    public function testAMarkerKnowsWhereItFallsInSeconds(): void
    {
        $project = Project::of(FlpFile::read($this->flp(
            $this->marker(MarkerType::Structure, 6144, 'DROP')
            . $this->dword(EventId::Tempo->value, 140000),
        )));

        $this->assertEqualsWithDelta(27.43, $project->structure()[0]->seconds(140.0, 96), 0.01);
        $this->assertSame(0.0, $project->structure()[0]->seconds(0.0, 96));
    }

    /**
     * A project that locks two different keys has not told us its key.
     *
     * @return void
     */
    public function testDisagreeingScaleMarkersResolveToNoKeyAtAll(): void
    {
        $project = Project::of(FlpFile::read($this->flp(
            $this->marker(MarkerType::Scale, 0, 'D# Minor Natural (Aeolian)', root: 3)
            . $this->marker(MarkerType::Scale, 6144, 'F# Major (Ionian)', root: 6),
        )));

        $this->assertNull($project->key);
        $this->assertTrue($project->hasKeyLock());
    }

    /**
     * Every key, in the spelling FL prints on a scale marker.
     *
     * @return void
     */
    public function testEveryMusicalKeyResolvesFromItsScaleMarkerSpelling(): void
    {
        foreach (MusicalKey::cases() as $case) {
            $mode    = str_ends_with($case->value, 'Minor') ? 'Minor Natural (Aeolian)' : 'Major (Ionian)';
            $written = str_replace([' Major', ' Minor'], '', $case->value) . ' ' . $mode;

            $this->assertSame($case, ScaleNotation::parse($written), sprintf('%s should resolve', $written));
        }
    }

    /**
     * @return array<string, array{string, MusicalKey|null}>
     */
    public static function scaleProvider(): array
    {
        return [
            'flats fold to the sharp MusicalKey spells' => ['Eb Minor Natural (Aeolian)', MusicalKey::DSharpMinor],
            'a bare quality with no mode'               => ['F# Major', MusicalKey::FSharpMajor],
            'lower case'                                => ['d# minor natural (aeolian)', MusicalKey::DSharpMinor],
            'a mode with no major or minor to fold to'  => ['D# Dorian (Dorian)', null],
            'a time signature is not a key'             => ['4/4', null],
            'a structure marker is not a key'           => ['DROP', null],
            'nothing at all'                            => ['', null],
        ];
    }

    /**
     * @param string          $written
     * @param MusicalKey|null $expected
     * @return void
     */
    #[DataProvider('scaleProvider')]
    public function testScaleMarkersResolveOrAreRefused(string $written, ?MusicalKey $expected): void
    {
        $this->assertSame($expected, ScaleNotation::parse($written));
    }

    /**
     * The facts the project info carries, and the one it carries that is not read.
     *
     * FL writes the title field whether or not it was filled in, so an untouched one arrives as a
     * lone NUL — which has to read as absent, not as an empty title.
     *
     * @return void
     */
    public function testProjectInfoIsReadAndAnUntouchedFieldIsAbsent(): void
    {
        $project = Project::of(FlpFile::read($this->flp(
            $this->text(EventId::Title->value, 'hello world!')
            . $this->text(EventId::Genre->value, 'bass house?')
            . $this->text(EventId::Artists->value, '')
            . $this->dword(EventId::Tempo->value, 140000)
            . $this->text(EventId::InsertName->value, 'KICK')
            . $this->text(EventId::PatternName->value, 'DRUMS MIDI'),
        )));

        $this->assertSame('hello world!', $project->title);
        // Left unresolved on purpose: Genre::tryFrom() refusing this is the point of the fallback.
        $this->assertSame('bass house?', $project->genre);
        $this->assertNull($project->artists);
        $this->assertSame(140.0, $project->tempo);
        $this->assertSame(['KICK'], $project->mixerTracks);
        $this->assertSame(['DRUMS MIDI'], $project->patterns);
    }

    /**
     * Time spent is the second of the timestamp's two doubles; the first is deliberately not read.
     *
     * @return void
     */
    public function testTimeSpentIsReadAndTheCreationDateIsNot(): void
    {
        $project = Project::of(FlpFile::read($this->flp($this->data(
            EventId::Timestamp->value,
            pack('d', 46213.9) . pack('d', 2.5061), // created 2026-07-10, then 60h 08m
        ))));

        $this->assertSame(216527, $project->timeSpent);
        $this->assertObjectNotHasProperty('created', $project);
    }

    /**
     * A project with no notes has no estimate, which is not the same as a weak one.
     *
     * @return void
     */
    public function testAProjectWithNoNotesHasNoKeyEstimate(): void
    {
        $this->assertNull(Project::of(FlpFile::read($this->flp(
            $this->dword(EventId::Tempo->value, 140000),
        )))->keyEstimate);
    }

    /**
     * The last rung of the key chain, for the projects — `hello world!` among them — with no lock.
     *
     * @return void
     */
    public function testTheKeyIsEstimatedFromTheNotesWhenNothingLocksIt(): void
    {
        $project = Project::of(FlpFile::read($this->flp(
            $this->data(EventId::PatternNotes->value, $this->notes([0, 4, 7, 0, 4, 7, 2, 5, 9, 11]))
            . $this->dword(EventId::Tempo->value, 140000),
        )));

        $this->assertNull($project->key, 'nothing locked the key');
        $this->assertSame(MusicalKey::CMajor, $project->keyEstimate?->key);
        $this->assertTrue($project->keyEstimate?->isConfident());
        $this->assertSame(10, $project->keyEstimate?->notes);
    }

    /**
     * Notes above the MIDI range are FL's own markers rather than pitches.
     *
     * @return void
     */
    public function testNotesOutsideTheMidiRangeAreNotCountedAsPitches(): void
    {
        $project = Project::of(FlpFile::read($this->flp(
            $this->data(EventId::PatternNotes->value, $this->notes([0, 4, 7, 200])),
        )));

        $this->assertSame(3, $project->keyEstimate?->notes);
    }

    /**
     * A pattern note is 24 bytes, with its length at offset 8 and its key at offset 12.
     *
     * @param list<int> $keys
     * @return string
     */
    private function notes(array $keys): string
    {
        $notes = '';

        foreach ($keys as $key) {
            $notes .= pack('V', 0) . pack('vv', 0, 0) . pack('V', 96) . chr($key) . str_repeat("\0", 11);
        }

        return $notes;
    }
}
