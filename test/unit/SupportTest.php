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
use NeuroSYS\Support\SearchableCollection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

#[CoversClass(Collection::class)]
#[CoversClass(SearchableCollection::class)]
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
}
