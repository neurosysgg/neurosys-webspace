<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Waveform;
use NeuroSYS\Model\WaveformBand;
use NeuroSYS\Model\WaveformColumn;
use NeuroSYS\Support\Directory;
use NeuroSYS\Tool\Dsp\Analyze;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The waveform format: what the tooling writes, what the page reads back, and what neither accepts.
 *
 * The claims worth having here are the ones that fail **quietly**. A waveform is a decoration, so
 * nothing throws when it is wrong — a short file draws a track that appears to end early, a stale
 * layout draws the right shape in the wrong colours, and a byte scale that disagrees with the
 * library that fed it draws a mix that looks louder or quieter than it is. None of that reaches a
 * console, and none of it is visible without the audio open beside it to compare against.
 */
#[CoversClass(Waveform::class)]
#[CoversClass(WaveformBand::class)]
#[CoversClass(WaveformColumn::class)]
final class WaveformTest extends TestCase
{
    /**
     * A round trip is identity, which is the whole contract between the two halves.
     *
     * @return void
     */
    public function testWhatWasWrittenIsWhatComesBack(): void
    {
        $waveform = self::waveform();

        self::assertSame($waveform->bytes(), Waveform::parse($waveform->bytes())?->bytes());
    }

    /**
     * The file identifies itself, so a reader is not merely counting bytes.
     *
     * @return void
     */
    public function testTheFileNamesItsOwnLayout(): void
    {
        self::assertStringStartsWith(Waveform::MAGIC, self::waveform()->bytes());
        self::assertSame(Waveform::length(), strlen(self::waveform()->bytes()));
    }

    /**
     * Everything a sidecar can be that is not a waveform, answered the same way.
     *
     * `null` is in the list because it is what {@link \NeuroSYS\Support\File::read()} answers for a
     * file that is absent *and* for one that cannot be read, and a demo staged before waveforms
     * existed is the ordinary case rather than a fault.
     *
     * @param string|null $bytes
     * @return void
     */
    #[DataProvider('unreadableSidecars')]
    public function testAnythingItCannotVouchForIsNoWaveformRatherThanAnError(?string $bytes): void
    {
        self::assertNull(Waveform::parse($bytes));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unreadableSidecars(): iterable
    {
        $good = self::waveform()->bytes();

        yield 'no file at all'        => [null];
        yield 'empty'                 => [''];
        yield 'the magic alone'       => [Waveform::MAGIC];
        yield 'one column short'      => [substr($good, 0, -WaveformBand::stride())];
        yield 'one column too many'   => [$good . "\0\0\0\0"];
        yield 'the right size, wrong' => [str_repeat("\0", Waveform::length())];
        // An MP3 dropped in beside the audio is the realistic version of the last case.
        yield 'a next-version file'   => ['NSW2' . substr($good, 4)];
    }

    /**
     * A count that is not the count is refused where it is built, not where it is drawn.
     *
     * @return void
     */
    public function testAWaveformThatIsNotTheAgreedNumberOfColumnsIsRefused(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('exactly 512 columns, got 3');

        Waveform::of(...array_fill(0, 3, new WaveformColumn(0.5, -6.0, -12.0, -24.0)));
    }

    /**
     * The attribute carries the columns and not the file.
     *
     * @return void
     */
    public function testTheAttributeLeavesTheMagicBehind(): void
    {
        $decoded = base64_decode(self::waveform()->base64(), true);

        self::assertSame(Waveform::COLUMNS * WaveformBand::stride(), strlen((string) $decoded));
        self::assertStringStartsNotWith(Waveform::MAGIC, (string) $decoded);
    }

    /**
     * Quantisation, at every place it can be wrong.
     *
     * The clamps are not hypothetical. `pack('C')` wraps rather than saturates, so an unclamped
     * value one step over the top reads as near-silence — a drop that draws as a gap, in the one
     * column of the track where the shape matters most.
     *
     * @param WaveformColumn $column
     * @param list<int>      $expected
     * @return void
     */
    #[DataProvider('columns')]
    public function testAColumnQuantisesToFourBytesOnKnownScales(WaveformColumn $column, array $expected): void
    {
        self::assertSame($expected, array_values(unpack('C*', $column->bytes())));
    }

    /**
     * @return iterable<string, array{WaveformColumn, list<int>}>
     */
    public static function columns(): iterable
    {
        $floor = WaveformColumn::DBFS_FLOOR;

        yield 'silence'          => [new WaveformColumn(0.0, $floor, $floor, $floor), [0, 0, 0, 0]];
        yield 'the loudest slice' => [new WaveformColumn(1.0, 0.0, 0.0, 0.0), [255, 255, 255, 255]];
        yield 'halfway'          => [new WaveformColumn(0.5, -30.0, -30.0, -30.0), [128, 128, 128, 128]];
        yield 'over the top'     => [new WaveformColumn(1.243, 0.0, 0.0, 0.0), [255, 255, 255, 255]];
        yield 'under the floor'  => [new WaveformColumn(-0.5, -90.0, -90.0, -90.0), [0, 0, 0, 0]];
        // Band order is the format, so a column where the three differ pins it.
        yield 'a lows-heavy hit' => [new WaveformColumn(1.0, 0.0, -30.0, -60.0), [255, 255, 128, 0]];
    }

    /**
     * The byte scale's floor and the library's floor are the same number, stated twice.
     *
     * They mean different things — one is a meter's bottom, one is the bottom of an 8-bit scale —
     * which is why {@link WaveformColumn} carries its own and `src/` does not reach into `tools/`
     * for it. This is the assertion that keeps the coincidence true: change one and every staged
     * waveform is drawn against a scale the analysis did not use, silently.
     *
     * @return void
     */
    public function testTheFormatsFloorAgreesWithTheLibraryThatFeedsIt(): void
    {
        self::assertSame(Analyze::DBFS_FLOOR, WaveformColumn::DBFS_FLOOR);
    }

    /**
     * The stride is the enum, so a fifth byte cannot be added in one place only.
     *
     * @return void
     */
    public function testTheStrideIsTheBandsAndTheBandsAreTheThreeSpectralOnes(): void
    {
        self::assertSame(4, WaveformBand::stride());
        self::assertSame(
            [WaveformBand::Low, WaveformBand::Mid, WaveformBand::High],
            WaveformBand::bands()->toValues(),
        );
        self::assertSame(0, WaveformBand::Level->value, 'the height is the first byte of a column');
    }

    /**
     * The sidecar sits beside the audio, named for the mix rather than for its file.
     *
     * @return void
     */
    public function testTheSidecarIsNamedForTheLabel(): void
    {
        $file = Waveform::fileIn(new Directory('/srv/data/demos/wna-bootleg'), 'v4');

        self::assertSame('/srv/data/demos/wna-bootleg/v4.wave', $file->path);
    }

    /**
     * A waveform with something in every column, so a truncation shows up as a length change.
     *
     * @return Waveform
     */
    private static function waveform(): Waveform
    {
        $columns = [];

        for ($i = 0; $i < Waveform::COLUMNS; $i++) {
            $fraction  = $i / Waveform::COLUMNS;
            $columns[] = new WaveformColumn(
                $fraction,
                -60.0 + 60.0 * $fraction,
                -30.0,
                -60.0 * $fraction,
            );
        }

        return Waveform::of(...$columns);
    }
}
