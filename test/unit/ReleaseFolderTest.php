<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use InvalidArgumentException;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Production\Plugin;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Service\Data\ReleaseCodec;
use NeuroSYS\Site;
use NeuroSYS\Tool\Command\StageRelease;
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
use Phpanta\Support\Collection;
use Phpanta\Support\Directory;
use Phpanta\Support\File;
use Phpanta\Support\SearchableCollection;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use Phpanta\Tool\Php\Argument;
use Phpanta\Tool\Php\Call;
use Phpanta\Tool\Php\ClassConstant;
use Phpanta\Tool\Php\Value;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `tools/lib/Release/` — reading a folder, and the entry `stage-release` prints out of it.
 *
 * **The line is drawn at the shell-outs, not at the disk.** What reads a folder's *tags* runs
 * `metaflac` and `ffprobe`, which is not something a unit test should reach for; that half is
 * exercised by running the tool, and it is why the fixtures below can leave `t.flac` empty — an
 * unreadable file and a missing tool produce the same empty answer, which is exactly the case the
 * fallback ladders are for. Everything else here is a real folder in `sys_get_temp_dir()`, because
 * the project is found on disk and the cover is chosen by looking.
 *
 * What is worth pinning is the part that fails *silently*: an unresolved key is a null, and a null
 * key is a staged release with no key rather than an error anybody sees. See docs/authoring.md.
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
        $files = new SearchableCollection(File::class);

        foreach ($audio as $format => $path) {
            $files = $files->with($format, new File($path));
        }

        return new ReleaseFolder(
            directory: new Directory('/x'),
            master:    new File('/x/ill..flac'),
            title:     'ill.',
            bpm:       140,
            key:       MusicalKey::DSharpMinor,
            genre:     Genre::Dubstep,
            cover:     $cover,
            date:      '2026-09-04',
            audio:     $files,
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
                $formats->toArray(),
            );
        } finally {
            array_map(unlink(...), glob($path . '/*') ?: []);
            rmdir($path);
        }
    }

    /**
     * The entry a project produces reads back through the site's own codec, arrangement and all.
     *
     * The half-state is the thing worth checking here: `madeWith` is **not written**, only listed
     * among the notes, so a release staged this way has an arrangement and a time spent and no
     * credits until a person has been through the candidate list. See {@link \NeuroSYS\Tool\Flp\Plugins}.
     *
     * @return void
     */
    public function testAnEntryWithAProjectReadsBackWithItsArrangement(): void
    {
        $path = self::folderWithProject();

        try {
            $release = self::readBack(ReleaseFolder::at($path));

            $this->assertSame(140, $release->bpm);
            $this->assertSame(MusicalKey::DSharpMinor, $release->key);
            $this->assertSame(
                ['INTRO', 'DROP'],
                $release->arrangement?->sections->map(static fn($s): string => $s->label)->toValues(),
            );
            $this->assertSame('1h 00m', $release->timeSpent?->render());
            $this->assertTrue($release->madeWith->isEmpty(), 'credits are left for a person');
        } finally {
            self::remove($path);
        }
    }

    /**
     * A leaf is written by `var_export()`, with the two exceptions that bit when it was not.
     *
     * `var_export(null)` is `NULL` in capitals — alone among the literals it emits, and unlike every
     * other null in `data/releases.json`. And an enum has to come out as the short `Genre::Dubstep`
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
     * This is what `var_export()` is there for, and what a heredoc with `%s` holes cannot do: a
     * release title is arbitrary text, and an apostrophe in one must not make a data file that
     * will not parse.
     *
     * @return void
     */
    public function testAValueThatWouldBreakTheSourceIsQuoted(): void
    {
        $this->assertSame("'don\\'t'", new Value("don't")->render());
        $this->assertSame("'a\\\\b'", new Value('a\\b')->render());
    }

    /**
     * A comment is a line, so a call written on one line refuses to carry one.
     *
     * Rendered, neither would be a call anybody meant. `Argument::comment()` would come out as
     * `new Format('a', , 'b')` — a syntax error, which at least announces itself. The commented-out
     * one would come out as `new Format('a', b: 'b')`, which **parses**: a line whose whole purpose
     * is to be uncommented later would go into `data/releases.json` as live code.
     *
     * @return void
     */
    public function testAnInlineCallRefusesAnArgumentThatIsMeantToBeCommented(): void
    {
        $inline = static fn(Argument $argument): Call => Call::create(
            Format::class,
            new Collection(Argument::class)->with(new Argument(new Value('a')), $argument),
        );

        // Stacked, the same two arguments are exactly what the half-state is written as.
        $this->assertStringContainsString(
            '// a note',
            Call::create(
                Format::class,
                new Collection(Argument::class)->with(Argument::comment('a note')),
                stacked: true,
            )->render(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be commented');

        $inline(Argument::pending(new Value('b'), 'b'))->render();
    }

    /**
     * The other half of it, which was the syntax error rather than the silent one.
     *
     * @return void
     */
    public function testAnInlineCallRefusesABareCommentBetweenItsArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be commented');

        Call::create(
            Format::class,
            new Collection(Argument::class)->with(new Argument(new Value('a')), Argument::comment('a note')),
        )->render();
    }

    /**
     * The entry `--print` puts on stdout, read back the way the site reads `data/releases.json`.
     *
     * @param ReleaseFolder $folder
     * @return Release
     */
    private static function readBack(ReleaseFolder $folder): Release
    {
        $release = ReleaseCodec::decode(EntryWriter::write($folder), 'the entry')->find('ill');
        self::assertInstanceOf(Release::class, $release);

        return $release;
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
            $project = ProjectFile::in(new Directory($path));

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
     * @param bool         $keyLock
     * @param list<string> $plugins Names to bury in wrapper blobs, for the credits candidate list.
     * @param int          $ppq     The project's pulse rate; 96 is what every project tested uses.
     * @param Source|null  $cover   Which rung of the cover ladder to prepare, or none at all.
     * @return string
     */
    private static function folderWithProject(
        bool $keyLock = true,
        array $plugins = [],
        int $ppq = 96,
        ?Source $cover = null,
    ): string {
        $path = sys_get_temp_dir() . '/neurosys-flp-' . bin2hex(random_bytes(6));

        mkdir($path);
        file_put_contents($path . '/project.flp', self::project($keyLock, $plugins, $ppq));

        if ($cover === Source::WebExport) {
            mkdir($path . '/web');
            file_put_contents($path . '/web/cover.jpg', '');
        }

        if ($cover === Source::FolderRoot) {
            file_put_contents($path . '/cover.png', '');
        }
        // Preflight stops at "no FLAC" before it reaches the project, so the fixture needs one to
        // exist. It stays empty: Probe answers an unreadable file with no tags, which is the same
        // shape as a master that was never tagged and is exactly the case under test.
        touch($path . '/t.flac');

        return $path;
    }

    /**
     * A project file carrying a tempo, a genre, a title and optionally a locked key.
     *
     * @param bool         $keyLock
     * @param list<string> $plugins
     * @param int          $ppq
     * @return string
     */
    private static function project(bool $keyLock, array $plugins = [], int $ppq = 96): string
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

        // A wrapper blob per plugin, three times over, because a name has to be seen three times
        // before Plugins offers it — see PluginsTest for why two is what a coincidence looks like.
        foreach ($plugins as $name) {
            $events .= $text(213, str_repeat(pack('P', strlen($name)) . $name, 3));
        }

        return 'FLhd' . pack('V', 6) . pack('vvv', 0, 26, $ppq)
            . 'FLdt' . pack('V', strlen($events)) . $events;
    }

    /**
     * @param string $path
     * @return void
     */
    private static function remove(string $path): void
    {
        foreach (glob($path . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                array_map(unlink(...), glob($entry . '/*') ?: []);
                rmdir($entry);

                continue;
            }

            unlink($entry);
        }

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
     * it is compared as an enum case rather than as a string on both sides.
     *
     * @return void
     */
    public function testACoverKnowsWhetherItIsTheWebExport(): void
    {
        $this->assertTrue(new Cover(new File('/x/web/ill. cover.jpg'), Source::WebExport)->isWebExport());
        $this->assertFalse(new Cover(new File('/x/ill..flac'), Source::EmbeddedPicture)->isWebExport());
        $this->assertSame('ill. cover.jpg', new Cover(new File('/x/web/ill. cover.jpg'), Source::WebExport)->name());
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
     * The entry is the shape `data/releases.json` already holds — the codec's own output — and what
     * a folder cannot supply is listed beside it, each note naming the file whose share id is wanted.
     *
     * @return void
     */
    public function testTheStagedEntryMatchesTheShapeOfTheDataFile(): void
    {
        $folder = $this->folder(
            audio: [
                ReleaseFormat::FLAC->value  => '/x/ill..flac',
                ReleaseFormat::STEMS->value => '/x/140 D#Min ill remix package.zip',
            ],
            cover: new Cover(new File('/x/web/ill. cover.jpg'), Source::WebExport),
        );
        $json = EntryWriter::write($folder);

        $this->assertStringStartsWith("{\n    \"ill\": {\n        \"title\": \"ill.\",\n", $json);
        $this->assertStringContainsString('"key": "D# Minor",', $json);
        $this->assertStringContainsString('"genre": "Dubstep",', $json);
        $this->assertStringContainsString("\"type\": \"flac\",\n                \"link\": null", $json);

        // The three facts a folder cannot supply are left as the staged states Release already models.
        $this->assertStringContainsString('"description": "",', $json);
        $this->assertStringContainsString('"cover": null,', $json);
        $this->assertStringNotContainsString('"embed"', $json);

        $notes = implode("\n", EntryWriter::notes($folder));
        $this->assertStringContainsString('cover: the share id for ill. cover.jpg', $notes);
        $this->assertStringContainsString('formats flac: the share id for ill..flac', $notes);
        $this->assertStringContainsString('formats stems: the share id for 140 D#Min ill remix package.zip', $notes);
        $this->assertStringContainsString('embed: the SoundCloud ids exist only once', $notes);
    }

    /**
     * The staged entry is not a draft — it has to load, and produce a renderable `Release`.
     *
     * @return void
     */
    public function testTheStagedEntryReadsBackAsARenderableRelease(): void
    {
        $release = self::readBack($this->folder(audio: [ReleaseFormat::FLAC->value => '/x/ill..flac']));

        $this->assertSame('ill.', $release->title);
        $this->assertSame(140, $release->bpm);
        $this->assertSame(MusicalKey::DSharpMinor, $release->key);
        $this->assertNull($release->cover);
        $this->assertNull($release->embed);

        // A format with no link is the staged state: the card renders, and clicking it 503s.
        $this->assertNull($release->findFormat(ReleaseFormat::FLAC)?->link);
    }

    /**
     * **Credits are listed for a person to trim, never written.**
     *
     * The candidate list is scraped out of the project's plugin blobs rather than stated by the
     * format, so it arrives with wreckage in it — see {@link \NeuroSYS\Tool\Flp\Plugins}. Listing
     * it saves retyping; keeping it out of the entry is what keeps a guess off the site, the way
     * `description` is left for a person to fill in.
     *
     * @return void
     */
    public function testCreditsAreListedForAPersonToTrimRatherThanApplied(): void
    {
        $path = self::folderWithProject(plugins: ['Serum 2', 'Kilohearts']);

        try {
            $folder = ReleaseFolder::at($path);

            $this->assertStringNotContainsString('madeWith', EntryWriter::write($folder));
            $this->assertStringContainsString(
                'madeWith: candidates the project names — credit the ones worth crediting: Serum 2, Kilohearts',
                implode("\n", EntryWriter::notes($folder)),
            );
            $this->assertTrue(self::readBack($folder)->madeWith->isEmpty());
        } finally {
            self::remove($path);
        }
    }

    /**
     * A project with no plugins to credit writes no `madeWith` at all, commented or otherwise.
     *
     * @return void
     */
    public function testAProjectWithNothingToCreditWritesNoCreditsLine(): void
    {
        $path = self::folderWithProject();

        try {
            $this->assertStringNotContainsString('madeWith', EntryWriter::write(ReleaseFolder::at($path)));
        } finally {
            self::remove($path);
        }
    }

    /**
     * The pulse rate is written with the ticks it qualifies.
     *
     * A tick is meaningless without it — a section at 12288 is a position at the project's ppq, not
     * a time — so the arrangement carries it, the usual 96 included.
     *
     * @return void
     */
    public function testThePulseRateIsWrittenWithTheTicks(): void
    {
        $usual = self::folderWithProject();

        try {
            $this->assertStringContainsString('"ppq": 96,', EntryWriter::write(ReleaseFolder::at($usual)));
        } finally {
            self::remove($usual);
        }

        $unusual = self::folderWithProject(ppq: 192);

        try {
            $this->assertSame(192, self::readBack(ReleaseFolder::at($unusual))->arrangement?->ppq);
        } finally {
            self::remove($unusual);
        }
    }

    /**
     * Staging writes the entry into the releases file, first, and says what is left to fill in —
     * and a second run for the same slug is refused with the file as it was, since the entry there
     * carries whatever was typed into it since.
     *
     * @return void
     */
    public function testAFolderThatPassesIsWrittenFirstAndNeverOverwritten(): void
    {
        $path     = self::folderWithProject(cover: Source::WebExport);
        $fixtures = Directory::temporary('neurosys-stage-release-');
        $file     = $fixtures->file('releases.json');
        $file->write("{\n    \"older\": {\n        \"title\": \"older\",\n        \"bpm\": 120,\n"
            . "        \"key\": \"C Major\",\n        \"genre\": \"Dubstep\",\n        \"description\": \"\",\n"
            . "        \"cover\": null,\n        \"formats\": []\n    }\n}\n");

        try {
            [$code, $out, $report] = self::stage(new StageRelease($file), [$path]);

            $this->assertSame(ExitCode::Success, $code);
            $this->assertSame('', $out);
            $this->assertStringContainsString("wrote 'ill' into", $report);
            $this->assertStringContainsString('still to fill in by hand', $report);
            $this->assertSame(
                ['ill', 'older'],
                ReleaseCodec::decode((string) $file->read(), 'x')->toKeys(),
                'newest first',
            );

            $written = $file->read();
            [$again, , $refusal] = self::stage(new StageRelease($file), [$path]);

            $this->assertSame(ExitCode::Failure, $again);
            $this->assertStringContainsString("'ill' is in", $refusal);
            $this->assertSame($written, $file->read());
        } finally {
            self::remove($path);
            $fixtures->remove();
        }
    }

    /**
     * Runs a command against in-memory streams.
     *
     * @param StageRelease $command
     * @param list<string> $arguments
     * @return array{ExitCode, string, string} status, stdout, stderr
     */
    private static function stage(StageRelease $command, array $arguments): array
    {
        $out   = fopen('php://memory', 'rw+');
        $error = fopen('php://memory', 'rw+');

        $code = Runner::execute($command, $arguments, new Output($out, $error));

        rewind($out);
        rewind($error);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($error)];
    }

    /**
     * The cover is looked for in three places, in the order of how ready each one is to publish.
     *
     * A `web/` export is a pair somebody made for this; an image at the folder root is the working
     * artwork, usually the 19MB master; the FLAC's own embedded picture is the last resort, which is
     * where `hello world!` kept its only copy until one was exported. The order is what `Preflight`
     * then turns into an OK or a "export a web/ pair" WARN, so getting it backwards would recommend
     * uploading the master.
     *
     * The third rung needs `metaflac` to say the FLAC carries a picture and is exercised by running
     * the tool, the way the rest of the tag ladder is.
     *
     * @param Source $prepared
     * @param string $expected
     * @return void
     */
    #[DataProvider('coverRungs')]
    public function testTheCoverIsTakenFromTheReadiestPlaceItIsFound(Source $prepared, string $expected): void
    {
        $path = self::folderWithProject(cover: $prepared);

        try {
            $folder = ReleaseFolder::at($path);

            $this->assertSame($prepared, $folder->sourceOf(Fact::Cover));
            $this->assertSame($prepared, $folder->cover?->source);
            $this->assertSame($expected, $folder->cover?->name());
        } finally {
            self::remove($path);
        }
    }

    /**
     * @return array<string, array{Source, string}>
     */
    public static function coverRungs(): array
    {
        return [
            'a web/ export'            => [Source::WebExport, 'cover.jpg'],
            'artwork at the root'      => [Source::FolderRoot, 'cover.png'],
        ];
    }

    /**
     * **A folder that fails its checks prints no entry, and that is the whole point of the gate.**
     *
     * `stage-release` writes the entry to stdout and everything else to stderr, so the intended use
     * is `> entry.php` or a paste out of the terminal. An entry printed for a folder with a failing
     * check is one somebody pastes into `data/releases.json` — at which point the release is on the
     * site and the discrepancy is in it. So the exit code is not the safeguard; the empty stdout is.
     *
     * @return void
     */
    public function testAFolderThatFailsItsChecksPrintsNoEntryToPasteAnywhere(): void
    {
        $path = self::folderWithProject();

        try {
            $out   = fopen('php://memory', 'rw+');
            $error = fopen('php://memory', 'rw+');

            $code = Runner::execute(
                new StageRelease(new File('/nonexistent/releases.json')),
                [$path],
                new Output($out, $error),
            );

            rewind($out);
            rewind($error);

            $this->assertSame(ExitCode::Failure, $code);
            $this->assertSame('', stream_get_contents($out));
            $this->assertStringContainsString('1 check(s) failed', (string) stream_get_contents($error));
        } finally {
            self::remove($path);
        }
    }

    /**
     * With `--print`, a folder that passes puts the entry on stdout and the report on stderr, and
     * writes nothing.
     *
     * @return void
     */
    public function testWithPrintAFolderThatPassesPutsItsEntryOnStdoutAndItsReportOnStderr(): void
    {
        $path     = self::folderWithProject(cover: Source::WebExport);
        $fixtures = Directory::temporary('neurosys-stage-release-');
        $file     = $fixtures->file('releases.json');

        try {
            [$code, $entry, $report] = self::stage(new StageRelease($file), [$path, '--print']);

            $this->assertSame(ExitCode::Success, $code);
            $this->assertStringStartsWith("{\n    \"ill\": {", $entry);
            $this->assertStringContainsString('still to fill in by hand', $report);
            $this->assertFalse($file->exists(), '--print writes nothing');
        } finally {
            self::remove($path);
            $fixtures->remove();
        }
    }

    /**
     * `--check` stops after the report, which is what makes it safe to run on a folder mid-export.
     *
     * @return void
     */
    public function testCheckReportsAndStopsWithoutWritingAnEntry(): void
    {
        $path = self::folderWithProject(cover: Source::WebExport);

        try {
            $out   = fopen('php://memory', 'rw+');
            $error = fopen('php://memory', 'rw+');

            $code = Runner::execute(
                new StageRelease(new File('/nonexistent/releases.json')),
                [$path, '--check'],
                new Output($out, $error),
            );

            rewind($out);
            rewind($error);

            $this->assertSame(ExitCode::Success, $code);
            $this->assertSame('', stream_get_contents($out));
            $this->assertStringContainsString('key lock set', (string) stream_get_contents($error));
        } finally {
            self::remove($path);
        }
    }
}
