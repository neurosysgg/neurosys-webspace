<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\Tool\Cli\ExitCode;
use NeuroSYS\Tool\Cli\Output;
use NeuroSYS\Tool\Cli\Runner;
use NeuroSYS\Tool\Command\ReleaseTrack;
use NeuroSYS\Tool\Command\ReleaseTrackOption;
use NeuroSYS\Tool\Export\ExportedAudio;
use NeuroSYS\Tool\Export\ExportException;
use NeuroSYS\Tool\Export\ExportSource;
use NeuroSYS\Tool\Export\FlStudioExport;
use NeuroSYS\Tool\Export\PreparedExport;
use NeuroSYS\Tool\Export\RenderFormat;
use NeuroSYS\Tool\Release\EntryWriter;
use NeuroSYS\Tool\Release\ReleaseFolder;
use NeuroSYS\Tool\Release\ReleasesFile;
use NeuroSYS\Tool\SoundCloud\CredentialVariable;
use PHPUnit\Framework\TestCase;

/**
 * `release-track`, and the export port underneath it — see docs/authoring.md.
 *
 * **Where this stops is the same place `ReleaseFolderTest` stops, and for the same reason.** The
 * command's uploading branch runs only on a folder that passes its preflight, and passing it means
 * real audio that `metaflac` and `ffprobe` can read — which is not something a unit test should
 * reach for. So the branch below it is covered where it lives, in `SoundCloudTest`, against a
 * transport that answers from an array; the entry the command prints is covered here at
 * `EntryWriter`, where it is generated; and what is left for the command itself is every path that
 * *refuses* — which is the half that decides whether anything is sent at all.
 */
final class ReleaseTrackTest extends TestCase
{
    /**
     * A folder with facts but no files, the way `ReleaseFolderTest` builds one.
     *
     * @return ReleaseFolder
     */
    private function folder(): ReleaseFolder
    {
        return new ReleaseFolder(
            directory: new Directory('/x'),
            master:    new File('/x/ill..flac'),
            title:     'ill.',
            bpm:       140,
            key:       MusicalKey::DSharpMinor,
            genre:     Genre::Dubstep,
            cover:     null,
            date:      '2026-09-04',
            audio:     new SearchableCollection(File::class)
                ->with(ReleaseFormat::FLAC->value, new File('/x/ill..flac')),
        );
    }

