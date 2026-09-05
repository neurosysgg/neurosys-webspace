<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use TypeError;

/**
 * The TypedItems trait. The store {@link Collection} and {@link SearchableCollection} share.
 *
 * **A trait rather than a base class, and that is the whole design decision here.** The two
 * collections are not substitutable and never should be: one is a list and one is a map, their
 * `with()` methods take different arguments, and no call site on this site holds "either kind of
 * collection". A shared parent would announce a common type that nothing wants and nothing checks;
 * a trait announces shared plumbing, which is all this is. It is the first trait in the codebase
 * for that reason — the honest reading of `extends` would have been a claim, and the honest reading
 * of `use` is a copy.
 *
 * Two mechanical consequences follow from that choice and are worth knowing before touching it:
 *
 * - **`$items` stays `private`.** PHP flattens a trait's members into the using class, so each
 *   collection's `with()` can still write `$copy->items` — which a `private` member of a *parent*
 *   would have forbidden, forcing it to `protected` and opening the store to anything that ever
 *   inherited.
 * - **`static::class` still names the collection**, not this trait, so
 *   {@link self::guard()}'s message reads `NeuroSYS\Support\Collection expects …` exactly as it
 *   did when the `sprintf` sat in both files. `SupportTest` asserts that message, which is what
 *   would catch a later slip to `self::class`.
 *
 * What stayed behind in each class is what genuinely differs: `with()` (different arity, different
 * write), `find()` (one of them only), and `all()`/`getIterator()` — those two have identical
 * bodies but different `@return` types, `list<T>` against `array<string, T>`, and that difference
 * is the reason there are two classes at all.
 *
 * @template T of object
 */
trait TypedItems
{
    /** @var array<array-key, T> */
    private array $items = [];

    /**
     * Constructs an instance of {@link self}.
     *
     * @param class-string<T> $type The fully-qualified class name this collection holds.
     */
    public function __construct(public readonly string $type) {}

    public function count(): int { return count($this->items); }

    /**
     * Throws unless $item is one of the things this collection was declared to hold.
     *
     * The one check a PHP generic cannot make. `@template T` is a docblock, erased at runtime, so
     * `$this->type` is the only thing that actually knows — which is also why it is a public
     * readonly property rather than an implementation detail: `Release`, `Terminal` and both embeds
     * read it to check the *element* type of a collection they were handed.
     *
     * @param T $item
     * @throws TypeError if it is not.
     */
    private function guard(mixed $item): void
    {
        if ($item instanceof $this->type) {
            return;
        }

        throw new TypeError(sprintf(
            '%s expects %s, got %s',
            static::class,
            $this->type,
            get_debug_type($item),
        ));
    }
}
