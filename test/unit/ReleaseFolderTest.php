<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Model\Genre;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Tool\Release\Cover;
use NeuroSYS\Tool\Release\EntryWriter;
use NeuroSYS\Tool\Release\Fact;
use NeuroSYS\Tool\Release\KeyNotation;
use NeuroSYS\Tool\Release\Level;
use NeuroSYS\Tool\Release\ReleaseFolder;
use NeuroSYS\Tool\Release\Source;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parts of `tools/lib/Release/` that do not need a folder on disk — see docs/authoring.md.
 *
 * What reads a folder shells out to `metaflac` and `ffprobe`, which is not something a unit test
 * should reach for; that half is exercised by running the tool. What is worth pinning here is the
 * part that fails *silently*: an unresolved key is a null, and a null key is a staged release with
 * no key rather than an error anybody sees.
 */
final class ReleaseFolderTest extends TestCase
{
    /**
     * A folder with facts but no files, for the parts that only read them back.
     *
     * @param array<string, string> $audio
     * @param Cover|null            $cover
     * @return ReleaseFolder
     */
    private function folder(array $audio = [], ?Cover $cover = null): ReleaseFolder
    {
        return new ReleaseFolder(
            path:   '/x',
            master: '/x/ill..flac',
            title:  'ill.',
            bpm:    140,
            key:    MusicalKey::DSharpMinor,
            genre:  Genre::Dubstep,
            cover:  $cover,
            date:   '2026-09-04',
            audio:  $audio,
        );
    }

    /**
     * Every case, in the spelling FL Studio writes into `INITIALKEY`.
     *
     * @return void
     */
    public function testEveryMusicalKeyResolvesFromItsFlStudioSpelling(): void
    {
        foreach (MusicalKey::cases() as $case) {
            [$note, $mode] = explode(' ', $case->value);

            $written = $note . ($mode === 'Major' ? 'Maj' : 'Min');

            $this->assertSame($case, KeyNotation::parse($written), sprintf('%s should resolve', $written));
        }
    }

    /**
     * The spellings that are not FL Studio's, and the reason the parser is not a lookup table.
     *
     * `MusicalKey` carries only sharps, so a flat has to be folded to its enharmonic equivalent —
     * `Ebm` and `D#Min` are the same key. Case varies because the filename convention has produced
     * `140 d#min` far more often than `140 D#Min`.
     *
     * @param string          $written
     * @param MusicalKey|null $expected
     * @return void
     */
    #[DataProvider('keySpellingProvider')]
    public function testKeySpellingsResolveOrAreRefused(string $written, ?MusicalKey $expected): void
    {
        $this->assertSame($expected, KeyNotation::parse($written));
    }

    /**
     * @return array<string, array{string, MusicalKey|null}>
     */
    public static function keySpellingProvider(): array
    {
        return [
            'flat folds to its sharp'          => ['Ebm', MusicalKey::DSharpMinor],
            'flat major'                       => ['Dbmaj', MusicalKey::CSharpMajor],
            'flat with a space'                => ['Gb Min', MusicalKey::FSharpMinor],
            'flat that crosses a white key'    => ['Cb', MusicalKey::BMajor],
            'lower case, as filenames have it' => ['d#min', MusicalKey::DSharpMinor],
            'upper case mode'                  => ['F#MAJ', MusicalKey::FSharpMajor],
            'bare note is major'               => ['A', MusicalKey::AMajor],
            'single m is minor'                => ['Am', MusicalKey::AMinor],
            'not a key'                        => ['wobble', null],
            'not a note in this system'        => ['Hmaj', null],
            'the whole filename token'         => ['140 D#Min', null],
            'empty'                            => ['', null],
        ];
    }

    /**
     * @param string $title
     * @param string $expected
     * @return void
     */
    #[DataProvider('slugProvider')]
    public function testTitlesSlugify(string $title, string $expected): void
    {
        $this->assertSame($expected, ReleaseFolder::slugFor($title));
    }

    /**
     * The trailing punctuation goes first, and deliberately: `ReleaseView` splits a trailing `!`,
     * `.` or `?` off to accent it, so it is presentation rather than identity.
     *
     * @return array<string, array{string, string}>
     */
    public static function slugProvider(): array
    {
        return [
            'trailing dot is dropped'    => ['ill.', 'ill'],
            'trailing bang is dropped'   => ['hello world!', 'hello-world'],
            'spaces become hyphens'      => ['take me away', 'take-me-away'],
            'punctuation collapses'      => ['who are u?? (VIP)', 'who-are-u-vip'],
            'no double hyphens or edges' => ['  --chaos--  ', 'chaos'],
        ];
    }

