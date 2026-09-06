<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Tool\Flp\EventId;
use NeuroSYS\Tool\Flp\FlpFile;
use NeuroSYS\Tool\Flp\Plugins;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `tools/lib/Flp/Plugins` — the scan that guesses what a project was made with.
 *
 * **This is the one reader in `tools/lib/Flp/` that answers a question the format does not.** The
 * tempo is a dword and the key is a marker; a hosted plugin's real name is buried in its wrapper's
 * serialised state at an offset that varies per plugin and per version. So the scan tries every
 * offset for the one shape that is dependable — eight bytes of little-endian length, then that many
 * bytes of ASCII — and sorts out the wreckage afterwards with three rules.
 *
 * Those three rules are why this has a file of its own. Every one of them was set against a corpus
 * of real projects rather than derived, so **none of them is recoverable by reading the code**, and
 * the class docblock says as much about the loosest of them: five capitals and not three, because
 * `LFOTool` is a real Xfer plugin and a threshold of three throws it away. A later tightening that
 * looks obviously correct would drop it again in silence — the output is a commented-out list a
 * person trims, so a name that stops being offered is a name nobody notices is gone.
 *
 * Every blob here is assembled byte by byte, for the reason {@link FlpTest} assembles projects that
 * way: it keeps a repository whose largest real project is 13MB free of binary fixtures, and it
 * reaches the rejection branches that a real wrapper blob would only produce by luck.
 */
final class PluginsTest extends TestCase
{
    /**
     * A length-prefixed run of bytes, which is the only shape inside a wrapper blob that holds.
     *
     * Concatenating several is what a real blob looks like, and the scan reads each one where it
     * starts — the bytes straddling a join read as a length in the hundreds of millions and are
     * skipped, which is worth knowing when reading the counts these tests assert.
     *
     * @param string ...$names
     * @return string
     */
    private function blob(string ...$names): string
    {
        $bytes = '';

        foreach ($names as $name) {
            $bytes .= pack('P', strlen($name)) . $name;
        }

        return $bytes;
    }

    /**
     * A project carrying one plugin event, and what the scan makes of it.
     *
     * @param string ...$names
     * @return list<string>
     */
    private function scan(string ...$names): array
    {
        return Plugins::of($this->project($this->blob(...$names)));
    }

