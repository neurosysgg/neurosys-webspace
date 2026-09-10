<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Support\Collection;
use NeuroSYS\Tool\Flp\EventId;
use NeuroSYS\Tool\Flp\FlpFile;
use NeuroSYS\Tool\Flp\Note;
use NeuroSYS\Tool\Flp\Pattern;
use NeuroSYS\Tool\Flp\PlaylistClip;
use NeuroSYS\Tool\Flp\Score;
use NeuroSYS\Tool\Midi\MidiException;
use NeuroSYS\Tool\Midi\MidiFile;
use NeuroSYS\Tool\Midi\MidiNote;
use NeuroSYS\Tool\Midi\MidiTrack;
use NeuroSYS\Tool\Midi\TimeSignature;
use NeuroSYS\Tool\Midi\VariableLength;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `tools/lib/Flp/` reading notes, and `tools/lib/Midi/` writing them out.
 *
 * **Every project here is assembled byte by byte**, for the reason {@link FlpTest} gives: a `.flp`
 * is just bytes, so a fixture can be written in a line and every branch reached without a
 * multi-megabyte binary entering the repository. The corpus these classes were actually built
 * against is 13MB of real projects and stays outside it.
 *
 * What is pinned here is chosen for **how it fails**, which for this pair of namespaces is almost
 * always in silence:
 *
 * - A playlist clip's width changes between FL Studio versions and the file does not say which.
 *   Read at the wrong width the arrangement is not an error, it is the wrong music. Both known
 *   widths are pinned, and so is the refusal when neither holds.
 * - A note of length zero is normal in a `.flp` and inaudible in MIDI. 1,398 notes of one real
 *   project are one-shots; written literally the whole drum kit disappears with nothing to see.
 * - A track name is read under a cursor rather than an id, so an off-by-one names every instrument
 *   after the wrong one — a file that opens perfectly and is labelled wrongly throughout.
 *
 * The round trip at the end is the one test that covers the writer end to end: rendering is a lot
 * of byte arithmetic whose mistakes produce a file no player will open, and the cheapest proof that
 * it did not is to read the bytes back.
 */
final class MidiTest extends TestCase
{
    /** The two clip widths FL Studio has been observed to write — FL 12.4 and FL 25/26. */
    private const int NARROW_CLIP = 32;
    private const int WIDE_CLIP = 80;

