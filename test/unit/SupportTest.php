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

    public function testStartsEmpty(): void
    {
        $collection = new Collection(stdClass::class);

        self::assertCount(0, $collection);
        self::assertSame([], $collection->all());
    }

    public function testAddsItemsAndPreservesOrder(): void
    {
        $a = new stdClass();
        $b = new stdClass();

        $collection = new Collection(stdClass::class)->with($a, $b);

        self::assertCount(2, $collection);
        self::assertSame([$a, $b], $collection->all());
    }

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

        $release->formats->with(new Format(ReleaseFormat::MP3));

        self::assertCount(1, $release->formats);
    }

    public function testIsIterable(): void
    {
        $items = [new stdClass(), new stdClass()];

        self::assertSame($items, iterator_to_array(new Collection(stdClass::class)->with(...$items)));
    }

    public function testRejectsAnItemOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        new Collection(DateTime::class)->with(new stdClass());
    }

    public function testRejectsAScalar(): void
    {
        $this->expectException(TypeError::class);
        new Collection(stdClass::class)->with('not an object');
    }

    public function testTheTypeErrorNamesBothTypes(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage(DateTime::class);
        new Collection(DateTime::class)->with(new stdClass());
    }

    /** The copy is discarded with the exception, so the good items in a bad batch go with it. */
    public function testARejectedBatchLeavesTheOriginalUntouched(): void
    {
        $collection = new Collection(stdClass::class)->with(new stdClass());

        try {
            $collection->with(new stdClass(), 'not an object');
        } catch (TypeError) {
            // expected
        }

        self::assertCount(1, $collection);
    }

    public function testAcceptsSubclassesOfTheDeclaredType(): void
    {
        $collection = new Collection(ArrayObject::class)->with(new class () extends ArrayObject {});

        self::assertCount(1, $collection);
    }

    public function testExposesItsDeclaredType(): void
    {
        self::assertSame(stdClass::class, new Collection(stdClass::class)->type);
    }

    // ───────────────────────── SearchableCollection ─────────────────────────

    public function testFindReturnsNullForAnUnknownKey(): void
    {
        self::assertNull(new SearchableCollection(stdClass::class)->find('nope'));
    }

    public function testFindReturnsTheItemStoredUnderAKey(): void
    {
        $item = new stdClass();

        self::assertSame($item, new SearchableCollection(stdClass::class)->with('k', $item)->find('k'));
    }

    public function testAddingTheSameKeyTwiceReplacesTheItem(): void
    {
        $second = new stdClass();

        $collection = new SearchableCollection(stdClass::class)
            ->with('k', new stdClass())
            ->with('k', $second);

        self::assertCount(1, $collection);
        self::assertSame($second, $collection->find('k'));
    }

    public function testIteratesAsKeyValuePairs(): void
    {
        $a = new stdClass();
        $b = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->with('a', $a)->with('b', $b);

        self::assertSame(['a' => $a, 'b' => $b], iterator_to_array($collection));
    }

    public function testSearchableRejectsAnItemOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        new SearchableCollection(DateTime::class)->with('k', new stdClass());
    }

    public function testKeysWithSlashesAndDotsAreJustKeys(): void
    {
        $item = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->with('../../etc/passwd', $item);

        self::assertSame($item, $collection->find('../../etc/passwd'));
        self::assertNull($collection->find('etc/passwd'));
    }
}