    /**
     * A project whose data chunk is one {@link EventId::PluginData} event.
     *
     * @param string $blob
     * @return FlpFile
     */
    private function project(string $blob): FlpFile
    {
        $events = $this->data(EventId::PluginData->value, $blob);

        return FlpFile::read(
            'FLhd' . pack('V', 6) . pack('vvv', 0, 26, 96)
            . 'FLdt' . pack('V', strlen($events)) . $events,
        );
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
     * Three occurrences, because two is what a coincidence looks like.
     *
     * The scan reads every offset rather than following a structure, so a plausible length in front
     * of plausible bytes happens on its own. A name that turns up once or twice across a whole
     * project is that; a name the project actually loaded turns up in every instance of it.
     *
     * @return void
     */
    public function testANameHasToTurnUpThreeTimesBeforeItIsOffered(): void
    {
        self::assertSame(['Serum 2'], $this->scan('Serum 2', 'Serum 2', 'Serum 2'));
        self::assertSame([], $this->scan('Serum 2', 'Serum 2'));
    }

    /**
     * @return void
     */
    public function testCandidatesComeBackMostUsedFirst(): void
    {
        self::assertSame(['Serum 2', 'Kilohearts'], $this->scan(
            'Kilohearts',
            'Kilohearts',
            'Kilohearts',
            'Serum 2',
            'Serum 2',
            'Serum 2',
            'Serum 2',
            'Serum 2',
        ));
    }

    /**
     * The tail is where the wreckage collects, so only the first twelve are offered.
     *
     * @return void
     */
    public function testOnlyTheFirstTwelveAreOffered(): void
    {
        $names = [];

        for ($i = 0; $i < 13; $i++) {
            // Descending counts, so which twelve survive is decided rather than incidental: the
            // thirteenth is the least-used and is the one that has to fall off.
            $names = [...$names, ...array_fill(0, 16 - $i, sprintf('Plugin %s', chr(97 + $i)))];
        }

        $found = $this->scan(...$names);

        self::assertCount(12, $found);
        self::assertSame('Plugin a', $found[0]);
        self::assertNotContains('Plugin m', $found);
    }

    /**
     * **The regression this file exists for.**
     *
     * `LFOTool` is a real Xfer plugin whose name is four capitals running into a lowercase letter,
     * which is exactly the shape of the false positives the wreckage rule throws away. The rule is
     * five capitals and not three for this one reason, stated on the class and pinned here so that
     * tightening it back has to argue with a test rather than with a comment.
     *
     * @return void
     */
    public function testARealPluginWhoseNameShoutsIsKept(): void
    {
        self::assertSame(['LFOTool'], $this->scan('LFOTool', 'LFOTool', 'LFOTool'));
    }

    /**
     * Bytes that read as a name but are not one.
     *
     * `ZTSVIOO2zone Ima` is `Ozone Imager 2` with four bytes of something else welded to the front,
     * and it is the specimen the wreckage rule was written against: the join is always a shout of
     * capitals hitting normal text. The other two are the rules either side of it — a candidate
     * with no lowercase at all is a token rather than a name, and one that opens with a digit or
     * punctuation is not a name anyone typed.
     *
     * @param string $candidate
     * @return void
     */
    #[DataProvider('wreckage')]
    public function testBytesThatAreNotANameAreDropped(string $candidate): void
    {
        self::assertSame([], $this->scan($candidate, $candidate, $candidate));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wreckage(): array
    {
        return [
            'a shout welded onto real text' => ['ZTSVIOO2zone Ima'],
            'digits between the two halves' => ['XFERRECORDS2serum'],
            'no lowercase anywhere'         => ['DEADBEEF'],
            'opens with a digit'            => ['2nd Voice'],
            'opens with punctuation'        => ['-Serum'],
            'holds a byte no name holds'    => ["Serum\x02"],
        ];
    }

    /**
     * Shorter is noise; longer is a path or a preset blob, not a product name.
     *
     * @return void
     */
    public function testRunsOutsideTheLengthBandAreNotReadAtAll(): void
    {
        self::assertSame([], $this->scan('abc', 'abc', 'abc'));

        $tooLong = str_repeat('a', 49);

        self::assertSame([], $this->scan($tooLong, $tooLong, $tooLong));

        // The band is inclusive at both ends, which is the half a boundary test is actually for.
        $shortest = 'abcd';
        $longest  = str_repeat('a', 48);

        self::assertSame([$shortest], $this->scan($shortest, $shortest, $shortest));
        self::assertSame([$longest], $this->scan($longest, $longest, $longest));
    }

    /**
     * A length that promises more bytes than the blob holds is skipped rather than read.
     *
     * The scan is walking bytes it does not understand, so this is not a hypothetical: it is what
     * every offset in a real wrapper blob looks like most of the time. Reading past the end would
     * be a substr of whatever the blob happens to end with, offered as a plugin.
     *
     * @return void
     */
    public function testALengthThatRunsPastTheEndOfTheBlobIsSkipped(): void
    {
        self::assertSame([], Plugins::of($this->project(pack('P', 20) . 'short')));
    }

    /**
     * A project with no plugin events at all has nothing to say, rather than nothing to sort.
     *
     * @return void
     */
    public function testAProjectWithNoPluginEventsOffersNothing(): void
    {
        self::assertSame([], Plugins::of(FlpFile::read(
            'FLhd' . pack('V', 6) . pack('vvv', 0, 26, 96) . 'FLdt' . pack('V', 0),
        )));
    }

    /**
     * Occurrences are counted across the whole project, not within one blob.
     *
     * Which is the real shape: FL writes one {@link EventId::PluginData} event per instance, so a
     * synth loaded on three channels is three events carrying one name each — and a rule that
     * counted within a blob would offer nothing at all for the commonest case there is.
     *
     * @return void
     */
    public function testOccurrencesAreCountedAcrossEveryPluginEvent(): void
    {
        $one    = $this->data(EventId::PluginData->value, $this->blob('Serum 2'));
        $events = $one . $one . $one;

        $flp = FlpFile::read(
            'FLhd' . pack('V', 6) . pack('vvv', 0, 26, 96)
            . 'FLdt' . pack('V', strlen($events)) . $events,
        );

        self::assertSame(['Serum 2'], Plugins::of($flp));
    }
}
