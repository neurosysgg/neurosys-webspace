<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use ArrayObject;
use DateTime;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Support\SearchableCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

#[CoversClass(Collection::class)]
#[CoversClass(SearchableCollection::class)]
#[CoversClass(File::class)]
#[CoversClass(Directory::class)]
final class SupportTest extends TestCase
{
    // ───────────────────────────── Collection ─────────────────────────────

    /**
     * @return void
     */
    public function testStartsEmpty(): void
    {
        $collection = new Collection(stdClass::class);

        self::assertCount(0, $collection);
        self::assertSame([], $collection->all());
    }

    /**
     * @return void
     */
    public function testAddsItemsAndPreservesOrder(): void
    {
        $a = new stdClass();
        $b = new stdClass();

        $collection = new Collection(stdClass::class)->with($a, $b);

        self::assertCount(2, $collection);
        self::assertSame([$a, $b], $collection->all());
    }

    /**
     * @return void
     */
    public function testWithReturnsACopyAndLeavesTheOriginalEmpty(): void
    {
        $collection = new Collection(stdClass::class);
        $extended   = $collection->with(new stdClass());

        self::assertNotSame($collection, $extended);
        self::assertCount(0, $collection);
        self::assertCount(1, $extended);
    }

    /**
     * The reason the collections are immutable: readonly protects the reference, not what it points
     * at. A mutable collection would make every readonly value object holding one appendable by
     * anyone who can reach it — Release::$formats, Terminal::$fields, SoundCloudEmbed::$options.
     *
     * @return void
     */
    public function testACollectionInsideAReadonlyObjectCannotBeAppendedTo(): void
    {
        $release = new Release(
            title:       'ill.',
            bpm:         140,
            key:         MusicalKey::FSharpMajor,
            genre:       Genre::Dubstep,
            description: 'debut single',
            cover:       null,
            formats:     new Collection(Format::class)->with(new Format(ReleaseFormat::FLAC)),
        );

        (void) $release->formats->with(new Format(ReleaseFormat::MP3));

        self::assertCount(1, $release->formats);
    }

    /**
     * @return void
     */
    public function testIsIterable(): void
    {
        $items = [new stdClass(), new stdClass()];

        self::assertSame($items, iterator_to_array(new Collection(stdClass::class)->with(...$items)));
    }

