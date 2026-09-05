<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The Collection class. A type-safe generic collection for objects of a single class.
 *
 * Immutable: {@link self::with()} returns a new instance rather than mutating this one, which is
 * what makes a collection safe to hold inside a readonly value object. `readonly` protects the
 * reference, not what it points at, so a mutable collection would leave every Release, Terminal and
 * SoundCloudEmbed appendable from anywhere holding one. Same shape as
 * {@link \NeuroSYS\Http\Security\ContentSecurityPolicy::allow()}, and named for it: `with` reads
 * as a copy where `add` would read as a mutation, so a discarded return value looks wrong.
 *
 * The store, the declared type and the type check live in {@link TypedItems}, shared with
 * {@link SearchableCollection} — see that trait for why it is a trait and not a base class. What is
 * here is what makes this one a **list**: `with()` appends, and iteration yields integer keys.
 *
 * @template T of object
 * @implements IteratorAggregate<int, T>
 */
class Collection implements Countable, IteratorAggregate
{
    /** @use TypedItems<T> */
    use TypedItems;

    /**
     * Returns a copy of this collection with one or more items appended.
     *
     * @param T ...$items
     * @return static
     * @throws \TypeError if any item is not an instance of the declared type. The copy is
     *                     discarded with the exception, so a rejected batch cannot half-apply.
     */
    #[\NoDiscard('with() copies rather than appends, so a call whose result goes nowhere does nothing')]
    public function with(mixed ...$items): static
    {
        $copy = clone $this;

        foreach ($items as $item) {
            $this->guard($item);
            $copy->items[] = $item;
        }
        return $copy;
    }

    /**
     * `with()` only ever appends to an array that started empty, so this is always a list — the
     * trait's store is typed `array-key` because it is shared with the map, not because this one
     * can grow holes.
     *
     * @return list<T>
     */
    public function all(): array { return $this->items; }

    /** @return ArrayIterator<int, T> */
    public function getIterator(): Traversable { return new ArrayIterator($this->items); }
}
