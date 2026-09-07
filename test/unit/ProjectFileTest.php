<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Tool\Flp\EventId;
use NeuroSYS\Tool\Flp\FlpException;
use NeuroSYS\Tool\Flp\FlpFile;
use NeuroSYS\Tool\Flp\Project;
use NeuroSYS\Tool\Release\ProjectFile;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * `tools/lib/Release/ProjectFile` — finding the FL Studio project a release folder belongs to.
 *
 * **This is the half of the reader that touches disk**, which is why it is not in {@link FlpTest}
 * next to the parser and not in {@link ReleaseFolderTest}, whose docblock draws its line at the
 * parts that need no folder. Everything here writes into a temporary directory and takes it away
 * again, the way `SoundCloudTest` does with token files.
 *
 * Finding a project is its own small problem and the reason this class exists: a `.flp` references
 * its samples by absolute path, so it is not portable on its own and gets kept zipped — which means
 * the project belonging to a release is usually *inside* an archive beside it rather than next to
 * it. The order matters, the zip is read into memory rather than extracted, and a project that will
 * not parse has to arrive as a value rather than an exception, because every other thing that can
 * be wrong with a folder arrives as a {@link \NeuroSYS\Tool\Release\Finding}.
 *
 * The projects are assembled byte by byte for the reason {@link FlpTest} gives. A real one is
 * megabytes; the eighteen bytes below are a project with a tempo, which is all any of these
 * assertions needs to tell "it was read" from "something else was".
 */
