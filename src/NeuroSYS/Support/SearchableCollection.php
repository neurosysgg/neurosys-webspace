<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
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
 * @template T of object
 * @implements IteratorAggregate<string, T>
 */
class SearchableCollection implements Countable, IteratorAggregate
{
    /** @var array<string, T> */
    private array $items = [];

    /**
     * Constructs an instance of {@link self}.
     *
     * @param class-string<T> $type The fully-qualified class name this collection holds.
     */
    public function __construct(public readonly string $type) {}

    /**
     * Returns a copy of this collection with $item stored under $key.
     *
     * @param string $key  The key to store the item under.
     * @param T      $item The item to store.
     * @throws TypeError if $item is not an instance of the declared type.
     */
    public function with(string $key, mixed $item): static
    {
        if (!($item instanceof $this->type)) {
            throw new TypeError(sprintf(
                '%s expects %s, got %s',
                static::class,
                $this->type,
                get_debug_type($item),
            ));
        }

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

    public function count(): int { return count($this->items); }

    /** @return ArrayIterator<string, T> */
    public function getIterator(): Traversable { return new ArrayIterator($this->items); }
}
