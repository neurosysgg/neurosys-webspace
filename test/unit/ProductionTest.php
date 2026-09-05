<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Production\Arrangement;
use NeuroSYS\Model\Production\Plugin;
use NeuroSYS\Model\Production\ProductionTime;
use NeuroSYS\Model\Production\Section;
use NeuroSYS\Model\Production\SectionKind;
use NeuroSYS\Model\Release;
use NeuroSYS\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `src/NeuroSYS/Model/Production/` — the facts a release gets from its own project file.
 *
 * These are the three things nothing else on the site could supply: how the track is laid out, how
 * long it took, and what made it. They arrive through `tools/lib/Flp/` and are read back here, so
 * what is worth pinning is the half that survives into `data/releases.php` — the guards, and the
 * arithmetic that turns a tick into a time.
 */
final class ProductionTest extends TestCase
{
    /**
     * @return array<string, array{string, SectionKind|null}>
     */
    public static function labelProvider(): array
    {
        return [
            'intro'                        => ['INTRO', SectionKind::Intro],
            'drop'                         => ['DROP', SectionKind::Drop],
            'break'                        => ['BREAK', SectionKind::Break],
            'bridge'                       => ['BRIDGE', SectionKind::Bridge],
            'outro'                        => ['OUTRO', SectionKind::Outro],
            'the spelling hello world uses' => ['BUILDUP', SectionKind::Build],
            'the spelling break shit uses' => ['BUILD', SectionKind::Build],
            'a numbered marker keeps its kind' => ['SWITCH 1', SectionKind::Switchover],
            'lower case, since a label is typed by hand' => ['drop', SectionKind::Drop],
            'a label nothing recognises'   => ['VERSE', null],
            'nothing at all'               => ['', null],
        ];
    }

    /**
     * @param string           $label
     * @param SectionKind|null $expected
     * @return void
     */
    #[DataProvider('labelProvider')]
    public function testALabelIsClassifiedOrLeftPlain(string $label, ?SectionKind $expected): void
    {
        $this->assertSame($expected, SectionKind::classify($label));
    }

    /**
     * The one place the order of `classify()`'s arms matters rather than merely reads well.
     *
     * `BUILDDOWN` begins with `BUILD`, so a shorter match tried first swallows it — and the two are
     * opposite ends of a track. Both spellings occur in the real projects.
     *
     * @return void
     */
    public function testBuilddownIsNotSwallowedByBuild(): void
    {
        $this->assertSame(SectionKind::BuildDown, SectionKind::classify('BUILDDOWN'));
        $this->assertSame(SectionKind::BuildDown, SectionKind::classify('BUILD DOWN'));
        $this->assertSame(SectionKind::Build, SectionKind::classify('BUILDUP'));
    }

    /**
     * A tick becomes a time only against the tempo and the pulse rate.
     *
     * 12288 ticks at 96 ppq is 128 beats, which at 140 BPM is 54.86 seconds — `ill.`'s first drop,
     * and the number the release page prints beside it.
     *
     * @return void
     */
    public function testASectionKnowsWhenItStarts(): void
    {
        $drop = Section::named('DROP', 12288);

        $this->assertSame(SectionKind::Drop, $drop->kind);
        $this->assertEqualsWithDelta(54.857, $drop->seconds(140, 96), 0.001);
        $this->assertSame('0:54', $drop->timestamp(140, 96));
        $this->assertSame('2:44', Section::named('OUTRO', 36864)->timestamp(140, 96));
    }

    /**
     * A release with no bpm is not a division to attempt.
     *
     * @return void
     */
    public function testASectionWithNothingToMeasureAgainstSitsAtZero(): void
    {
        $this->assertSame(0.0, Section::named('DROP', 12288)->seconds(0, 96));
        $this->assertSame(0.0, Section::named('DROP', 12288)->seconds(140, 0));
        $this->assertSame('0:00', Section::named('DROP', 12288)->timestamp(0, 96));
    }

    /**
     * @return void
     */
    public function testASectionCannotStartBeforeTheTrackDoes(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('Section::tick cannot be negative.');

        new Section('DROP', -1);
    }

    /**
     * @return void
     */
    public function testAnArrangementGuardsWhatItHolds(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('Arrangement::sections must be a Collection of \Section.');

        new Arrangement(new Collection(Plugin::class));
    }

