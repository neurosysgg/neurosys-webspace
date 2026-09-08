<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use ArrayObject;
use DateTime;
use NeuroSYS\Http\Security\CspSource;
use NeuroSYS\Http\Security\CspSourceList;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\Directory;
use NeuroSYS\Support\File;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\Support\TypedItems;
use NeuroSYS\View\Html\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use stdClass;
use TypeError;

#[CoversClass(Collection::class)]
#[CoversClass(SearchableCollection::class)]
#[CoversTrait(TypedItems::class)]
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
        self::assertSame([], $collection->toArray());
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
        self::assertSame([$a, $b], $collection->toArray());
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

    // ─────────────────────────── scalar collections ───────────────────────────

    /**
     * @return void
     */
    public function testHoldsScalarsOfTheDeclaredType(): void
    {
        self::assertSame(['a', 'b'], new Collection('string')->with('a', 'b')->toArray());
        self::assertSame([1, 2], new Collection('int')->with(1, 2)->toArray());
        self::assertSame([1.5], new Collection('float')->with(1.5)->toArray());
        self::assertSame([true, false], new Collection('bool')->with(true, false)->toArray());
    }

    /**
     * @return void
     */
    public function testRejectsAScalarOfTheWrongType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains('expects string, got int');
        (void) new Collection('string')->with(1);
    }

    /**
     * @return void
     */
    public function testRejectsAnObjectInAScalarCollection(): void
    {
        $this->expectException(TypeError::class);
        (void) new Collection('int')->with(new stdClass());
    }

    /**
     * `int` satisfies `float` because that is the one widening PHP itself performs under
     * `declare(strict_types=1)`; a collection stricter than the language would refuse
     * `array_fill(0, 512, 0)`.
     *
     * @return void
     */
    public function testAnIntSatisfiesAFloatCollection(): void
    {
        self::assertSame([0, 1.5], new Collection('float')->with(0, 1.5)->toArray());
    }

    /**
     * The widening is one-way, exactly as a parameter's is.
     *
     * @return void
     */
    public function testAFloatDoesNotSatisfyAnIntCollection(): void
    {
        $this->expectException(TypeError::class);
        (void) new Collection('int')->with(1.5);
    }

    /**
     * @return void
     */
    public function testScalarsWorkInASearchableCollectionToo(): void
    {
        self::assertSame('v', new SearchableCollection('string')->with('k', 'v')->find('k'));
    }

    // ────────────────────── the declared type is checked ──────────────────────

    /**
     * The fault this exists for: `instanceof` answers `false` for a string naming no class, so a
     * misspelled type used to be a collection that silently rejected everything.
     *
     * @return void
     */
    public function testRefusesATypeThatNamesNothing(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains('Reelase');
        (void) new Collection('Reelase');
    }

    /**
     * @return void
     */
    public function testRefusesNullAndArrayAsDeclaredTypes(): void
    {
        $refused = 0;

        foreach (['null', 'array', 'mixed', 'iterable', ''] as $type) {
            try {
                (void) new Collection($type);
            } catch (TypeError) {
                $refused++;
            }
        }

        self::assertSame(5, $refused);
    }

    /**
     * `class_exists()` answers false for an interface, so the constructor has to ask twice.
     *
     * @return void
     */
    public function testAcceptsAnInterfaceAsItsDeclaredType(): void
    {
        self::assertSame(Node::class, new Collection(Node::class)->type);
    }

    /**
     * Enums need no third question — `class_exists()` already answers true for them.
     *
     * @return void
     */
    public function testAcceptsAnEnumAsItsDeclaredType(): void
    {
        self::assertCount(1, new Collection(ReleaseFormat::class)->with(ReleaseFormat::FLAC));
    }

    /**
     * @return void
     */
    public function testTheRefusalNamesTheCollectionAndTheScalarsItWouldAccept(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageIsOrContains(SearchableCollection::class);
        (void) new SearchableCollection('Nope');
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

        self::assertSame(['a' => $a, 'b' => $b], $collection->toArray());
    }

    /**
     * @return void
     */
    public function testAnEmptySearchableCollectionHandsBackAnEmptyArray(): void
    {
        self::assertSame([], new SearchableCollection(stdClass::class)->toArray());
    }
    // ─────────────────────────── The query methods ───────────────────────────

    /**
     * The store both collections share, and so the one place the six query methods are written.
     *
     * They are exercised through both classes rather than through one, because the two differ in
     * exactly the way {@link TypedItems::rebuilt()} exists to handle: a list has to be reindexed
     * after a filter and a map has to keep its keys. Everything else here is shared by construction.
     *
     * @return void
     */
    public function testIsEmptyAnswersForBothShapes(): void
    {
        self::assertTrue(new Collection(stdClass::class)->isEmpty());
        self::assertTrue(new SearchableCollection(stdClass::class)->isEmpty());
        self::assertFalse(new Collection(stdClass::class)->with(new stdClass())->isEmpty());
        self::assertFalse(new SearchableCollection(stdClass::class)->with('k', new stdClass())->isEmpty());
    }

    /**
     * @return void
     */
    public function testWhereKeepsOnlyTheMatches(): void
    {
        [$a, $b, $c] = [self::numbered(1), self::numbered(2), self::numbered(3)];

        $kept = new Collection(stdClass::class)
            ->with($a, $b, $c)
            ->where(static fn(stdClass $item): bool => $item->n !== 2);

        self::assertSame([$a, $c], $kept->toArray());
    }

    /**
     * A `Collection` is a `list<T>`, and `array_filter` preserves keys — so dropping the middle item
     * of three would leave `[0 => …, 2 => …]` if nothing reindexed. This is the assertion that says
     * something does.
     *
     * @return void
     */
    public function testWhereReindexesAList(): void
    {
        $kept = new Collection(stdClass::class)
            ->with(self::numbered(1), self::numbered(2), self::numbered(3))
            ->where(static fn(stdClass $item): bool => $item->n !== 2);

        self::assertSame([0, 1], $kept->toKeys());
    }

    /**
     * The other half of the same decision: a map that lost its keys on the way through `where()`
     * would have stopped being one.
     *
     * @return void
     */
    public function testWhereKeepsTheKeysOfAMap(): void
    {
        $kept = new SearchableCollection(stdClass::class)
            ->with('a', self::numbered(1))
            ->with('b', self::numbered(2))
            ->where(static fn(stdClass $item): bool => $item->n === 2);

        self::assertSame(['b'], $kept->toKeys());
    }

    /**
     * @return void
     */
    public function testWhereReturnsACopyAndLeavesTheOriginalAlone(): void
    {
        $collection = new Collection(stdClass::class)->with(new stdClass(), new stdClass());

        (void) $collection->where(static fn(): bool => false);

        self::assertCount(2, $collection);
    }

    /**
     * A collection rather than a list, and one that holds what the callback said it returns.
     *
     * The type is read off the `: int` and nowhere else — passing it as an argument too would be
     * the same fact written twice, and the second copy is the one that goes stale.
     *
     * @return void
     */
    public function testMapAnswersWithACollectionOfTheCallbacksReturnType(): void
    {
        $mapped = new Collection(stdClass::class)
            ->with(self::numbered(1), self::numbered(2))
            ->map(static fn(stdClass $item): int => $item->n);

        self::assertInstanceOf(Collection::class, $mapped);
        self::assertSame('int', $mapped->type);
        self::assertSame([1, 2], $mapped->toValues());
    }

    /**
     * The value first and the key second — the order `array_find` and `ARRAY_FILTER_USE_BOTH` use,
     * and the order that lets a one-argument callback stay a first-class callable.
     *
     * @return void
     */
    public function testMapHandsOverTheValueThenTheKey(): void
    {
        $mapped = new SearchableCollection(stdClass::class)
            ->with('a', self::numbered(1))
            ->with('b', self::numbered(2))
            ->map(static fn(stdClass $item, string $key): string => $key . $item->n);

        self::assertSame(['a1', 'b2'], $mapped->toValues());
    }

    /**
     * The property the value-first order was chosen for: PHP hands a userland callback the extra
     * argument harmlessly, so a callback that only wants the item does not have to declare a key it
     * will not read. Nine call sites depend on this.
     *
     * @return void
     */
    public function testAOneArgumentCallbackNeedsNoClosureAroundIt(): void
    {
        $mapped = new SearchableCollection(stdClass::class)
            ->with('a', self::numbered(7))
            ->map(self::plainNumber(...));

        self::assertSame([7], $mapped->toValues());
    }

    /**
     * The inversion, and the behaviour change with the widest blast radius.
     *
     * This used to reindex, on the reasoning that `array_map` given two arrays returns one — the
     * implementation talking rather than the type. A `SearchableCollection` is a map, and the whole
     * reason `ReleasesView` can name each release by its slug is that it stays one through a
     * `map()`. What follows is that the result can no longer be spread into a call, since string
     * keys are named arguments, so a spreading call site asks {@link Collection::toValues()} and
     * says so.
     *
     * @return void
     */
    public function testMapKeepsTheKeysOfAMap(): void
    {
        $mapped = new SearchableCollection(stdClass::class)
            ->with('z', self::numbered(1))
            ->with('a', self::numbered(2))
            ->map(static fn(stdClass $item): int => $item->n);

        self::assertSame(['z' => 1, 'a' => 2], $mapped->toArray());
    }

    /**
     * A list maps to a list and a map maps to a map. Neither becomes the other.
     *
     * @return void
     */
    public function testMapAnswersWithTheSameShapeItWasCalledOn(): void
    {
        self::assertInstanceOf(
            SearchableCollection::class,
            new SearchableCollection(stdClass::class)->map(static fn(stdClass $i): int => $i->n),
        );
        self::assertInstanceOf(
            Collection::class,
            new Collection(stdClass::class)->map(static fn(stdClass $i): int => $i->n),
        );
    }

    /**
     * A subclass is a claim about the element type, so `where()` keeps it and `map()` cannot.
     *
     * {@link CspSourceList} is the whole reason the distinction is worth a test: it exists to say
     * its list holds {@link \NeuroSYS\Http\Security\CspSource}, which is exactly what stops
     * being true the moment a callback turns those into something else.
     *
     * @return void
     */
    public function testASubclassSurvivesWhereAndIsLeftBehindByMap(): void
    {
        $sources = new CspSourceList();

        self::assertInstanceOf(CspSourceList::class, $sources->where(static fn(): bool => true));

        $mapped = $sources->map(static fn(CspSource $source): string => $source->source());

        self::assertInstanceOf(Collection::class, $mapped);
        self::assertNotInstanceOf(CspSourceList::class, $mapped);
    }

    /**
     * @return void
     */
    public function testMapRefusesACallbackThatDeclaresNoReturnType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches('/declares none/');

        (void) new Collection('int')->map(static fn(int $n) => $n);
    }

    /**
     * A collection cannot hold null, so a callback that may answer with one is refused where it is
     * written rather than at whichever item first turns out to be null.
     *
     * @return void
     */
    public function testMapRefusesANullableReturnType(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches('/cannot hold null/');

        (void) new Collection('int')->map(static fn(int $n): ?string => null);
    }

    /**
     * `array` is refused as a declared type, so it is refused as a mapped-to one — the constructor
     * is the single place that decides, and its message names the type rather than the callback.
     *
     * @return void
     */
    public function testMapRefusesAReturnTypeNoCollectionCanHold(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches("/cannot hold 'array'/");

        (void) new Collection('int')->with(1)->map(static fn(int $n): array => [$n]);
    }

    /**
     * @return void
     */
    public function testJoinImplodesWhateverTheChainProduced(): void
    {
        $joined = new Collection(stdClass::class)
            ->with(self::numbered(1), self::numbered(2), self::numbered(3))
            ->map(static fn(stdClass $item): string => (string) $item->n)
            ->join(' · ');

        self::assertSame('1 · 2 · 3', $joined);
    }

    /**
     * @return void
     */
    public function testJoinAnswersEmptyForAnEmptyCollection(): void
    {
        self::assertSame('', new Collection('string')->join(', '));
    }

    /**
     * `join()` lost its callback when `map()` started answering with a collection, so what is left
     * is a collection of strings or a mistake. Saying which is cheaper than `implode()`'s own
     * answer, which for a collection of objects is a fatal about string conversion.
     *
     * @return void
     */
    public function testJoinRefusesACollectionThatDoesNotHoldStrings(): void
    {
        $this->expectException(TypeError::class);
        $this->expectExceptionMessageMatches('/map\(\) it to a string first/');

        (void) new Collection('int')->with(1, 2)->join(', ');
    }

    // ─────────────────────────── One pass, fused ───────────────────────────

    /**
     * The property the whole pipeline exists for, and the one an eager implementation passes every
     * other test without having.
     *
     * A staged `where()`-then-`map()` would trace `w1 w2 w3 w4 w5 w6` and only then `m2 m4 m6`,
     * building an intermediate list in between. Fused, each element goes through the whole chain
     * before the next is touched — which is what the interleaving below says and what nothing else
     * here can distinguish.
     *
     * @return void
     */
    public function testTransformingStepsRunFusedRatherThanOneAfterTheOther(): void
    {
        $trace = [];

        $chain = new Collection('int')
            ->with(1, 2, 3, 4, 5, 6)
            ->where(static function (int $n) use (&$trace): bool {
                $trace[] = "w$n";

                return $n % 2 === 0;
            })
            ->map(static function (int $n) use (&$trace): string {
                $trace[] = "m$n";

                return "n$n";
            });

        self::assertSame([], $trace, 'a transforming step must run nothing until something asks');

        self::assertSame(['n2', 'n4', 'n6'], $chain->toValues());
        self::assertSame(
            ['w1', 'w2', 'm2', 'w3', 'w4', 'm4', 'w5', 'w6', 'm6'],
            $trace,
        );
    }

    /**
     * Both short-circuiting materialisers, asserted by what the chain was *not* asked to do.
     *
     * @return void
     */
    public function testFirstAndIsEmptyStopAtTheFirstAnswer(): void
    {
        $seen  = 0;
        $chain = new Collection('int')
            ->with(1, 2, 3, 4, 5, 6)
            ->map(static function (int $n) use (&$seen): int {
                $seen++;

                return $n * 10;
            });

        self::assertSame(10, $chain->first());
        self::assertSame(1, $seen, 'first() ran the chain for one element');

        $seen = 0;
        self::assertFalse($chain->isEmpty());
        self::assertSame(1, $seen, 'isEmpty() ran the chain for one element');

        $seen = 0;
        self::assertSame(40, $chain->first(static fn(int $n): bool => $n > 30));
        self::assertSame(4, $seen, 'a predicate stops at the match rather than at the end');
    }

    /**
     * A `Generator` is exhausted once; a method that builds one is not. That distinction is what
     * lets a pending pipeline be held, iterated, counted and iterated again — including nested
     * inside its own `foreach`, which is what `Element::renderChildren()` does one collection down.
     *
     * @return void
     */
    public function testAPipelineCanBeMaterialisedMoreThanOnce(): void
    {
        $chain = new Collection('int')
            ->with(1, 2, 3)
            ->map(static fn(int $n): int => $n * 2);

        self::assertSame([2, 4, 6], $chain->toValues());
        self::assertSame([2, 4, 6], $chain->toValues());
        self::assertCount(3, $chain);
        self::assertSame([2, 4, 6], iterator_to_array($chain, false));

        $pairs = [];

        foreach ($chain as $outer) {
            foreach ($chain as $inner) {
                $pairs[] = $outer + $inner;
            }
        }

        self::assertSame([4, 6, 8, 6, 8, 10, 8, 10, 12], $pairs);
    }

    /**
     * Appending is a store operation, so it runs what is pending first — which is what keeps
     * `->map(…)->with($x)` meaning what it reads as, with $x on the end of the mapped items rather
     * than waiting behind a transformation that was never meant to touch it.
     *
     * @return void
     */
    public function testWithRunsAPendingPipelineBeforeAppending(): void
    {
        $chain = new Collection('int')->with(1, 2)->map(static fn(int $n): string => "n$n");

        self::assertSame(['n1', 'n2', 'z'], $chain->with('z')->toValues());
        self::assertSame(['n1', 'n2'], $chain->toValues(), 'with() copies rather than appends');
    }

    /**
     * A list renumbers between steps, so a `map()` after a `where()` is handed `0, 1, 2` — exactly
     * what it was handed when `where()` rebuilt an array eagerly. A map keeps its keys throughout.
     *
     * @return void
     */
    public function testAListIsRenumberedBetweenStepsAndAMapIsNot(): void
    {
        self::assertSame(
            ['0:20', '1:40'],
            new Collection('int')
                ->with(10, 20, 30, 40)
                ->where(static fn(int $n): bool => $n % 20 === 0)
                ->map(static fn(int $n, int $key): string => "$key:$n")
                ->toValues(),
        );

        self::assertSame(
            ['b:20'],
            new SearchableCollection('int')
                ->with('a', 10)
                ->with('b', 20)
                ->where(static fn(int $n): bool => $n === 20)
                ->map(static fn(int $n, string $key): string => "$key:$n")
                ->toValues(),
        );
    }

    /**
     * The one materialiser that cannot short-circuit — the far end of a stream is only knowable by
     * reaching it — and the one that had no test at all until the pipeline gave it a second way to
     * be wrong.
     *
     * @return void
     */
    public function testLastAnswersTheFarEndOfWhateverTheChainProduced(): void
    {
        self::assertNull(new Collection('int')->last());
        self::assertSame(3, new Collection('int')->with(1, 2, 3)->last());
        self::assertSame(
            'n4',
            new Collection('int')
                ->with(1, 2, 3, 4, 5)
                ->where(static fn(int $n): bool => $n % 2 === 0)
                ->map(static fn(int $n): string => "n$n")
                ->last(),
        );
        self::assertNull(
            new Collection('int')->with(1)->where(static fn(): bool => false)->last(),
        );
    }

    // ─────────────────────────── settled() ───────────────────────────

    /**
     * The member laziness made necessary, and the hazard it exists for.
     *
     * {@link \NeuroSYS\Tool\Demo\DemoStage::write()} filters on a predicate that transcodes with
     * ffmpeg, so *when* that predicate runs is the whole behaviour of the method. Left pending it
     * runs at whatever first asks a question — and `isEmpty()`, which is what its caller asks, stops
     * at the first match with every mix behind it unstaged.
     *
     * @return void
     */
    public function testSettledRunsThePendingStepsWhereItIsAsked(): void
    {
        $staged = [];

        $failed = new Collection('string')
            ->with('a', 'b', 'c')
            ->where(static function (string $mix) use (&$staged): bool {
                $staged[] = $mix;

                return $mix !== 'b';
            })
            ->settled();

        self::assertSame(['a', 'b', 'c'], $staged, 'every item went through before settled() returned');
        self::assertSame(['a', 'c'], $failed->toValues());

        (void) $failed->isEmpty();
        (void) $failed->toValues();

        self::assertSame(['a', 'b', 'c'], $staged, 'and the answer can be asked twice for free');
    }

    /**
     * The behaviour that makes `settled()` worth having: without it the predicate runs once for the
     * first item and again for all of them, so a side-effecting one both skips work and repeats it.
     *
     * @return void
     */
    public function testALazyFilterShortCircuitsAndThenRunsAgain(): void
    {
        $seen    = [];
        $pending = new Collection('string')
            ->with('a', 'b', 'c')
            ->where(static function (string $mix) use (&$seen): bool {
                $seen[] = $mix;

                return true;
            });

        self::assertFalse($pending->isEmpty());
        self::assertSame(['a'], $seen, 'isEmpty() stopped at the first item');

        (void) $pending->toValues();

        self::assertSame(['a', 'a', 'b', 'c'], $seen, "and 'a' was put through a second time");
    }

    /**
     * Nothing pending is nothing to run, so this is the collection itself — which is also what lets
     * a caller assert `assertSame($c, $c->settled())` to say a collection carries no pending work,
     * without reaching for its private members.
     *
     * @return void
     */
    public function testSettledIsTheSameCollectionWhenNothingIsPending(): void
    {
        $collection = new Collection('int')->with(1, 2);

        self::assertSame($collection, $collection->settled());
        self::assertNotSame($collection, $collection->where(static fn(): bool => true)->settled());
    }

    /**
     * Settling changes when the work happens, not what the collection holds — so a subclass
     * survives it, exactly as it survives {@link Collection::where()}.
     *
     * @return void
     */
    public function testSettledKeepsASubclass(): void
    {
        self::assertInstanceOf(
            CspSourceList::class,
            new CspSourceList()->where(static fn(): bool => true)->settled(),
        );
    }

    /**
     * @return void
     */
    public function testFirstAnswersTheFirstMatch(): void
    {
        [$a, $b] = [self::numbered(2), self::numbered(2)];

        $collection = new Collection(stdClass::class)->with(self::numbered(1), $a, $b);

        self::assertSame($a, $collection->first(static fn(stdClass $item): bool => $item->n === 2));
    }

    /**
     * @return void
     */
    public function testFirstWithNoPredicateAnswersTheFirstItem(): void
    {
        $a = new stdClass();

        self::assertSame($a, new Collection(stdClass::class)->with($a, new stdClass())->first());
    }

    /**
     * Null for both ways of not finding anything, which is what every call site collapses them to.
     *
     * @return void
     */
    public function testFirstAnswersNullWhenThereIsNothingToAnswerWith(): void
    {
        self::assertNull(new Collection(stdClass::class)->first());
        self::assertNull(new Collection(stdClass::class)->with(new stdClass())->first(static fn(): bool => false));

        // And through a pending pipeline, which is a different loop: the fast path above asks
        // array_find(), a chain has to be run out before it can say there was nothing.
        self::assertNull(
            new Collection('int')->with(1, 2)->map(static fn(int $n): string => "n$n")->first(
                static fn(string $name): bool => $name === 'n3',
            ),
        );
    }

    /**
     * @return void
     */
    public function testKeysAnswersIndicesForAListAndNamesForAMap(): void
    {
        self::assertSame(
            [0, 1],
            new Collection(stdClass::class)->with(new stdClass(), new stdClass())->toKeys(),
        );
        self::assertSame(
            ['a', 'b'],
            new SearchableCollection(stdClass::class)
                ->with('a', new stdClass())
                ->with('b', new stdClass())
                ->toKeys(),
        );
    }

    /**
     * An object carrying one number, so a test can say which item it got back.
     *
     * @param int $n
     * @return stdClass
     */
    private static function numbered(int $n): stdClass
    {
        $item    = new stdClass();
        $item->n = $n;

        return $item;
    }

    /**
     * Declares one parameter on purpose — see
     * {@link self::testAOneArgumentCallbackNeedsNoClosureAroundIt()}.
     *
     * @param stdClass $item
     * @return int
     */
    private static function plainNumber(stdClass $item): int
    {
        return $item->n;
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
     * A read stops at the byte limit it is given.
     *
     * This is what lets a caller reading an untrusted stream — the one being
     * {@link \NeuroSYS\Http\Request::body()} over `php://input` — bound how much it pulls into memory
     * rather than inheriting `post_max_size`. Null, the default every other caller uses, reads the
     * file whole; a limit past the end is the same, since there is no more to read.
     *
     * @return void
     */
    public function testAReadStopsAtItsLimit(): void
    {
        $directory = Directory::temporary('neurosys-support-');
        $file      = $directory->file('body.bin');

        try {
            self::assertTrue($file->write('0123456789'));
            self::assertSame('0123', $file->read(4), 'the read ran past its limit');
            self::assertSame('0123456789', $file->read(100), 'a limit past the end is the whole file');
            self::assertSame('0123456789', $file->read(), 'null reads the file whole');
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
     * That the mode lands, which is the half this can see.
     *
     * **The other half is the order, and no runtime assertion can reach it.** Applying the mode
     * before the contents and applying it after both end with the same file at the same mode; what
     * differs is only whether the contents sat there world-readable in between, which is a window
     * this process cannot sample from inside itself. `test/basic_test.sh` asserts the order against
     * the source instead — the same move the CSP checks make, and it was verified to fail when the
     * two statements are swapped back.
     *
     * @return void
     */
    public function testAModeIsAppliedBeforeTheContentsAreReachable(): void
    {
        $directory = Directory::temporary('neurosys-support-');
        $file      = $directory->file('token.json');

        try {
            self::assertTrue($file->write('{}', 0o600));
            self::assertSame('0600', substr(sprintf('%o', fileperms($file->path)), -4));

            // A mode-less write is left to the umask rather than narrowed to something of this
            // class's choosing — the site appends a log and writes nothing, so the only caller that
            // asks for a mode is the one holding a credential.
            $plain = $directory->file('plain.txt');

            self::assertTrue($plain->write('hello'));
            self::assertSame('hello', $plain->read());
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
                $directory->files()->map(static fn(File $f): string => $f->name())->toValues(),
            );
            self::assertSame(
                ['a.flac'],
                $directory->files('*.flac')->map(static fn(File $f): string => $f->name())->toValues(),
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
            self::assertSame([], $directory->files('taken.*')->toArray(), 'the temporary file was cleaned up');
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
