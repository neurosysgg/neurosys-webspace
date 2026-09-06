<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Model\Genre;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\Tool\Flp\EventId;
use NeuroSYS\Tool\Flp\MarkerType;
use NeuroSYS\Tool\Release\Cover;
use NeuroSYS\Tool\Release\Finding;
use NeuroSYS\Tool\Release\Level;
use NeuroSYS\Tool\Release\Preflight;
use NeuroSYS\Tool\Release\ProjectFile;
use NeuroSYS\Tool\Release\ReleaseFolder;
use NeuroSYS\Tool\Release\Source;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * `tools/lib/Release/Preflight` — the checks that run before anything reaches HiDrive.
 *
 * This is the half of the staging tool that earns its keep, because a share link is minted by hand
 * in a web UI and is bound to the bytes it was minted for: a file re-exported afterwards costs an
 * upload, a new link and an edit to `data/releases.php`. Every check in the class exists because a
 * real folder turned out to have that discrepancy in it.
 *
 * **Where this stops is where `ReleaseFolderTest` stops, and for the same reason.** `Preflight` has
 * five checks and one of them — `audio()` — compares what `ffprobe` says about each export against
 * the master. That needs real audio, which is not something a unit test should carry; it is
 * exercised by running the tool. The folders below have no readable master, so `Probe::stream()`
 * answers null and that check stands aside, leaving the other four to be asserted on their own.
 *
 * The two it leaves are worth the setup. `project()` compares the `.flp` against the tags exported
 * *from* it, and that is the only check here that can notice a master exported before the last
 * change — nothing else in a folder can. And `stems()` compares the zip that ships against the
 * loose folder beside it, which is a real zip and a real directory here rather than a stub, because
 * the thing being asserted is that the two are named the same way on both sides.
 */