final class ProjectFileTest extends TestCase
{
    /** Where every fixture in this file is written. */
    private Directory $directory;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = Directory::temporary('neurosys-project-');
    }

    /**
     * `Directory::remove()` deliberately refuses to descend, so a nested fixture comes apart in the
     * order it was built rather than by handing a tree to something that would delete it.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (glob($this->directory->path . '/*', GLOB_ONLYDIR) ?: [] as $nested) {
            new Directory($nested)->remove();
        }

        $this->directory->remove();
    }

    /**
     * A project whose only event is a tempo, so a parse either produced one or did not.
     *
     * @param int $bpm
     * @return string
     */
    private function flp(int $bpm = 140): string
    {
        $events = chr(EventId::Tempo->value) . pack('V', $bpm * 1000);

        return 'FLhd' . pack('V', 6) . pack('vvv', 0, 26, 96)
            . 'FLdt' . pack('V', strlen($events)) . $events;
    }

    /**
     * Writes a file into the fixture directory.
     *
     * @param string $name
     * @param string $contents
     * @return File
     */
    private function write(string $name, string $contents): File
    {
        $file = $this->directory->file($name);

        $file->write($contents);

        return $file;
    }

    /**
     * A zip holding the named entries, which is the shape FL's *Export project file* writes.
     *
     * @param string                $name
     * @param array<string, string> $entries Path inside the archive => contents.
     * @return File
     */
    private function zip(string $name, array $entries): File
    {
        $file    = $this->directory->file($name);
        $archive = new ZipArchive();

        $archive->open($file->path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $path => $contents) {
            $archive->addFromString($path, $contents);
        }

        $archive->close();

        return $file;
    }

    /**
     * A loose project is what the folder is asked for first.
     *
     * @return void
     */
    public function testALooseProjectIsRead(): void
    {
        $this->write('ill..flp', $this->flp());

        $found = ProjectFile::in($this->directory);

        self::assertNotNull($found);
        self::assertSame('ill..flp', $found->name);
        self::assertNull($found->error);
        self::assertSame(140.0, $found->project?->tempo);
    }

    /**
     * A project kept the way a project is actually kept: inside the archive beside the release.
     *
     * The archive is read **into memory**. Nothing is extracted, which is the decision worth
     * pinning — a staging tool that unpacked a 500MB archive to read 8MB out of the middle of it
     * would be doing something the rest of this layer never does, and the only visible evidence
     * either way is that the fixture directory still holds exactly what was put in it.
     *
     * @return void
     */
    public function testAProjectInsideAZipIsReadWithoutExtractingIt(): void
    {
        $this->zip('ill. [project].zip', ['ill. [project]/ill..flp' => $this->flp(174)]);

        $found = ProjectFile::in($this->directory);

        self::assertNotNull($found);
        self::assertSame(174.0, $found->project?->tempo);

        // The name is the project's own, not the path it sits at inside the archive.
        self::assertSame('ill..flp', $found->name);

        self::assertSame(
            ['ill. [project].zip'],
            $this->directory->files()->map(static fn(File $file): string => $file->name()),
        );
    }

    /**
     * @return void
     */
    public function testALooseProjectOutranksOneInAZipBesideIt(): void
    {
        $this->write('loose.flp', $this->flp(140));
        $this->zip('archived.zip', ['archived.flp' => $this->flp(174)]);

        $found = ProjectFile::in($this->directory);

        self::assertNotNull($found);
        self::assertSame('loose.flp', $found->name);
        self::assertSame(140.0, $found->project?->tempo);
    }

    /**
     * **A zero-byte file is not an archive, and is asked about before `ZipArchive` sees it.**
     *
     * As of PHP 8.5 handing one over is deprecated rather than merely false, and `phpunit.xml.dist`
     * sets `failOnDeprecation` — so the guard's absence would be *this test failing*, not a null
     * that reads the same. That is the whole reason it is a test rather than a comment: a
     * placeholder zip in a release folder is an ordinary thing to find, and the deprecation is
     * raised inside a command nobody runs under PHPUnit.
     *
     * @return void
     */
    public function testAZeroByteZipIsRefusedBeforeItReachesTheArchiveReader(): void
    {
        $this->write('empty.zip', '');

        self::assertNull(ProjectFile::in($this->directory));
    }

    /**
     * @return void
     */
    public function testAZipWithNoProjectInItIsNotAProjectFile(): void
    {
        $this->zip('stems.zip', ['stems/kick.wav' => 'not a project']);

        self::assertNull(ProjectFile::in($this->directory));
    }

    /**
     * @return void
     */
    public function testAFolderWithNothingInItHasNoProject(): void
    {
        self::assertNull(ProjectFile::in($this->directory));
    }

    /**
     * A project that will not parse is carried, not thrown.
     *
     * Every other thing that can be wrong with a folder arrives as a `Finding` and this one is not
     * special enough to end the command by itself — `Preflight` turns it into a FAIL naming the
     * file, which is more use than a stack trace.
     *
     * @return void
     */
    public function testAProjectThatWillNotParseIsCarriedAsAnError(): void
    {
        $this->write('broken.flp', 'this is not a project');

        $found = ProjectFile::in($this->directory);

        self::assertNotNull($found);
        self::assertSame('broken.flp', $found->name);
        self::assertNull($found->project);
        self::assertSame('not a .flp: no FLhd header', $found->error);
    }

    /**
     * `--project` pointing at a folder is the same question `in()` answers.
     *
     * @return void
     */
    public function testAProjectPathThatIsAFolderIsSearchedLikeOne(): void
    {
        $this->write('ill..flp', $this->flp());

        $found = ProjectFile::at($this->directory->path);

        self::assertNotNull($found);
        self::assertSame('ill..flp', $found->name);
    }

    /**
     * @return void
     */
    public function testAProjectPathNamingAFileReadsThatFile(): void
    {
        $file = $this->write('ill..flp', $this->flp(174));

        self::assertSame(174.0, ProjectFile::at($file->path)?->project?->tempo);
    }

    /**
     * @return void
     */
    public function testAProjectPathNamingAZipReadsTheProjectInside(): void
    {
        $zip = $this->zip('project.zip', ['nested/ill..flp' => $this->flp(128)]);

        self::assertSame(128.0, ProjectFile::at($zip->path)?->project?->tempo);
    }

    /**
     * **A named project that is not there answers null rather than falling back to the folder.**
     *
     * Which is the right way round: `--project` is a path somebody typed, so a path that is not
     * there is a typo — and silently reading the folder's own project instead would stage a release
     * from a different project than the one asked for, and say nothing.
     *
     * @return void
     */
    public function testANamedProjectThatIsNotThereDoesNotFallBackToTheFolder(): void
    {
        $this->write('ill..flp', $this->flp());

        self::assertNull(ProjectFile::at($this->directory->path . '/typo.flp'));
    }

    /**
     * A file that is neither a project nor an archive holds no project.
     *
     * @return void
     */
    public function testAFileThatIsNeitherAProjectNorAnArchiveHoldsNoProject(): void
    {
        $file = $this->write('notes.txt', 'bpm 140, key D#m');

        self::assertNull(ProjectFile::at($file->path));
    }

    /**
     * The two entry points that read a `.flp` off disk rather than out of bytes.
     *
     * `FlpFile::read()` is what every other test in this repository uses, because bytes are what
     * makes the parser testable at all. These two are the thin layer above it that the commands
     * actually call, and the only thing they add is the failure below.
     *
     * @return void
     */
    public function testAProjectIsReadFromDiskByBothEntryPoints(): void
    {
        $file = $this->write('ill..flp', $this->flp(150));

        self::assertSame(96, FlpFile::open($file)->ppq);
        self::assertSame(150.0, Project::open($file)->tempo);
    }

    /**
     * @return void
     */
    public function testAProjectThatCannotBeReadNamesThePathItLookedAt(): void
    {
        $missing = $this->directory->file('gone.flp');

        $this->expectException(FlpException::class);
        $this->expectExceptionMessage(sprintf('cannot read %s', $missing->path));

        FlpFile::open($missing);
    }
}