    /**
     * A project file, assembled from raw event bytes.
     *
     * @param string $events
     * @param int    $ppq
     * @param int    $channels
     * @return string
     */
    private function flp(string $events, int $ppq = 96, int $channels = 8): string
    {
        return 'FLhd' . pack('V', 6) . pack('vvv', 0, $channels, $ppq)
            . 'FLdt' . pack('V', strlen($events)) . $events;
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
     * A word event — what opens a channel or a pattern block.
     *
     * @param int $id
     * @param int $value
     * @return string
     */
    private function word(int $id, int $value): string
    {
        return chr($id) . pack('v', $value);
    }

    /**
     * One note, at the offsets stated here rather than asked of {@link Note}.
     *
     * Spelling the layout out a second time is the point: deriving it from the reader would make
     * the fixture agree with it by construction, which is the agreement this file exists to check.
     *
     * @param int  $position
     * @param int  $key
     * @param int  $length
     * @param int  $channel
     * @param int  $velocity
     * @param bool $slide
     * @return string
     */
    private function note(
        int $position,
        int $key,
        int $length = 96,
        int $channel = 0,
        int $velocity = 100,
        bool $slide = false,
    ): string {
        return pack('V', $position)          // 0  position
            . pack('v', 0x4000)              // 4  flags
            . pack('v', $channel)            // 6  rack channel
            . pack('V', $length)             // 8  length
            . chr($key) . chr(0)             // 12 key
            . pack('v', 0)                   // 14 group
            . chr(120) . chr(0)              // 16 fine pitch
            . chr(64)                        // 18 release
            . chr($slide ? 0x80 : 0)         // 19 slide
            . chr(64)                        // 20 pan
            . chr($velocity)                 // 21 velocity
            . chr(128) . chr(128);           // 22 mod x, mod y
    }

    /**
     * One playlist clip, padded out to the width being tested.
     *
     * @param int $position
     * @param int $length
     * @param int $index       At or above {@link PlaylistClip::PATTERN_BASE} for a pattern.
     * @param int $startOffset Minus one for an untrimmed clip, which is what FL writes.
     * @param int $stride
     * @return string
     */
    private function clip(
        int $position,
        int $length,
        int $index,
        int $startOffset = -1,
        int $stride = self::WIDE_CLIP,
    ): string {
        $clip = pack('V', $position)
            . pack('v', PlaylistClip::PATTERN_BASE)
            . pack('v', $index)
            . pack('V', $length)
            . pack('V', 500)
            . str_repeat("\0", 8)
            . pack('l', $startOffset)
            . pack('l', -1);

        return str_pad($clip, $stride, "\0");
    }

    /**
     * A pattern block: the opener, its name, and its notes.
     *
     * @param int    $index
     * @param string $name
     * @param string $notes
     * @return string
     */
    private function pattern(int $index, string $name, string $notes): string
    {
        return $this->word(EventId::PatternIndex->value, $index)
            . $this->text(EventId::PatternName->value, $name)
            . $this->data(EventId::PatternNotes->value, $notes);
    }

    /**
     * A channel block: the opener and the name that follows it.
     *
     * @param int    $index
     * @param string $name
     * @return string
     */
    private function channel(int $index, string $name): string
    {
        return $this->word(EventId::ChannelIndex->value, $index)
            . $this->text(EventId::ChannelName->value, $name);
    }

    /**
     * A score from events.
     *
     * @param string $events
     * @param int    $ppq
     * @return Score
     */
    private function score(string $events, int $ppq = 96): Score
    {
        return Score::of(FlpFile::read($this->flp($events, $ppq)));
    }

    /**
     * Every field of the note struct, at the offsets a real project writes them.
     *
     * The layout was recovered by surveying byte columns across a real project rather than read out
     * of a specification, so this is the test that says what was concluded. Get one offset wrong
     * and the notes still decode — as the wrong pitch, on the wrong channel, at the wrong time.
     *
     * @return void
     */
    public function testANoteIsReadFieldForField(): void
    {
        $notes = Note::decode(
            $this->note(position: 384, key: 63, length: 192, channel: 5, velocity: 117, slide: true)
            . $this->note(position: 0, key: 27, length: 0, channel: 2),
        );

        $this->assertCount(2, $notes);

        $this->assertSame(384, $notes[0]->position);
        $this->assertSame(63, $notes[0]->key);
        $this->assertSame(192, $notes[0]->length);
        $this->assertSame(5, $notes[0]->channel);
        $this->assertSame(117, $notes[0]->velocity);
        $this->assertTrue($notes[0]->slide);
        $this->assertSame(64, $notes[0]->pan);
        $this->assertSame(64, $notes[0]->release);
        $this->assertSame(120, $notes[0]->finePitch);

        $this->assertFalse($notes[1]->slide, 'the slide bit is the high bit of the MIDI-channel byte');
        $this->assertSame(0, $notes[1]->length, 'a one-shot really does carry no length');
    }

    /**
     * A block that is not a whole number of notes is refused rather than read to the last whole one.
     *
     * Same reasoning as the estimator's: 24 bytes is the only thing separating one note from the
     * next, so a block that is not a multiple of it is one this reader has misunderstood. Reading
     * 41 notes out of 41 and a half would turn a misread into data.
     *
     * @return void
     */
    public function testARaggedNoteBlockIsNotGuessedAt(): void
    {
        $this->assertSame([], Note::decode(str_repeat("\0", 30)));
        $this->assertSame([], Note::decode(''));
        $this->assertCount(1, Note::decode($this->note(0, 60)));
    }

    /**
     * FL's key range is wider than MIDI's, and the two ends of that fail differently.
     *
     * Above 131 the byte is not a pitch at all — FL parks markers up there — so it is dropped at
     * the door. Between 128 and 131 it is a real note that MIDI has no room for, which is a fact
     * about the file being written rather than about the project, so it survives decoding and
     * answers {@link Note::isPlayable()} instead.
     *
     * @return void
     */
    public function testAKeyOutsideMidisRangeIsKeptOrDroppedDependingOnWhichRangeItLeft(): void
    {
        $notes = Note::decode(
            $this->note(0, 60) . $this->note(0, 130) . $this->note(0, 200),
        );

        $this->assertCount(2, $notes, 'above 131 is not a pitch and never reaches a caller');
        $this->assertTrue($notes[0]->isPlayable());
        $this->assertFalse($notes[1]->isPlayable(), '130 is a real note MIDI cannot hold');
    }

    /**
     * The clip width is probed, and both widths FL Studio has written are found.
     *
     * This is the highest-risk read in the whole reader. Nothing in the file states the width, it
     * changed from 32 to 80 bytes between FL 12 and FL 25, and a wrong width does not fail — it
     * produces an arrangement of plausible-looking nonsense. The probe leans on the one thing that
     * held across every project tested: a clip's `u16` at offset 4 is always 20480.
     *
     * @param int $stride
     * @return void
     */
    #[DataProvider('clipWidths')]
    public function testAClipWidthIsProbedRatherThanAssumed(int $stride): void
    {
        $score = $this->score(
            $this->pattern(1, 'CHORDS', $this->note(0, 60) . $this->note(96, 64))
            . $this->data(EventId::Playlist->value, $this->clip(0, 384, 20481, stride: $stride)
                . $this->clip(384, 384, 20481, stride: $stride)),
        );

        $this->assertSame($stride, $score->playlist?->stride);
        $this->assertCount(2, $score->playlist?->clips ?? []);
        $this->assertSame([0 => 4], array_map(count(...), $score->arrangement()));
    }

    /**
     * @return list<array{int}>
     */
    public static function clipWidths(): array
    {
        return [[self::NARROW_CLIP], [self::WIDE_CLIP]];
    }

    /**
     * A playlist no width explains is refused, rather than read at whichever one divides.
     *
     * Answering null is the same choice `Request::path()` makes for a target it cannot read: a
     * caller that gets no arrangement can say so, where one handed a half-read arrangement cannot
     * tell it apart from a real one.
     *
     * @return void
     */
    public function testAPlaylistThatMatchesNoWidthIsNotRead(): void
    {
        $score = $this->score(
            $this->pattern(1, 'CHORDS', $this->note(0, 60))
            . $this->data(EventId::Playlist->value, str_repeat("\x01", 96)),
        );

        $this->assertNull($score->playlist, '96 bytes divides by 32 and by 48, and holds no canary');
        $this->assertSame([], $score->arrangement());
    }

    /**
     * A pattern placed twice plays twice, which is what makes an arrangement bigger than its notes.
     *
     * @return void
     */
    public function testAPatternPlaysWhereverThePlaylistPutsIt(): void
    {
        $score = $this->score(
            $this->channel(0, 'BASS')
            . $this->pattern(1, 'RIFF', $this->note(0, 36) . $this->note(96, 38))
            . $this->data(EventId::Playlist->value, $this->clip(0, 192, 20481)
                . $this->clip(768, 192, 20481)),
        );

        $ticks = array_map(static fn($note): int => $note->tick, $score->arrangement()[0]);

        $this->assertSame([0, 96, 768, 864], $ticks);
    }

    /**
     * A clip trims: a note outside its length does not play, and an offset moves the window.
     *
     * Checked rather than assumed, and the alternative reading was checked too — placing the
     * pattern repeatedly until the clip is filled gives byte-identical output on the corpus,
     * because FL sizes a pattern clip to its content.
     *
     * @return void
     */
    public function testAClipTrimsTheNotesThatFallOutsideIt(): void
    {
        $notes = $this->note(0, 36) . $this->note(96, 38) . $this->note(192, 40) . $this->note(288, 41);

        $score = $this->score(
            $this->channel(0, 'BASS')
            . $this->pattern(1, 'RIFF', $notes)
            . $this->data(EventId::Playlist->value, $this->clip(0, 192, 20481)
                . $this->clip(1000, 192, 20481, startOffset: 192)),
        );

        $placed = $score->arrangement()[0];

        $this->assertSame([0, 96, 1000, 1096], array_map(static fn($n): int => $n->tick, $placed));
        $this->assertSame([36, 38, 40, 41], array_map(static fn($n): int => $n->note->key, $placed));
    }

    /**
     * A one-shot lasts until its channel next speaks, and to the clip's end when it is the last.
     *
     * **The single most consequential rule in this namespace.** 1,398 notes of the project this was
     * built against carry no length at all — every hi-hat and every foley hit — because FL plays a
     * sample for as long as the sample lasts. MIDI has no note of no length: written literally,
     * every one of them is silent, and nothing about the file looks wrong. The rule was recovered
     * by diffing against FL's own export and agreed on all 1,398, the five last-note cases included.
     *
     * @return void
     */
    public function testAOneShotIsGivenTheDurationItSounds(): void
    {
        $score = $this->score(
            $this->channel(0, 'HAT')
            . $this->pattern(1, 'HATS', $this->note(0, 60, length: 0)
                . $this->note(48, 60, length: 0)
                . $this->note(96, 60, length: 0))
            . $this->data(EventId::Playlist->value, $this->clip(0, 192, 20481)),
        );

        $lengths = array_map(static fn($note): int => $note->length, $score->arrangement()[0]);

        $this->assertSame([48, 48, 96], $lengths, 'to the next note, then to the end of the clip');
    }

    /**
     * A note that already has a length keeps it, clip boundary or not.
     *
     * This is the divergence from FL's export that was taken deliberately — see
     * `ExtractMidi::DIVERGENCE`. FL shortens some notes ending exactly on a boundary by one tick
     * and leaves others, so the project's own length is written instead of a rule that is wrong
     * nine times in fifty-one.
     *
     * @return void
     */
    public function testANoteEndingOnTheClipBoundaryKeepsItsLength(): void
    {
        $score = $this->score(
            $this->channel(0, 'PAD')
            . $this->pattern(1, 'PAD', $this->note(0, 60, length: 192))
            . $this->data(EventId::Playlist->value, $this->clip(0, 192, 20481)),
        );

        $this->assertSame(192, $score->arrangement()[0][0]->length);
    }

    /**
     * A channel takes the first name after its opener, and no later one.
     *
     * FL writes the same event id for every mixer effect too, further down the file, where the
     * cursor is still pointing at the last channel opened. Taking any name but the first would let
     * an insert's plugin rename an instrument — a file that opens perfectly and is labelled wrong.
     *
     * @return void
     */
    public function testAChannelIsNamedByTheFirstNameAfterItAndNotByAMixerPlugin(): void
    {
        $score = $this->score(
            $this->channel(0, 'KICK')
            . $this->text(EventId::ChannelName->value, 'Fruity Limiter')
            . $this->channel(1, 'SNARE')
            . $this->text(EventId::ChannelName->value, 'Fruity Reeverb 2'),
        );

        $this->assertSame('KICK', $score->channels->find('0')?->name);
        $this->assertSame('SNARE', $score->channels->find('1')?->name);
        $this->assertCount(2, $score->channels);
    }

    /**
     * A pattern without a name still has something to call it.
     *
     * @return void
     */
    public function testAnUnnamedPatternFallsBackToItsIndex(): void
    {
        $score = $this->score(
            $this->word(EventId::PatternIndex->value, 7)
            . $this->data(EventId::PatternNotes->value, $this->note(0, 60)),
        );

        $this->assertSame('Pattern 7', $score->patterns->find('7')?->label());
    }

    /**
     * The patterns mode reads the writing rather than the performance.
     *
     * Every pattern once, at the ticks the pattern itself holds, whether or not the playlist ever
     * places it — which is why this fixture has no playlist at all and still produces notes.
     *
     * @return void
     */
    public function testPatternsAreReadableWithoutAPlaylist(): void
    {
        $score   = $this->score($this->pattern(3, 'ARP', $this->note(0, 60) . $this->note(48, 64, length: 0)));
        $pattern = $score->patterns->find('3');

        $this->assertNotNull($pattern);
        $this->assertSame([], $score->arrangement(), 'no playlist means no arrangement');

        $written = $score->written($pattern);

        $this->assertSame([0, 48], array_map(static fn($n): int => $n->tick, $written));
        $this->assertSame([96, 48], array_map(static fn($n): int => $n->length, $written));
    }

    /**
     * The variable-length quantity, at the boundaries where its byte count changes.
     *
     * @param int    $value
     * @param string $expected
     * @return void
     */
    #[DataProvider('quantities')]
    public function testAVariableLengthQuantityIsEncodedAtEveryBoundary(int $value, string $expected): void
    {
        $this->assertSame($expected, bin2hex(VariableLength::encode($value)));
    }

    /**
     * @return list<array{int, string}>
     */
    public static function quantities(): array
    {
        return [
            [0, '00'],
            [127, '7f'],
            [128, '8100'],
            [8192, 'c000'],
            [16383, 'ff7f'],
            [16384, '818000'],
            [VariableLength::MAXIMUM, 'ffffff7f'],
        ];
    }

    /**
     * A delta the format cannot state throws rather than silently losing its high bits.
     *
     * Truncating would put every event after it at the wrong time, which is a file that plays and
     * is wrong — the failure this whole layer is arranged to avoid.
     *
     * @return void
     */
    public function testADeltaTooLargeToStateIsRefused(): void
    {
        $this->expectException(MidiException::class);

        (void) VariableLength::encode(VariableLength::MAXIMUM + 1);
    }

    /**
     * A note MIDI cannot hold is refused at its constructor rather than clamped there.
     *
     * Clamping is a decision about a project and belongs to the caller making it, which is why
     * `ExtractMidi` does it and prints how many notes it affected.
     *
     * @param int $key
     * @param int $velocity
     * @param int $length
     * @return void
     */
    #[DataProvider('impossibleNotes')]
    public function testANoteOutsideWhatMidiHoldsIsRefused(int $key, int $velocity, int $length): void
    {
        $this->expectException(MidiException::class);

        (void) new MidiNote(0, $key, $velocity, $length);
    }

    /**
     * @return list<array{int, int, int}>
     */
    public static function impossibleNotes(): array
    {
        return [
            [128, 100, 96],
            [-1, 100, 96],
            [60, 128, 96],
            [60, 0, 96],
            [60, 100, 0],
        ];
    }

    /**
     * A time signature is a numerator and a power of two, and the second half is the trap.
     *
     * MIDI stores the exponent rather than the denominator, so a `4/4` written as two plain
     * integers at a call site is one conversion away from a bar of the wrong length.
     *
     * @return void
     */
    public function testATimeSignatureIsReadAndRenderedAsMidiStatesIt(): void
    {
        $this->assertSame('7/8', TimeSignature::parse('7/8')?->label());
        $this->assertSame('4/4', TimeSignature::common()->label());
        $this->assertNull(TimeSignature::parse('6/6'), '6 is not a power of two');
        $this->assertNull(TimeSignature::parse('common time'));

        // beats, log2(division), clocks per click, 32nds per quarter
        $this->assertSame('07031808', bin2hex(new TimeSignature(7, 8)->render()));
    }

    /**
     * A rendered file reads back as the notes it was given.
     *
     * The one test that covers the writer end to end. Rendering is chunk lengths, delta times and
     * status bytes, and every mistake in that arithmetic produces a file that no player will open
     * and no assertion about note counts would notice. Reading the bytes back is the cheap proof.
     *
     * @return void
     */
    public function testARenderedFileReadsBackAsWhatWentIntoIt(): void
    {
        $notes = new Collection(MidiNote::class)->with(
            new MidiNote(0, 60, 100, 96),
            new MidiNote(96, 64, 90, 48),
            new MidiNote(96, 67, 90, 48),
        );

        $midi = new MidiFile(
            division:      96,
            tracks:        new Collection(MidiTrack::class)->with(new MidiTrack('CHORDS', 3, $notes)),
            tempo:         140.0,
            timeSignature: new TimeSignature(3, 4),
        );

        $parsed = $this->parse($midi->render());

        $this->assertSame(1, $parsed['format']);
        $this->assertSame(96, $parsed['division']);
        $this->assertSame(2, $parsed['chunks'], 'the conductor track, then the one track');
        $this->assertSame('3/4', $parsed['signature']);

        // Not `assertSame(140.0)`: MIDI states a tempo as whole microseconds per quarter note, and
        // 60,000,000 / 140 is not one. It rounds to 428,571, which reads back as 140.0001 — the
        // same figure FL Studio's own export of a 140 BPM project reads back as. There is no
        // encoding of exactly 140 here to be got wrong.
        $this->assertEqualsWithDelta(140.0, $parsed['bpm'], 0.001);
        $this->assertSame(['0:60:100:96', '96:64:90:48', '96:67:90:48'], $parsed['CHORDS']);
    }

    /**
     * A note ending exactly as another begins writes its note-off first.
     *
     * Otherwise a player meeting the note-on first has the same key sounding twice, and the
     * note-off it then reads ends the wrong one — which is how a re-triggered hi-hat becomes one
     * long note. The round trip proves it, because the parser below pairs ons to offs in order.
     *
     * @return void
     */
    public function testANoteRetriggeredOnTheSameTickDoesNotSwallowItsNeighbour(): void
    {
        $notes = new Collection(MidiNote::class)->with(
            new MidiNote(0, 42, 100, 48),
            new MidiNote(48, 42, 100, 48),
            new MidiNote(96, 42, 100, 48),
        );

        $parsed = $this->parse(new MidiFile(96, new Collection(MidiTrack::class)->with(
            new MidiTrack('HAT', 0, $notes),
        ))->render());

        $this->assertSame(['0:42:100:48', '48:42:100:48', '96:42:100:48'], $parsed['HAT']);
    }

    /**
     * A file with no tempo leaves the field out rather than inventing 120.
     *
     * A project without a tempo is a bad read rather than a slow song — `FlpFile` names it as the
     * symptom of a desynchronised walk — so the command warns and the file stays silent on it.
     *
     * @return void
     */
    public function testAFileWithoutATempoStatesNone(): void
    {
        $parsed = $this->parse(new MidiFile(96, new Collection(MidiTrack::class)->with(
            new MidiTrack('X', 0, new Collection(MidiNote::class)->with(new MidiNote(0, 60, 100, 96))),
        ))->render());

        $this->assertArrayNotHasKey('bpm', $parsed);
        $this->assertSame('4/4', $parsed['signature'], 'a bar is still stated, because MIDI assumes one');
    }

    /**
     * A channel outside MIDI's sixteen is refused.
     *
     * @return void
     */
    public function testATrackOutsideMidisChannelsIsRefused(): void
    {
        $this->expectException(MidiException::class);

        (void) new MidiTrack('X', 16, new Collection(MidiNote::class));
    }

    /**
     * **A tempo the three-byte payload cannot state is refused, not truncated.**
     *
     * `pack('N')` gives four bytes and the event takes three, so a tempo slow enough to need the
     * fourth would lose it silently — 3 BPM writing itself as roughly 18.6, which is a file that
     * plays at the wrong speed with nothing anywhere reading as wrong. Nothing this repository
     * reads can reach it, FL's own floor being 10 BPM, but every other value this package cannot
     * hold throws, and so does this one.
     *
     * @return void
     */
    public function testATempoTooSlowToStateIsRefused(): void
    {
        $this->expectException(MidiException::class);

        (void) new MidiFile(96, new Collection(MidiTrack::class), 3.0);
    }

    /**
     * And the one either side of the boundary still writes, so the guard is a bound rather than a
     * new floor under ordinary tempos.
     *
     * @return void
     */
    public function testTheSlowestStatableTempoIsStillWritten(): void
    {
        $track  = new MidiTrack('X', 0, new Collection(MidiNote::class)->with(new MidiNote(0, 60, 100, 96)));
        $parsed = $this->parse(
            new MidiFile(96, new Collection(MidiTrack::class)->with($track), 3.6)->render(),
        );

        self::assertEqualsWithDelta(3.6, $parsed['bpm'], 0.01);
    }

    /**
     * The conductor chunk ends the way every other chunk does.
     *
     * It spelled the end-of-track type as a bare `0x2F` — the one meta type in `MidiFile` not
     * named, a line below its own `TEMPO` and `TIME_SIGNATURE` constants — so changing the value on
     * `MidiTrack` could not have reached it. Both call {@link MidiTrack::terminator()} now, and a
     * chunk missing it is one a player reads past the end of.
     *
     * @return void
     */
    public function testEveryChunkEndsWithTheSameTerminator(): void
    {
        $bytes      = new MidiFile(96, new Collection(MidiTrack::class)->with(
            new MidiTrack('X', 0, new Collection(MidiNote::class)->with(new MidiNote(0, 60, 100, 96))),
        ), 140.0)->render();
        $terminator = MidiTrack::terminator();

        self::assertSame("\x00\xFF\x2F\x00", $terminator, 'delta of nothing, then the meta that says stop');
        self::assertSame(2, substr_count($bytes, $terminator), 'the conductor chunk and the one track');
        self::assertStringEndsWith($terminator, $bytes);
    }

    /**
     * Reads a rendered file back — deliberately a second implementation, not the writer in reverse.
     *
     * @param string $bytes
     * @return array<string, mixed>
     */
    private function parse(string $bytes): array
    {
        $cursor = 8;
        $header = unpack('nformat/nchunks/ndivision', substr($bytes, $cursor, 6));
        $cursor += 6;

        $read = static function (string $bytes, int &$cursor): int {
            $value = 0;

            do {
                $byte   = ord($bytes[$cursor++]);
                $value  = ($value << 7) | ($byte & 0x7F);
            } while (($byte & 0x80) !== 0);

            return $value;
        };

        $parsed = ['format' => $header['format'], 'chunks' => $header['chunks'], 'division' => $header['division']];

        for ($chunk = 0; $chunk < $header['chunks']; $chunk++) {
            $this->assertSame('MTrk', substr($bytes, $cursor, 4), 'every chunk after the header is a track');
            $cursor += 4;
            $length  = unpack('N', substr($bytes, $cursor, 4))[1];
            $cursor += 4;
            $end     = $cursor + $length;
            $tick    = 0;
            $name    = null;
            $open    = [];
            $notes   = [];

            while ($cursor < $end) {
                $tick  += $read($bytes, $cursor);
                $status = ord($bytes[$cursor++]);

                if ($status === 0xFF) {
                    $type    = ord($bytes[$cursor++]);
                    $size    = $read($bytes, $cursor);
                    $payload = substr($bytes, $cursor, $size);
                    $cursor += $size;

                    if ($type === 0x03) {
                        $name = $payload;
                    }

                    if ($type === 0x51) {
                        $parsed['bpm'] = round(60_000_000 / unpack('N', "\0" . $payload)[1], 4);
                    }

                    if ($type === 0x58) {
                        $parsed['signature'] = sprintf('%d/%d', ord($payload[0]), 2 ** ord($payload[1]));
                    }

                    continue;
                }

                $key      = ord($bytes[$cursor]);
                $velocity = ord($bytes[$cursor + 1]);
                $cursor  += 2;

                if (($status & 0xF0) === 0x90) {
                    $open[$key][] = [$tick, $velocity];
                    continue;
                }

                [$started, $loud] = array_shift($open[$key]);
                $notes[]          = sprintf('%d:%d:%d:%d', $started, $key, $loud, $tick - $started);
            }

            if ($name !== null) {
                $parsed[$name] = $notes;
            }
        }

        return $parsed;
    }
}
