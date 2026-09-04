<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use ArrayObject;
use DateTime;
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

        $collection = new Collection(stdClass::class)->add($a, $b);

        self::assertCount(2, $collection);
        self::assertSame([$a, $b], $collection->all());
    }

    public function testAddIsChainable(): void
    {
        $collection = new Collection(stdClass::class);

        self::assertSame($collection, $collection->add(new stdClass()));
    }

    public function testIsIterable(): void
    {
        $items = [new stdClass(), new stdClass()];

        self::assertSame($items, iterator_to_array(new Collection(stdClass::class)->add(...$items)));
    }

    public function testRejectsAnItemOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        new Collection(DateTime::class)->add(new stdClass());
    }

    public function testRejectsAScalar(): void
    {
        $this->expectException(TypeError::class);
        new Collection(stdClass::class)->add('not an object');
    }

    /** A rejected batch must not leave the earlier items behind. */
    public function testTheTypeErrorNamesBothTypes(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessage(DateTime::class);
        new Collection(DateTime::class)->add(new stdClass());
    }

    public function testAcceptsSubclassesOfTheDeclaredType(): void
    {
        $collection = new Collection(ArrayObject::class)->add(new class () extends ArrayObject {});

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

        self::assertSame($item, new SearchableCollection(stdClass::class)->add('k', $item)->find('k'));
    }

    public function testAddingTheSameKeyTwiceReplacesTheItem(): void
    {
        $second = new stdClass();

        $collection = new SearchableCollection(stdClass::class)
            ->add('k', new stdClass())
            ->add('k', $second);

        self::assertCount(1, $collection);
        self::assertSame($second, $collection->find('k'));
    }

    public function testIteratesAsKeyValuePairs(): void
    {
        $a = new stdClass();
        $b = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->add('a', $a)->add('b', $b);

        self::assertSame(['a' => $a, 'b' => $b], iterator_to_array($collection));
    }

    public function testSearchableRejectsAnItemOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        new SearchableCollection(DateTime::class)->add('k', new stdClass());
    }

    public function testKeysWithSlashesAndDotsAreJustKeys(): void
    {
        $item = new stdClass();

        $collection = new SearchableCollection(stdClass::class)->add('../../etc/passwd', $item);

        self::assertSame($item, $collection->find('../../etc/passwd'));
        self::assertNull($collection->find('etc/passwd'));
    }
}