    /**
     * @return void
     */
    public function testAnArrangementWithNoPulseRateIsRefused(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('Arrangement::ppq must be greater than 0');

        new Arrangement(new Collection(Section::class), ppq: 0);
    }

    /**
     * `lastStart()` is where the last marker is, not where the track ends — see the method.
     *
     * @return void
     */
    public function testAnArrangementMeasuresItsLastMarkerRatherThanItsEnd(): void
    {
        $arrangement = new Arrangement(new Collection(Section::class)->with(
            Section::named('INTRO', 0),
            Section::named('DROP', 12288),
            Section::named('OUTRO', 36864),
        ));

        $this->assertFalse($arrangement->isEmpty());
        $this->assertEqualsWithDelta(164.571, $arrangement->lastStart(140), 0.001);

        $offsets = array_map(static fn(array $p): float => $p['offset'], $arrangement->positions(140));

        $this->assertEqualsWithDelta([0.0, 1 / 3, 1.0], $offsets, 0.001);
    }

    /**
     * A single-section arrangement has no span to divide by.
     *
     * @return void
     */
    public function testAnArrangementWithNoSpanPutsEverythingAtTheStart(): void
    {
        $arrangement = new Arrangement(new Collection(Section::class)->with(Section::named('INTRO', 0)));

        $this->assertSame(0.0, $arrangement->lastStart(140));
        $this->assertSame([0.0], array_map(
            static fn(array $p): float => $p['offset'],
            $arrangement->positions(140),
        ));
    }

    /**
     * @return void
     */
    public function testAnEmptyArrangementSaysSo(): void
    {
        $empty = new Arrangement(new Collection(Section::class));

        $this->assertTrue($empty->isEmpty());
        $this->assertSame(0.0, $empty->lastStart(140));
        $this->assertSame([], $empty->positions(140));
    }

    /**
     * The two real figures, and the sub-hour form nothing has needed yet.
     *
     * @return void
     */
    public function testProductionTimeReadsAsHoursAndMinutes(): void
    {
        $this->assertSame('60h 07m', ProductionTime::of(60, 7)->render());
        $this->assertSame('42h 48m', ProductionTime::of(42, 48)->render());
        $this->assertSame('48m', ProductionTime::of(0, 48)->render());
        $this->assertSame(216420, ProductionTime::of(60, 7)->seconds);
        $this->assertSame(60, ProductionTime::of(60, 7)->hours());
        $this->assertSame(7, ProductionTime::of(60, 7)->minutes());
    }

    /**
     * @return void
     */
    public function testProductionTimeCannotRunBackwards(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('ProductionTime::seconds cannot be negative.');

        new ProductionTime(-1);
    }

    /**
     * @return void
     */
    public function testAPluginNeedsAName(): void
    {
        $this->assertSame('Serum 2', new Plugin('Serum 2')->name);

        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('Plugin::name cannot be blank.');

        new Plugin('   ');
    }

    /**
     * The guard beside the one `Release` already had for `formats`.
     *
     * @return void
     */
    public function testAReleaseGuardsWhatItWasMadeWith(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->expectExceptionMessage('Release::madeWith must be a Collection of \Plugin.');

        new Release(
            title:       'ill.',
            bpm:         140,
            key:         MusicalKey::DSharpMinor,
            genre:       Genre::Dubstep,
            description: 'wub wub',
            cover:       null,
            formats:     new Collection(Format::class),
            madeWith:    new Collection(Section::class),
        );
    }

    /**
     * A release written before any of this existed still constructs, and says nothing extra.
     *
     * The reason all three are optional: `data/releases.php` is hand-ordered and hand-edited, and an
     * entry that had to be rewritten to keep working would make adding the fields a migration.
     *
     * @return void
     */
    public function testAReleaseWithoutAProjectIsUnchanged(): void
    {
        $release = new Release(
            title:       'ill.',
            bpm:         140,
            key:         MusicalKey::DSharpMinor,
            genre:       Genre::Dubstep,
            description: 'wub wub',
            cover:       null,
            formats:     new Collection(Format::class),
        );

        $this->assertNull($release->arrangement);
        $this->assertNull($release->timeSpent);
        $this->assertSame([], $release->madeWith->all());
    }
}
