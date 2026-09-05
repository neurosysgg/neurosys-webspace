<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;
use NeuroSYS\Tool\Php\ClassConstant;
use NeuroSYS\Tool\Php\Value;
use NeuroSYS\Tool\Release\Cover;
use NeuroSYS\Tool\Release\EntryWriter;
use NeuroSYS\Tool\Release\Fact;
use NeuroSYS\Tool\Release\Finding;
use NeuroSYS\Tool\Release\KeyNotation;
use NeuroSYS\Tool\Release\Level;
use NeuroSYS\Tool\Release\Preflight;
use NeuroSYS\Tool\Release\ProjectFile;
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
     * The entry a project produces evaluates too, arrangement and all.
     *
     * The half-state is the thing worth checking here: `madeWith` is written **commented out**, so
     * a release staged this way has an arrangement and a time spent and no credits until a person
     * has been through the candidate list. See {@link \NeuroSYS\Tool\Flp\Plugins}.
     *
     * @return void
     */
    public function testAnEntryWithAProjectEvaluatesWithItsArrangement(): void
    {
        $path = self::folderWithProject();

        try {
            $folder = ReleaseFolder::at($path);
            $php    = EntryWriter::write($folder);
            // eval() is a language construct, so its result cannot be indexed where it stands.
            $releases = eval(self::evaluable($folder, $php));
            $release  = $releases['ill'];

            $this->assertSame(140, $release->bpm);
            $this->assertSame(MusicalKey::DSharpMinor, $release->key);
            $this->assertSame(
                ['INTRO', 'DROP'],
                array_map(static fn($s): string => $s->label, $release->arrangement->sections->all()),
            );
            $this->assertSame('1h 00m', $release->timeSpent?->render());
            $this->assertSame([], $release->madeWith->all(), 'credits are commented out for a person');
        } finally {
            self::remove($path);
        }
    }

    /**
     * A leaf is written by `var_export()`, with the two exceptions that bit when it was not.
     *
     * `var_export(null)` is `NULL` in capitals — alone among the literals it emits, and unlike every
     * other null in `data/releases.php`. And an enum has to come out as the short `Genre::Dubstep`
     * the file imports rather than the fully-qualified name `var_export()` would give.
     *
     * @return void
     */
    public function testALeafIsWrittenAsTheDataFileWritesIt(): void
    {
        $this->assertSame('null', new Value(null)->render());
        $this->assertSame("'ill.'", new Value('ill.')->render());
        $this->assertSame('140', new Value(140)->render());
        $this->assertSame('MusicalKey::DSharpMinor', new Value(MusicalKey::DSharpMinor)->render());
        $this->assertSame('Genre::Dubstep', new Value(Genre::Dubstep)->render());
        $this->assertSame('Format::class', new ClassConstant(Format::class)->render());

        // The full name is still what the import list needs, so both are kept.
        $this->assertSame(MusicalKey::class, new Value(MusicalKey::DSharpMinor)->className());
        $this->assertNull(new Value('ill.')->className());
    }

    /**
     * A string that would break the source is quoted rather than escaped by hand.
     *
     * This is what `var_export()` is there for, and what a heredoc with `%s` holes was not doing: a
     * release title is arbitrary text, and one with an apostrophe in it used to be a data file that
     * would not parse.
     *
     * @return void
     */
    public function testAValueThatWouldBreakTheSourceIsQuoted(): void
    {
        $this->assertSame("'don\\'t'", new Value("don't")->render());
        $this->assertSame("'a\\\\b'", new Value('a\\b')->render());
    }

    /**
     * The writer names every class the entry uses, so the file can be told what to import.
     *
     * @return void
     */
    public function testTheWriterNamesEveryClassTheEntryUses(): void
    {
        $imports = EntryWriter::imports($this->folder(audio: [ReleaseFormat::FLAC->value => '/x/ill..flac']));

        $this->assertContains(Release::class, $imports);
        $this->assertContains(MusicalKey::class, $imports);
        $this->assertContains(Genre::class, $imports);
        $this->assertContains(Format::class, $imports);
        $this->assertContains(ReleaseFormat::class, $imports);
        $this->assertContains(Collection::class, $imports);
        $this->assertSame($imports, array_unique($imports), 'each class named once');
    }

    /**
     * The `use` block the emitted entry needs, in front of it, for eval().
     *
     * @param ReleaseFolder $folder
     * @param string        $php
     * @return string
     */
    private static function evaluable(ReleaseFolder $folder, string $php): string
    {
        $imports = array_map(
            static fn(string $class): string => sprintf('use %s;', $class),
            EntryWriter::imports($folder),
        );

        return implode('', $imports) . sprintf('return [%s];', $php);
    }

    /**
     * A folder holding a project reads its facts from there, and says so in the third column.
     *
     * The project is written byte by byte for the reason `FlpTest` writes its own: the real ones
     * are 4-13MB and live outside the repository. What this is really pinning is the **ordering**
     * — bpm, key and genre come from the project because FL Studio is what wrote the tags under
     * them, while the title does not, because `ill.`'s project is called `ill` and the trailing dot
     * is a decision taken at export. See {@link Source}.
     *
     * @return void
     */
    public function testAProjectOutranksTheTagsExceptForTheTitle(): void
    {
        $path = self::folderWithProject();

        try {
            $folder = ReleaseFolder::at($path);

            $this->assertSame(140, $folder->bpm);
            $this->assertSame(Source::FlpTempo, $folder->sourceOf(Fact::Bpm));
            $this->assertSame(MusicalKey::DSharpMinor, $folder->key);
            $this->assertSame(Source::FlpKeyLock, $folder->sourceOf(Fact::Key));
            $this->assertSame(Genre::Dubstep, $folder->genre);
            $this->assertSame(Source::FlpGenre, $folder->sourceOf(Fact::Genre));

            // No FLAC in the fixture, so the title falls through to the project rather than winning
            // from it — the fallback rung, which is what an untagged older master would land on.
            $this->assertSame('ill', $folder->title);
            $this->assertSame(Source::FlpTitle, $folder->sourceOf(Fact::Title));
        } finally {
            self::remove($path);
        }
    }

    /**
     * A folder with no project says so, rather than quietly resting on the tags.
     *
     * @return void
     */
    public function testAFolderWithNoProjectIsWarnedAbout(): void
    {
        $findings = Preflight::check($this->folder());
        $messages = array_map(static fn(Finding $f): string => $f->message, $findings);

        $this->assertContains(
            'project: no .flp in the folder, so bpm, key and genre rest on the tags alone',
            $messages,
        );
    }

    /**
     * A project whose piano roll locks nothing is a WARN naming what its notes suggest instead.
     *
     * `hello world!` is the live instance: a shipped release whose project sets no scale marker, so
     * its key comes from the FLAC tag and the estimate only corroborates it. An estimate is never a
     * value — see `KeyEstimate` — so what is pinned here is that it reaches the report as a
     * sentence and the fact stays null.
     *
     * @return void
     */
    public function testAProjectWithNoKeyLockOffersItsEstimateAsAWarning(): void
    {
        $path = self::folderWithProject(keyLock: false);

        try {
            $folder   = ReleaseFolder::at($path);
            $messages = array_map(
                static fn(Finding $f): string => $f->message,
                array_filter(
                    Preflight::check($folder),
                    static fn(Finding $f): bool => $f->level === Level::Warn,
                ),
            );
            $keyLock = array_values(array_filter(
                $messages,
                static fn(string $m): bool => str_contains($m, 'sets no key lock'),
            ));

            $this->assertNull($folder->key, 'an estimate must not become the key');
            $this->assertCount(1, $keyLock);
            $this->assertStringContainsString('which is a guess, not a reading', $keyLock[0]);
        } finally {
            self::remove($path);
        }
    }

    /**
     * A project that will not parse is a finding rather than an exception out of the command.
     *
     * @return void
     */
    public function testAProjectThatWillNotParseIsReportedNotThrown(): void
    {
        $path = sys_get_temp_dir() . '/neurosys-flp-' . bin2hex(random_bytes(6));

        mkdir($path);
        file_put_contents($path . '/broken.flp', 'not a project at all');

        try {
            $project = ProjectFile::in($path);

            $this->assertNotNull($project);
            $this->assertNull($project->project);
            $this->assertStringContainsString('no FLhd', (string) $project->error);
        } finally {
            self::remove($path);
        }
    }

    /**
     * A temporary folder holding one synthesised project.
     *
     * @param bool $keyLock
     * @return string
     */
    private static function folderWithProject(bool $keyLock = true): string
    {
        $path = sys_get_temp_dir() . '/neurosys-flp-' . bin2hex(random_bytes(6));

        mkdir($path);
        file_put_contents($path . '/project.flp', self::project($keyLock));
        // Preflight stops at "no FLAC" before it reaches the project, so the fixture needs one to
        // exist. It stays empty: Probe answers an unreadable file with no tags, which is the same
        // shape as a master that was never tagged and is exactly the case under test.
        touch($path . '/t.flac');

        return $path;
    }

    /**
     * A project file carrying a tempo, a genre, a title and optionally a locked key.
     *
     * @param bool $keyLock
     * @return string
     */
    private static function project(bool $keyLock): string
    {
        $utf16 = static fn(string $text): string => mb_convert_encoding($text, 'UTF-16LE', 'UTF-8') . "\0\0";

        // The length is seven bits at a time, not one byte: the note block below is 240 bytes, and
        // a single chr() of that sets the continuation bit and derails the whole walk.
        $text = static function (int $id, string $value): string {
            $length = strlen($value);
            $varInt = '';

            do {
                $septet  = $length & 0x7F;
                $length >>= 7;
                $varInt .= chr($length > 0 ? $septet | 0x80 : $septet);
            } while ($length > 0);

            return chr($id) . $varInt . $value;
        };

        $events = chr(156) . pack('V', 140000)                      // tempo
            . $text(194, $utf16('ill'))                             // title
            . $text(206, $utf16('Dubstep'));                        // genre

        // An hour spent, in the second of the timestamp's two doubles.
        $events .= $text(237, pack('d', 46213.9) . pack('d', 1 / 24));

        // Two structure markers, which become the arrangement.
        $events .= chr(148) . pack('V', 0) . chr(46) . chr(0) . $text(205, $utf16('INTRO'))
            . chr(148) . pack('V', 12288) . chr(46) . chr(0) . $text(205, $utf16('DROP'));

        if ($keyLock) {
            $events .= chr(148) . pack('V', (0x0C << 24))           // a scale marker at tick 0
                . chr(46) . chr(3)                                  // rooted on D#
                . $text(205, $utf16('D# Minor Natural (Aeolian)'));
        } else {
            // Notes enough for an estimate to be confident about, and nothing locking a key.
            $notes = '';

            foreach ([6, 10, 1, 6, 10, 1, 8, 11, 3, 5] as $key) {
                $notes .= pack('V', 0) . pack('vv', 0, 0) . pack('V', 96) . chr($key) . str_repeat("\0", 11);
            }

            $events .= $text(224, $notes);
        }

        return 'FLhd' . pack('V', 6) . pack('vvv', 0, 26, 96)
            . 'FLdt' . pack('V', strlen($events)) . $events;
    }

    /**
     * @param string $path
     * @return void
     */
    private static function remove(string $path): void
    {
        array_map(unlink(...), glob($path . '/*') ?: []);
        rmdir($path);
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
        $folder = $this->folder(audio: [ReleaseFormat::FLAC->value => '/x/ill..flac']);
        $php    = EntryWriter::write($folder);

        // The imports come from the writer rather than being typed here, which makes this a test of
        // two things at once: `use` aliases do not cross into eval(), so a class the writer names
        // short and forgets to report is a parse error right here.
        $releases = eval(self::evaluable($folder, $php));

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