    /**
     * Runs the command and hands back its status and what it wrote to each stream.
     *
     * Not called `run()`, which `TestCase` declares final — a collision worth a sentence, since the
     * error it produces names neither this file's intent nor that method's.
     *
     * @param list<string> $arguments
     * @return array{ExitCode, string, string}
     */
    private function invoke(array $arguments): array
    {
        $out   = fopen('php://memory', 'r+');
        $error = fopen('php://memory', 'r+');

        $code = Runner::execute(new ReleaseTrack(), $arguments, new Output($out, $error));

        rewind($out);
        rewind($error);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($error)];
    }

    /**
     * A temporary folder that reads as a release and passes nothing.
     *
     * The empty FLAC is the same trick `ReleaseFolderTest` uses: `Preflight` stops at *no FLAC*
     * before it reaches anything else, so the fixture needs one to exist for the later checks to be
     * the ones that fail.
     *
     * @return string
     */
    private static function folderOnDisk(): Directory
    {
        $folder = Directory::temporary('neurosys-track-');

        $folder->file('t.flac')->write('');

        return $folder;
    }

    /**
     * @return void
     */
    public function testAPathThatIsNotAFolderIsAUsageError(): void
    {
        [$code, $out, $error] = $this->invoke([__FILE__]);

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('is not a folder', $error);
    }

    /**
     * @return void
     */
    public function testNoFolderAtAllIsAUsageError(): void
    {
        [$code, , $error] = $this->invoke([]);

        self::assertSame(ExitCode::Usage, $code);
        self::assertStringContainsString('usage: php tools/release-track.php', $error);
    }

    /**
     * A folder that fails its checks is not one to be uploading from.
     *
     * The assertion that matters is the empty stdout: no entry, and nothing sent. An upload is
     * bound to the bytes it was made from, the same way a HiDrive share link is.
     *
     * @return void
     */
    public function testAFolderThatFailsItsChecksIsRefusedWithNothingSent(): void
    {
        $path = self::folderOnDisk();

        try {
            [$code, $out, $error] = $this->invoke([$path->path, '--upload']);

            self::assertSame(ExitCode::Failure, $code);
            self::assertSame('', $out, 'a failing folder must not produce an entry');
            self::assertStringContainsString('check(s) failed', $error);
        } finally {
            $path->remove();
        }
    }

    /**
     * Without an app registered, the command says which three variables it is waiting for.
     *
     * @return void
     */
    public function testAuthorizingWithNoAppConfiguredNamesTheThreeVariables(): void
    {
        foreach (CredentialVariable::cases() as $variable) {
            putenv($variable->value);
        }

        [$code, $out, $error] = $this->invoke(['--authorize']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame('', $out);

        foreach (CredentialVariable::cases() as $variable) {
            self::assertStringContainsString($variable->value, $error);
        }

        self::assertStringContainsString('deploy.sh', $error, 'it should say why not data/');
    }

    /**
     * The absence pinned, because an absence is what nobody notices has gone.
     *
     * Sharing is decided in SoundCloud's own interface on the day, and a flag here would make
     * publishing a typo away. If a `--public` ever turns up, it has to be argued for in this test.
     *
     * @return void
     */
    public function testThereIsNoFlagThatCanPublishATrack(): void
    {
        self::assertSame(
            ['project', 'audio', 'upload', 'authorize'],
            array_map(static fn(ReleaseTrackOption $o): string => $o->flag(), ReleaseTrackOption::cases()),
        );
    }

    /**
     * @return void
     */
    public function testAPreparedFileIsReportedAsPreparedAndNotAsRendered(): void
    {
        $path = self::folderOnDisk();

        $path->file('ill..wav')->write('');

        try {
            $audio = new PreparedExport($path->file('ill..wav'))->export($this->folder(), RenderFormat::Wav);

            self::assertSame(ExportSource::Prepared, $audio->source);
            self::assertSame(RenderFormat::Wav, $audio->format);
            self::assertSame('ill..wav', $audio->name());
            self::assertSame('audio/wav', $audio->part()->type->render());

            // Named for the release, which is what the far end is told. The paragraph on `part()`
            // said so for as long as the only call site passed nothing, so the name that actually
            // went up was the working one the file happens to carry in the folder. The extension
            // is not the caller's to choose — it comes off `$format`, which nothing had asked.
            self::assertSame('ill..wav', $audio->part()->filename);
            self::assertSame('ill.wav', $audio->part('ill')->filename);
            self::assertSame('audio/wav', $audio->part('ill')->type->render());
        } finally {
            $path->remove();
        }
    }

    /**
     * With no file named, the folder answers — and the master is the last rung.
     *
     * @return void
     */
    public function testWithNoFileNamedTheFolderAnswersWithWhatItHas(): void
    {
        $path = self::folderOnDisk();

        try {
            $folder = ReleaseFolder::at($path->path);
            $audio  = new PreparedExport()->export($folder, RenderFormat::Wav);

            self::assertSame($path->file('t.flac')->path, $audio->file->path, 'the master is the fallback');
            self::assertSame(RenderFormat::Flac, $audio->format);
        } finally {
            $path->remove();
        }
    }

    /**
     * @return void
     */
    public function testAFolderWithNothingToUploadSaysSoRatherThanUploadingNothing(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/nothing to upload/');

        new PreparedExport()->export(
            new ReleaseFolder(
                new Directory('/x'),
                null,
                'ill.',
                140,
                null,
                null,
                null,
                null,
                new SearchableCollection(File::class),
            ),
            RenderFormat::Wav,
        );
    }

    /**
     * @return void
     */
    public function testAFileThatIsNotAudioIsRefusedWithTheFormatsThatWouldDo(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/wav, flac, mp3, ogg/');

        ExportedAudio::prepared(new File(__FILE__));
    }

    /**
     * @return void
     */
    public function testAFileThatIsNotThereIsRefusedBeforeAnythingElse(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/is not a file/');

        ExportedAudio::prepared(new File('/x/never-existed.wav'));
    }

    /**
     * The half of the FL exporter that is decided: what it would run.
     *
     * @return void
     */
    public function testTheFlStudioCommandLineIsTheOneHalfThatIsSettled(): void
    {
        self::assertSame(
            ['FL64.exe', '/R', '/Ewav', 'C:\\projects\\ill.flp'],
            FlStudioExport::commandLine('C:\\projects\\ill.flp', RenderFormat::Wav),
        );
    }

    /**
     * And the half that is not: it refuses, and says what has to be decided first.
     *
     * @return void
     */
    public function testTheFlStudioExporterRefusesAndSaysWhy(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/Windows VM/');

        new FlStudioExport()->export($this->folder(), RenderFormat::Wav);
    }

    /**
     * @return void
     */
    public function testEveryRenderFormatIsAFormatTheSiteHasANameFor(): void
    {
        foreach (RenderFormat::cases() as $format) {
            self::assertSame($format->value, $format->releaseFormat()->value);
            self::assertSame($format, RenderFormat::of(new File('/x/ill.' . $format->value)));
        }

        self::assertNull(RenderFormat::of(new File('/x/ill.zip')));
    }

    /**
     * The `embed:` line, written out rather than commented out, because an upload supplies its ids.
     *
     * @return void
     */
    public function testAnUploadedTrackTurnsTheCommentedOutEmbedIntoARealOne(): void
    {
        $php = EntryWriter::write($this->folder(), new SoundCloudEmbed(2394077313, 'ill', 's-dIMAqki109G'));

        self::assertStringNotContainsString('// embed:', $php);
        // Stacked, with the names in a column — the shape every player already in
        // `data/releases.php` is written in, so a generated one sits beside them unremarked.
        self::assertStringContainsString(
            "        embed: new SoundCloudEmbed(\n"
            . "            trackId:     2394077313,\n"
            . "            permalink:   'ill',\n"
            . "            secretToken: 's-dIMAqki109G',\n"
            . "        ),",
            $php,
        );

        $release = $this->evaluate($this->folder(), $php);

        self::assertSame(2394077313, $release->embed?->trackId);
        self::assertSame('ill', $release->embed?->permalink);
        self::assertSame('s-dIMAqki109G', $release->embed?->secretToken);
    }

    /**
     * Without one, the entry is exactly what it always was.
     *
     * @return void
     */
    public function testWithoutOneTheEmbedIsStillWrittenDownRatherThanWrittenOut(): void
    {
        $php = EntryWriter::write($this->folder());

        self::assertStringContainsString('// embed: new SoundCloudEmbed(', $php);
        self::assertStringContainsString('SoundCloud ids exist only once the track is uploaded', $php);
        self::assertNull($this->evaluate($this->folder(), $php)->embed);
    }

    /**
     * A public track has no token, and an argument holding `''` is not the same as no argument:
     * `SoundCloudEmbed::toElement()` sends no attribute at all for an empty one.
     *
     * @return void
     */
    public function testAPublicTracksEmbedIsWrittenWithoutASecretToken(): void
    {
        $php = EntryWriter::write($this->folder(), new SoundCloudEmbed(2394077313, 'ill'));

        self::assertStringNotContainsString('secretToken', $php);
        self::assertSame('', $this->evaluate($this->folder(), $php)->embed?->secretToken);
    }

    /**
     * @return void
     */
    public function testTheFileIsAskedWhichImportsItIsMissing(): void
    {
        $file = new ReleasesFile(new File(NEUROSYS_ROOT . '/data/releases.php'));

        self::assertSame([], $file->missingImports([Release::class, SoundCloudEmbed::class]));
        self::assertSame(
            ['NeuroSYS\Tool\Release\ReleasesFile'],
            $file->missingImports([Release::class, ReleasesFile::class]),
        );
    }

    /**
     * A file that cannot be read imports nothing, so everything is reported missing.
     *
     * @return void
     */
    public function testAFileThatCannotBeReadIsToldToImportEverything(): void
    {
        self::assertSame(
            [Release::class],
            new ReleasesFile(new File('/x/never-existed.php'))->missingImports([Release::class]),
        );
    }

    /**
     * The entry, evaluated the way `ReleaseFolderTest` evaluates one.
     *
     * @param ReleaseFolder $folder
     * @param string        $php
     * @return Release
     */
    private function evaluate(ReleaseFolder $folder, string $php): Release
    {
        $imports = array_map(
            static fn(string $class): string => sprintf('use %s;', $class),
            EntryWriter::imports($folder, new SoundCloudEmbed(1, 'x')),
        );

        // eval() is a language construct, so its result cannot be indexed where it stands.
        $releases = eval(implode('', $imports) . sprintf('return [%s];', $php));

        return $releases['ill'];
    }

    /**
     * What is about to be uploaded, in bytes, before it is.
     *
     * The size is reported to the operator rather than checked against anything: a master is tens
     * of megabytes over a domestic uplink, and the number is what makes a wrong file obvious in the
     * line before the transfer starts rather than in the minutes after it. A file that has gone
     * away since answers 0 rather than warning, which is `File::size()`'s decision and is why this
     * asserts both.
     *
     * @return void
     */
    public function testTheSizeOfWhatIsAboutToBeSentIsReportedBeforeSendingIt(): void
    {
        $path = self::folderOnDisk();

        try {
            $path->file('ill..wav')->write('forty-four bytes of entirely notional audio.');

            $audio = new PreparedExport($path->file('ill..wav'))->export($this->folder(), RenderFormat::Wav);

            self::assertSame(44, $audio->size());
            self::assertSame('ill..wav', $audio->name());

            $audio->file->delete();

            self::assertSame(0, $audio->size(), 'a file that has gone away is 0, not a warning');
        } finally {
            $path->remove();
        }
    }
}
