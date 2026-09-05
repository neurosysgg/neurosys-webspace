<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use NoDiscard;
use Traversable;
use TypeError;

/**
 * The SearchableCollection class. A type-safe, string-keyed collection of objects.
 *
 * Complements {@link Collection} (which is integer-indexed) with key-based storage
 * and lookup. Use when items must be retrievable by a named key (e.g. a URL slug).
 *
 * Immutable for the same reason and in the same way — see {@link Collection}.
 *
 * The store, the declared type and the type check live in {@link TypedItems}, shared with
 * {@link Collection}. What is here is what makes this one a **map**: `with()` takes a key, `find()`
 * exists at all, and iteration yields that key alongside the item — which is the whole reason
 * `ReleasesView` can name each release by its slug while listing it.
 *
 * @template T of object
 * @implements IteratorAggregate<string, T>
 */
class SearchableCollection implements Countable, IteratorAggregate
{
    /** @use TypedItems<T> */
    use TypedItems;

    /**
     * Returns a copy of this collection with $item stored under $key.
     *
     * @param string $key  The key to store the item under.
     * @param T      $item The item to store.
     * @return static
     * @throws TypeError if $item is not an instance of the declared type.
     */
    #[NoDiscard('with() copies rather than stores, so a call whose result goes nowhere does nothing')]
    public function with(string $key, mixed $item): static
    {
        $this->guard($item);

        $copy               = clone $this;
        $copy->items[$key]  = $item;

        return $copy;
    }

    /**
     * Finds an item by its key, or returns null if not found.
     *
     * @param string $key
     * @return T|null
     */
    public function find(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    /** @return array<string, T> */
    public function all(): array { return $this->items; }

    /** @return ArrayIterator<string, T> */
    public function getIterator(): Traversable { return new ArrayIterator($this->items); }
}
