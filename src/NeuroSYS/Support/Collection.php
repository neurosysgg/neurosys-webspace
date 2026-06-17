<?php

namespace NeuroSYS\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use TypeError;

/**
 * The Collection class. A type-safe generic collection for objects of a single class.
 *
 * @template T of object
 * @implements IteratorAggregate<int, T>
 */
class Collection implements Countable, IteratorAggregate
{
    /** @var list<T> */
    private array $items = [];

    /**
     * Constructs an instance of {@link self}.
     *
     * @param class-string<T> $type The fully-qualified class name this collection holds.
     */
    public function __construct(public readonly string $type) {}

    /**
     * Adds one or more items to the collection.
     *
     * @param T ...$items
     * @return static
     * @throws \TypeError if any item is not an instance of the declared type.
     */
    public function add(mixed ...$items): static
    {
        foreach ($items as $item) {
            if (!($item instanceof $this->type)) {
                throw new TypeError(sprintf(
                    '%s expects %s, got %s',
                    static::class,
                    $this->type,
                    get_debug_type($item),
                ));
            }
            $this->items[] = $item;
        }
        return $this;
    }

    /** @return list<T> */
    public function all(): array { return $this->items; }

    public function count(): int { return count($this->items); }

    /** @return ArrayIterator<int, T> */
    public function getIterator(): Traversable { return new ArrayIterator($this->items); }
}