    /**
     * @return void
     */
    public function testRejectsAnItemOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        (void) new Collection(DateTime::class)->with(new stdClass());
    }

    /**
     * @return void
     */
    public function testRejectsAScalar(): void
    {
        $this->expectException(TypeError::class);
        (void) new Collection(stdClass::class)->with('not an object');
    }

    /**
     * @return void
     */
    public function testTheTypeErrorNamesBothTypes(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains(DateTime::class);
        (void) new Collection(DateTime::class)->with(new stdClass());
    }

    /**
     * The copy is discarded with the exception, so the good items in a bad batch go with it.
     *
     * @return void
     */
    public function testARejectedBatchLeavesTheOriginalUntouched(): void
    {
        $collection = new Collection(stdClass::class)->with(new stdClass());

        try {
            (void) $collection->with(new stdClass(), 'not an object');
        } catch (TypeError) {
            // expected
        }

        self::assertCount(1, $collection);
    }

    /**
     * @return void
     */
    public function testAcceptsSubclassesOfTheDeclaredType(): void
    {
        $collection = new Collection(ArrayObject::class)->with(new class () extends ArrayObject {});

        self::assertCount(1, $collection);
    }

    /**
     * @return void
     */
    public function testExposesItsDeclaredType(): void
    {
        self::assertSame(stdClass::class, new Collection(stdClass::class)->type);
    }

    // ───────────────────────── SearchableCollection ─────────────────────────

    /**
     * @return void
     */
    public function testFindReturnsNullForAnUnknownKey(): void
    {
        self::assertNull(new SearchableCollection(stdClass::class)->find('nope'));
    }

    /**
     * @return void
     */
    public function testFindReturnsTheItemStoredUnderAKey(): void
    {
        $item = new stdClass();

        self::assertSame($item, new SearchableCollection(stdClass::class)->with('k', $item)->find('k'));
    }

    /**
     * @return void
     */
    public function testAddingTheSameKeyTwiceReplacesTheItem(): void
    {
        $second = new stdClass();

        $collection = new SearchableCollection(stdClass::class)
            ->with('k', new stdClass())
            ->with('k', $second);

        self::assertCount(1, $collection);
        self::assertSame($second, $collection->find('k'));
    }

    /**
     * @return void
     */
    public function testIteratesAsKeyValuePairs(): void
    {
        $a = new stdClass();
        $b = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->with('a', $a)->with('b', $b);

        self::assertSame(['a' => $a, 'b' => $b], iterator_to_array($collection));
    }

    /**
     * @return void
     */
    public function testSearchableRejectsAnItemOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        (void) new SearchableCollection(DateTime::class)->with('k', new stdClass());
    }

    /**
     * @return void
     */
    public function testKeysWithSlashesAndDotsAreJustKeys(): void
    {
        $item = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->with('../../etc/passwd', $item);

        self::assertSame($item, $collection->find('../../etc/passwd'));
        self::assertNull($collection->find('etc/passwd'));
    }

    /**
     * all() hands back the keyed map rather than a list — the slug is the key, and it is what
     * ReleasesView iterates to build each card's href.
     *
     * @return void
     */
    public function testASearchableCollectionHandsBackItsItemsKeyed(): void
    {
        $a = new stdClass();
        $b = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->with('a', $a)->with('b', $b);

        self::assertSame(['a' => $a, 'b' => $b], $collection->all());
    }

    /**
     * @return void
     */
    public function testAnEmptySearchableCollectionHandsBackAnEmptyArray(): void
    {
        self::assertSame([], new SearchableCollection(stdClass::class)->all());
    }
    // ───────────────────────────── File and Directory ─────────────────────────────

    /**
     * Absent and unreadable are different causes and the same answer.
     *
     * This is the case the class was written for: `is_file()` guards the first and does nothing
     * about the second, so `file_get_contents()` warned — and on the live host that warning printed
     * into the page ahead of the doctype, because the headers had already gone out. See
     * {@link \NeuroSYS\Controller\PrivacyController}.
     *
     * @return void
     */
    public function testAFileThatCannotBeReadAnswersNullRatherThanWarning(): void
    {
        $directory = Directory::temporary('neurosys-support-');

        try {
            self::assertNull($directory->file('never-written.txt')->read());
            self::assertFalse($directory->file('never-written.txt')->exists());
            self::assertSame([], $directory->file('never-written.txt')->lines());
            self::assertSame(0, $directory->file('never-written.txt')->size());
        } finally {
            $directory->remove();
        }
    }

    /**
     * @return void
     */
    public function testAFileIsWrittenReadBackAndRemoved(): void
    {
        $directory = Directory::temporary('neurosys-support-');
        $file      = $directory->file('note.txt');

        try {
            self::assertTrue($file->write("one\ntwo\n"));
            self::assertSame("one\ntwo\n", $file->read());
            self::assertSame(['one', 'two'], $file->lines());
            self::assertSame(8, $file->size());
            self::assertSame('note.txt', $file->name());
            self::assertSame('txt', $file->extension());
            self::assertTrue($file->delete());
            self::assertFalse($file->exists());
        } finally {
            $directory->remove();
        }
    }

    /**
     * Deleting a file that was never there is a success: the postcondition is what is asked for.
     *
     * @return void
     */
    public function testDeletingSomethingThatIsNotThereSucceeds(): void
    {
        self::assertTrue(new File('/x/never-existed.txt')->delete());
    }

    /**
     * @return void
     */
    public function testAnExtensionIsLowerCasedAndAFileWithoutOneHasNone(): void
    {
        self::assertSame('flac', new File('/x/ILL..FLAC')->extension());
        self::assertSame('', new File('/x/README')->extension());
    }

    /**
     * The one thing this class refuses to do, and the history that decided it: a logger that
     * created its own directory was deployed once, had to be reverted, and the directory it had
     * already made on the server had to be deleted by hand. Writing into a directory that is not
     * there fails, and the caller asks {@link Directory::create()} when it means to.
     *
     * @return void
     */
    public function testWritingIntoADirectoryThatIsNotThereFailsRatherThanCreatingIt(): void
    {
        $directory = Directory::temporary('neurosys-support-');
        $missing   = $directory->directory('nope');

        try {
            self::assertFalse($missing->file('x.txt')->write('anything'));
            self::assertFalse($missing->file('x.txt')->append('anything'));
            self::assertFalse($missing->exists());
        } finally {
            $directory->remove();
        }
    }

    /**
     * @return void
     */
    public function testAppendingAddsWholeLinesAndCreatesTheFile(): void
    {
        $directory = Directory::temporary('neurosys-support-');
        $log       = $directory->file('downloads.log');

        try {
            self::assertTrue($log->append('{"slug":"ill"}'));
            self::assertTrue($log->append('{"slug":"hello-world"}'));
            self::assertSame(['{"slug":"ill"}', '{"slug":"hello-world"}'], $log->lines());
        } finally {
            $directory->remove();
        }
    }

    /**
     * @return void
     */
    public function testAModeIsAppliedBeforeTheContentsAreReachable(): void
    {
        $directory = Directory::temporary('neurosys-support-');
        $file      = $directory->file('token.json');

        try {
            self::assertTrue($file->write('{}', 0o600));
            self::assertSame('0600', substr(sprintf('%o', fileperms($file->path)), -4));
        } finally {
            $directory->remove();
        }
    }

    /**
     * @return void
     */
    public function testADirectoryListsItsOwnFilesAndNotItsSubdirectories(): void
    {
        $directory = Directory::temporary('neurosys-support-');

        try {
            $directory->file('a.flac')->write('');
            $directory->file('b.wav')->write('');
            $directory->directory('web')->create();

            self::assertSame(
                ['a.flac', 'b.wav'],
                array_map(static fn(File $f): string => $f->name(), $directory->files()),
            );
            self::assertSame(
                ['a.flac'],
                array_map(static fn(File $f): string => $f->name(), $directory->files('*.flac')),
            );
        } finally {
            $directory->directory('web')->remove();
            $directory->remove();
        }
    }

    /**
     * It removes what it holds and itself, and refuses to descend — a recursive delete is not a
     * thing this repository needs, and not a thing to have lying around.
     *
     * @return void
     */
    public function testRemovingADirectoryWithASubdirectoryInItRefusesRatherThanRecursing(): void
    {
        $directory = Directory::temporary('neurosys-support-');
        $nested    = $directory->directory('web');

        $nested->create();
        $nested->file('cover.jpg')->write('');

        self::assertFalse($directory->remove());
        self::assertTrue($directory->exists());
        self::assertTrue($nested->file('cover.jpg')->exists(), 'nothing inside it was touched');

        $nested->remove();
        $directory->remove();
    }

    /**
     * @return void
     */
    public function testATemporaryDirectoryIsCreatedForItsOwnerAndIsUniquePerCall(): void
    {
        $one = Directory::temporary('neurosys-support-');
        $two = Directory::temporary('neurosys-support-');

        try {
            self::assertTrue($one->exists());
            self::assertNotSame($one->path, $two->path);
            self::assertSame('0700', substr(sprintf('%o', fileperms($one->path)), -4));
        } finally {
            $one->remove();
            $two->remove();
        }
    }

    /**
     * A write that cannot be put into place leaves nothing behind — not the temporary file either.
     *
     * Reached by aiming at a name a directory already has: `rename()` will not replace a directory
     * with a file. It is the same failure as a full disk or a read-only mount, which is what this
     * branch is really for.
     *
     * @return void
     */
    public function testAWriteThatCannotBeRenamedIntoPlaceLeavesNothingBehind(): void
    {
        $directory = Directory::temporary('neurosys-support-');
        $occupied  = $directory->directory('taken');

        $occupied->create();
        $occupied->file('inside.txt')->write('');

        try {
            self::assertFalse($directory->file('taken')->write('anything'));
            self::assertSame([], $directory->files('taken.*'), 'the temporary file was cleaned up');
        } finally {
            $occupied->remove();
            $directory->remove();
        }
    }

    /**
     * Removing what is not there is a success: the postcondition is what is being asked for.
     *
     * @return void
     */
    public function testRemovingADirectoryThatIsNotThereSucceeds(): void
    {
        self::assertTrue(new Directory('/x/never-existed')->remove());
    }

    /**
     * A file it cannot remove stops it, rather than leaving it to fail at the `rmdir()`.
     *
     * @return void
     */
    public function testADirectoryThatCannotBeEmptiedAnswersFalse(): void
    {
        $directory = Directory::temporary('neurosys-support-');

        $directory->file('locked.txt')->write('');
        chmod($directory->path, 0o500);

        try {
            if (is_writable($directory->path)) {
                self::markTestSkipped('this process can write to a read-only directory');
            }

            self::assertFalse($directory->remove());
            self::assertTrue($directory->exists());
        } finally {
            chmod($directory->path, 0o700);
            $directory->remove();
        }
    }

    /**
     * @return void
     */
    public function testAFileKnowsWhichDirectoryItIsIn(): void
    {
        self::assertSame('/x/web', new File('/x/web/cover.jpg')->directory()->path);
    }
}
