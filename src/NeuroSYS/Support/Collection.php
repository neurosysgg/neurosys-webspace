<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;
use TypeError;

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
     * Returns a copy of this collection with one or more items appended.
     *
     * @param T ...$items
     * @return static
     * @throws \TypeError if any item is not an instance of the declared type. The copy is
     *                     discarded with the exception, so a rejected batch cannot half-apply.
     */
    public function with(mixed ...$items): static
    {
        $copy = clone $this;

        foreach ($items as $item) {
            if (!($item instanceof $this->type)) {
                throw new TypeError(sprintf(
                    '%s expects %s, got %s',
                    static::class,
                    $this->type,
                    get_debug_type($item),
                ));
            }
            $copy->items[] = $item;
        }
        return $copy;
    }

    /** @return list<T> */
    public function all(): array { return $this->items; }

    public function count(): int { return count($this->items); }

    /** @return ArrayIterator<int, T> */
    public function getIterator(): Traversable { return new ArrayIterator($this->items); }
}