final class PreflightTest extends TestCase
{
    /** Where the stems fixtures are written. */
    private Directory $directory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = Directory::temporary('neurosys-preflight-');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory->path . '/*', GLOB_ONLYDIR) ?: [] as $nested) {
            foreach (glob($nested . '/*', GLOB_ONLYDIR) ?: [] as $deeper) {
                new Directory($deeper)->remove();
            }

            new Directory($nested)->remove();
        }

        $this->directory->remove();
    }

    /**
     * A folder that resolves every required fact, so the fact ladder contributes no findings and
     * whatever a test is actually about is the only thing in the report.
     *
     * The master is named but not written: `Probe` answers an empty result for a file it cannot
     * read, which is the same answer it gives for a missing tool, and both are what the fallback
     * ladders already expect.
     *
     * @param ProjectFile|null                    $project
     * @param Cover|null                          $cover
     * @param SearchableCollection<File>|null     $audio
     * @return ReleaseFolder
     */
    private function folder(
        ?ProjectFile $project = null,
        ?Cover $cover = null,
        ?SearchableCollection $audio = null,
    ): ReleaseFolder {
        return new ReleaseFolder(
            directory:   $this->directory,
            master:      $this->directory->file('ill..flac'),
            title:       'ill.',
            bpm:         140,
            key:         MusicalKey::DSharpMinor,
            genre:       Genre::Dubstep,
            cover:       $cover ?? new Cover($this->directory->file('web/cover.jpg'), Source::WebExport),
            date:        '2026-09-04',
            audio:       $audio ?? new SearchableCollection(File::class),
            projectFile: $project,
        );
    }

    /**
     * The findings of one level, as their messages.
     *
     * @param list<Finding> $findings
     * @param Level         $level
     * @return list<string>
     */
    private static function at(array $findings, Level $level): array
    {
        return array_values(array_map(
            static fn(Finding $finding): string => $finding->message,
            array_filter($findings, static fn(Finding $finding): bool => $finding->level === $level),
        ));
    }

    /**
     * A project written into the fixture directory and read back the way the tool reads one.
     *
     * `ProjectFile`'s constructor is private and its factories read from disk, which is the right
     * shape for the class and means a fixture has to be a real file. It is eighteen bytes.
     *
     * @param string $events
     * @return ProjectFile
     */
    private function project(string $events): ProjectFile
    {
        $this->directory->file('ill..flp')->write(
            'FLhd' . pack('V', 6) . pack('vvv', 0, 26, 96)
            . 'FLdt' . pack('V', strlen($events)) . $events,
        );

        $found = ProjectFile::in($this->directory);

        self::assertNotNull($found, 'the fixture project should have been found');

        return $found;
    }

    /**
     * @param int $bpm
     * @return string
     */
    private function tempo(int $bpm = 140): string
    {
        return chr(EventId::Tempo->value) . pack('V', $bpm * 1000);
    }

    /**
     * A scale marker, which is what a key lock in the piano roll comes out as.
     *
     * @param string $name
     * @return string
     */
    private function scaleMarker(string $name = 'D# Minor Natural (Aeolian)'): string
    {
        return chr(EventId::Marker->value) . pack('V', MarkerType::Scale->value << 24)
            . chr(EventId::MarkerRoot->value) . chr(3)
            . $this->variable(EventId::MarkerName->value, mb_convert_encoding($name, 'UTF-16LE', 'UTF-8') . "\0\0");
    }

    /**
     * Pattern notes, 24 bytes each, with the pitch at offset 12.
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

        return $this->variable(EventId::PatternNotes->value, $notes);
    }

    /**
     * @param int    $id
     * @param string $payload
     * @return string
     */
    private function variable(int $id, string $payload): string
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
     * With no master there is nothing to read a release out of, and nothing else is worth saying.
     *
     * The check stops rather than continuing, which is why this asserts the count: a folder with no
     * FLAC would otherwise also report a missing cover, missing facts and unreadable formats, and
     * bury the one finding that explains all of them.
     *
     * @return void
     */
    public function testAFolderWithNoMasterIsOneFindingAndNotFive(): void
    {
        $findings = Preflight::check(new ReleaseFolder(
            directory: $this->directory,
            master:    null,
            title:     null,
            bpm:       null,
            key:       null,
            genre:     null,
            cover:     null,
            date:      null,
            audio:     new SearchableCollection(File::class),
        ));

        self::assertCount(1, $findings);
        self::assertSame(Level::Fail, $findings[0]->level);
        self::assertStringContainsString('no FLAC in the folder', $findings[0]->message);
    }

    /**
     * @return void
     */
    public function testAFolderWithNoProjectIsWarnedAboutRatherThanFailed(): void
    {
        $findings = Preflight::check($this->folder());

        self::assertContains(
            'project: no .flp in the folder, so bpm, key and genre rest on the tags alone',
            self::at($findings, Level::Warn),
        );
        self::assertSame([], self::at($findings, Level::Fail));
    }

    /**
     * @return void
     */
    public function testAProjectThatWillNotParseIsReportedWithWhatWentWrong(): void
    {
        $this->directory->file('ill..flp')->write('this is not a project');

        $findings = Preflight::check($this->folder(ProjectFile::in($this->directory)));

        self::assertSame(
            ['project: ill..flp could not be read — not a .flp: no FLhd header'],
            self::at($findings, Level::Fail),
        );
    }

    /**
     * **The canary, and the only guard against a desynchronised read.**
     *
     * A `.flp` has no separators between events and no checksums, so a reader that mis-sizes one id
     * reads everything after it at the wrong offset — and re-synchronises a few hundred bytes later
     * on the zero high bytes of UTF-16 ASCII. The file then parses, ends exactly where it said it
     * would, and is simply missing whatever sat in the desynchronised stretch. Every project tested
     * carries a tempo across four FL Studio versions, so an absent one means that happened.
     *
     * @return void
     */
    public function testAProjectThatParsesWithNoTempoIsTreatedAsAMisReadRatherThanAsAProject(): void
    {
        $findings = Preflight::check($this->folder($this->project($this->scaleMarker())));
        $failures = self::at($findings, Level::Fail);

        self::assertCount(1, $failures);
        self::assertStringContainsString('parsed but carries no tempo', $failures[0]);
        self::assertStringContainsString('an event was sized wrongly', $failures[0]);

        // And the project check stops there: the key lock is set, and would otherwise report an
        // OK, but a project read at the wrong offset has no business being asked anything else
        // about itself. The checks either side of it carry on, which is why this looks for the
        // absence of one message rather than the absence of all of them.
        foreach ($findings as $finding) {
            self::assertStringNotContainsString('key lock set', $finding->message);
        }
    }

    /**
     * @return void
     */
    public function testAProjectWithAKeyLockPassesTheCheckThatAsksAboutOne(): void
    {
        $findings = Preflight::check($this->folder(
            $this->project($this->tempo() . $this->scaleMarker()),
        ));

        self::assertContains('project: ill..flp, key lock set', self::at($findings, Level::Ok));
        self::assertSame([], self::at($findings, Level::Fail));
    }

    /**
     * A project that locks nothing is warned about, and the estimate is offered as a sentence.
     *
     * Never as a value: the estimate agreed with three of the four projects whose key is
     * independently known, which is worth saying to a person and not worth writing into
     * `data/releases.php` unasked.
     *
     * @return void
     */
    public function testAProjectWithNoKeyLockIsOfferedItsEstimateAsProseAndNotAsAValue(): void
    {
        $findings = Preflight::check($this->folder($this->project(
            $this->notes([0, 4, 7, 0, 4, 7, 2, 5, 9, 11]) . $this->tempo(),
        )));

        $warnings = self::at($findings, Level::Warn);

        self::assertCount(1, $warnings);
        self::assertStringContainsString('sets no key lock in the piano roll', $warnings[0]);
        self::assertStringContainsString('its notes suggest C Major', $warnings[0]);
        self::assertStringContainsString('over 10 notes', $warnings[0]);
        self::assertStringContainsString('which is a guess, not a reading', $warnings[0]);
    }

    /**
     * With nothing to estimate from, the warning says the one thing it knows and stops.
     *
     * @return void
     */
    public function testWithNoNotesToEstimateFromTheWarningOffersNoGuess(): void
    {
        $findings = Preflight::check($this->folder($this->project($this->tempo())));
        $warnings = self::at($findings, Level::Warn);

        self::assertCount(1, $warnings);
        self::assertSame('project: ill..flp sets no key lock in the piano roll', $warnings[0]);
    }

    /**
     * The zip is what ships; the loose folder beside it is scratch, and the two can drift.
     *
     * @return void
     */
    public function testAZipThatMatchesTheLooseFolderBesideItPasses(): void
    {
        $this->stems(['kick.wav' => 'kick', 'bass.wav' => 'bass'], ['kick.wav' => 'kick', 'bass.wav' => 'bass']);

        self::assertContains(
            'stems: the zip matches stems/, 2 files',
            self::at(Preflight::check($this->folderWithStems()), Level::Ok),
        );
    }

    /**
     * **A file in one and not the other is the whole reason this check exists.**
     *
     * The zip is what gets uploaded, so a stem added to the loose folder after the zip was built is
     * a stem nobody downloads — and there is no other symptom. The warning says which side ships.
     *
     * @return void
     */
    public function testAZipAndAFolderThatDisagreeAreReportedWithTheZipNamedAsWhatShips(): void
    {
        $this->stems(
            ['kick.wav' => 'kick'],
            ['kick.wav' => 'kick', 'lead.wav' => 'added after the zip was built'],
        );

        self::assertContains(
            'stems: the zip and stems/ disagree — the zip is what ships, so rebuild it',
            self::at(Preflight::check($this->folderWithStems()), Level::Warn),
        );
    }

    /**
     * Same names on both sides, different bytes — which a name-only comparison would call a match.
     *
     * @return void
     */
    public function testAStemReExportedSinceTheZipWasBuiltIsNoticed(): void
    {
        $this->stems(['kick.wav' => 'kick'], ['kick.wav' => 'kick, re-exported longer']);

        self::assertContains(
            'stems: the zip and stems/ disagree — the zip is what ships, so rebuild it',
            self::at(Preflight::check($this->folderWithStems()), Level::Warn),
        );
    }

    /**
     * @return void
     */
    public function testAZipWithNoLooseFolderHasNothingToDisagreeWith(): void
    {
        $this->stems(['kick.wav' => 'kick', 'bass.wav' => 'bass'], []);

        self::assertContains(
            'stems: 2 files in the zip, with no loose folder to disagree',
            self::at(Preflight::check($this->folderWithStems()), Level::Ok),
        );
    }

    /**
     * A zip that opens and holds nothing, which is not the same as one that will not open.
     *
     * The realistic way to make one is to zip a folder that turned out to be empty: the archive
     * carries a directory entry, {@link \NeuroSYS\Tool\Release\Probe::zipEntries()} drops it for
     * ending in a slash, and nothing is left. That used to reach the count below and read as
     * *`stems: 0 files in the zip, with no loose folder to disagree`* — an OK, because an empty zip
     * has no root to look for a loose folder under. The zip is what a stranger downloads.
     *
     * @return void
     */
    public function testAStemsZipHoldingNoFilesIsAFailureAndNotAQuietZero(): void
    {
        $archive = new ZipArchive();

        $archive->open($this->directory->file('stems.zip')->path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addEmptyDir('stems/');
        $archive->close();

        self::assertContains(
            'stems: stems.zip holds no files — the zip is what ships, so rebuild it',
            self::at(Preflight::check($this->folderWithStems()), Level::Fail),
        );
    }

    /**
     * @return void
     */
    public function testAStemsZipThatCannotBeReadIsNotComparedRatherThanCalledWrong(): void
    {
        $this->directory->file('stems.zip')->write('not an archive');

        self::assertContains(
            'stems: the zip could not be read, so it was not compared',
            self::at(Preflight::check($this->folderWithStems()), Level::Warn),
        );
    }

    /**
     * A folder with no stems at all is not asked about stems.
     *
     * @return void
     */
    public function testAFolderWithNoStemsIsSilentAboutThem(): void
    {
        foreach (Preflight::check($this->folder()) as $finding) {
            self::assertStringNotContainsString('stems', $finding->message);
        }
    }

    /**
     * @return void
     */
    public function testACoverPreparedForTheWebPasses(): void
    {
        self::assertContains(
            'cover: cover.jpg, prepared for the web',
            self::at(Preflight::check($this->folder()), Level::Ok),
        );
    }

    /**
     * The other two rungs are covers, and neither is artwork to hand to a service.
     *
     * @return void
     */
    public function testACoverThatIsNotTheWebExportSaysWhichRungItCameFrom(): void
    {
        $findings = Preflight::check($this->folder(cover: new Cover(
            $this->directory->file('artwork.png'),
            Source::FolderRoot,
        )));

        self::assertContains(
            'cover: folder root — export a web/ pair before uploading',
            self::at($findings, Level::Warn),
        );
    }

    /**
     * @return void
     */
    public function testAFolderWithNoCoverAnywhereIsAFailure(): void
    {
        $findings = Preflight::check(new ReleaseFolder(
            directory: $this->directory,
            master:    $this->directory->file('ill..flac'),
            title:     'ill.',
            bpm:       140,
            key:       MusicalKey::DSharpMinor,
            genre:     Genre::Dubstep,
            cover:     null,
            date:      '2026-09-04',
            audio:     new SearchableCollection(File::class),
        ));

        self::assertContains(
            'cover: no image anywhere in the folder, and none embedded in the FLAC',
            self::at($findings, Level::Fail),
        );
    }

    /**
     * A fact nothing in the folder supplies is named, and so is one that matched no case.
     *
     * The second is the more useful of the two: `'Dub Step'` in a GENRE tag is not a folder missing
     * its genre, it is a folder whose genre needs a case adding or a tag correcting, and a report
     * that said only "nothing supplies it" would send someone looking in the wrong place.
     *
     * @return void
     */
    public function testAnUnresolvedFactIsNamedWithWhatTheFolderActuallyHeld(): void
    {
        $findings = Preflight::check(new ReleaseFolder(
            directory: $this->directory,
            master:    $this->directory->file('ill..flac'),
            title:     'ill.',
            bpm:       null,
            key:       null,
            genre:     null,
            cover:     new Cover($this->directory->file('web/cover.jpg'), Source::WebExport),
            date:      null,
            audio:     new SearchableCollection(File::class),
            projectFile: null,
            raw:       ['genre' => 'Dub Step'],
        ));

        $failures = self::at($findings, Level::Fail);

        self::assertContains('bpm: nothing in the folder supplies it', $failures);
        self::assertContains("genre: 'Dub Step' matches no case — add one, or correct the tag", $failures);
    }

    /**
     * The folder the stems checks read, with the zip registered as the STEMS format.
     *
     * @return ReleaseFolder
     */
    private function folderWithStems(): ReleaseFolder
    {
        return $this->folder(audio: new SearchableCollection(File::class)->with(
            ReleaseFormat::STEMS->value,
            $this->directory->file('stems.zip'),
        ));
    }

    /**
     * A stems zip and the loose folder it is supposed to match.
     *
     * Both are real, because what is being asserted is that the walk is rooted where the zip is
     * rooted so both sides name their files the same way — which a pair of stubs would assume.
     *
     * @param array<string, string> $zipped
     * @param array<string, string> $loose
     * @return void
     */
    private function stems(array $zipped, array $loose): void
    {
        $archive = new ZipArchive();

        $archive->open($this->directory->file('stems.zip')->path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($zipped as $name => $contents) {
            $archive->addFromString('stems/' . $name, $contents);
        }

        $archive->close();

        if ($loose === []) {
            return;
        }

        $folder = $this->directory->directory('stems');

        $folder->create();

        foreach ($loose as $name => $contents) {
            $folder->file($name)->write($contents);
        }
    }
}