    /**
     * The formats come out in the order the catalogue offers them, not the order the enum declares.
     *
     * Only the file names matter, so the fixture is empty files — the ordering is a decision about
     * presentation, and a wrong one reshuffles the download cards of any release regenerated through
     * the tool without anything else noticing. This goes through `at()` rather than a private
     * helper, which also proves a folder with nothing readable in it still reports its formats.
     *
     * @return void
     */
    public function testFormatsAreOrderedTheWayTheCatalogueOffersThem(): void
    {
        $path = sys_get_temp_dir() . '/neurosys-stage-' . bin2hex(random_bytes(6));

        mkdir($path);

        // Created in an order that is neither the enum's nor the wanted one.
        foreach (['t.mp3', 't.flac', 'a remix package.zip', 't.wav'] as $name) {
            touch($path . '/' . $name);
        }

        try {
            $formats = ReleaseFolder::at($path)->formats();

            $this->assertSame(
                [ReleaseFormat::FLAC, ReleaseFormat::WAV, ReleaseFormat::MP3, ReleaseFormat::STEMS],
                $formats,
            );
        } finally {
            array_map(unlink(...), glob($path . '/*') ?: []);
            rmdir($path);
        }
    }

    /**
     * A fact the folder could not resolve keeps the string it could not read, for the report to show.
     *
     * @return void
     */
    public function testAnUnresolvedFactIsReportedWithWhatTheFolderActuallyHeld(): void
    {
        $folder = $this->folder();

        $this->assertTrue($folder->has(Fact::Title));
        $this->assertNull($folder->raw(Fact::Genre));
        $this->assertSame([], $folder->missing());
        $this->assertSame('ill', $folder->slug());
    }

    /**
     * The provenance of a cover is the thing that decides whether the preflight warns about it, so
     * it is compared as an enum case rather than as the string it used to be on both sides.
     *
     * @return void
     */
    public function testACoverKnowsWhetherItIsTheWebExport(): void
    {
        $this->assertTrue(new Cover('/x/web/ill. cover.jpg', Source::WebExport)->isWebExport());
        $this->assertFalse(new Cover('/x/ill..flac', Source::EmbeddedPicture)->isWebExport());
        $this->assertSame('ill. cover.jpg', new Cover('/x/web/ill. cover.jpg', Source::WebExport)->name());
    }

    /**
     * The report's four-column severity labels, which `test/basic_test.sh` also renders.
     *
     * @return void
     */
    public function testLevelsRenderAndOnlyFailureStops(): void
    {
        $this->assertSame('OK  ', Level::Ok->label());
        $this->assertSame('WARN', Level::Warn->label());
        $this->assertSame('FAIL', Level::Fail->label());

        $this->assertFalse(Level::Ok->isFailure());
        $this->assertFalse(Level::Warn->isFailure());
        $this->assertTrue(Level::Fail->isFailure());
    }

    /**
     * The emitted entry has to be the shape `data/releases.php` already uses, because it is pasted
     * into it — including the indentation, which is what a heredoc gets wrong by default.
     *
     * @return void
     */
    public function testTheStagedEntryMatchesTheShapeOfTheDataFile(): void
    {
        $php = EntryWriter::write($this->folder(
            audio: [
                ReleaseFormat::FLAC->value  => '/x/ill..flac',
                ReleaseFormat::STEMS->value => '/x/140 D#Min ill remix package.zip',
            ],
            cover: new Cover('/x/web/ill. cover.jpg', Source::WebExport),
        ));

        $this->assertStringStartsWith("    'ill' => new Release(\n", $php);
        $this->assertStringContainsString("        title:       'ill.',\n", $php);
        $this->assertStringContainsString("        key:         MusicalKey::DSharpMinor,\n", $php);
        $this->assertStringContainsString("        genre:       Genre::Dubstep,\n", $php);

        // Column-aligned, and each one naming the file whose share id is wanted.
        $this->assertStringContainsString(
            "            new Format(ReleaseFormat::FLAC),   // share id for ill..flac\n",
            $php,
        );
        $this->assertStringContainsString(
            "            new Format(ReleaseFormat::STEMS),  // share id for 140 D#Min ill remix package.zip\n",
            $php,
        );

        // The three facts a folder cannot supply are left as the staged states Release already models.
        $this->assertStringContainsString("description: '',", $php);
        $this->assertStringContainsString('cover:       null,', $php);
        $this->assertStringContainsString('// embed: new SoundCloudEmbed(', $php);
    }

    /**
     * The staged entry is not a draft — it has to load, and produce a renderable `Release`.
     *
     * @return void
     */
    public function testTheStagedEntryEvaluatesToARenderableRelease(): void
    {
        $php = EntryWriter::write($this->folder(audio: [ReleaseFormat::FLAC->value => '/x/ill..flac']));

        $releases = eval(sprintf(
            'use NeuroSYS\Model\{Release, Format, Genre, MusicalKey, ReleaseFormat};'
            . 'use NeuroSYS\Support\Collection; return [%s];',
            $php,
        ));

        $release = $releases['ill'];

        $this->assertSame('ill.', $release->title);
        $this->assertSame(140, $release->bpm);
        $this->assertSame(MusicalKey::DSharpMinor, $release->key);
        $this->assertNull($release->cover);
        $this->assertNull($release->embed);

        // A format with no link is the staged state: the card renders, and clicking it 503s.
        $this->assertNull($release->findFormat(ReleaseFormat::FLAC)?->link);
    }
}
