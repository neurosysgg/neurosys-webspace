<?php

declare(strict_types=1);

namespace NeuroSYS\Support;

use NoDiscard;
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
 * write), `find()` (one of them only), `all()`/`getIterator()` — those two have identical bodies but
 * different `@return` types, `list<T>` against `array<string, T>`, and that difference is the reason
 * there are two classes at all — and {@link self::rebuilt()}, which is abstract here for the same
 * reason: a list has to be reindexed and a map has to keep its keys.
 *
 * **The query methods below are the site's default way of handling a group of things.** They were
 * written because `all()` had become the escape hatch out of the type: sixteen call sites reached
 * for it or hand-rolled a `foreach`, and nine of those unwrapped the collection for no other purpose
 * than to hand the array to `array_map`. A collection that has to be unwrapped before it can be
 * asked anything is a collection that only types its construction.
 *
 * **The callback takes the value first and the key second.** That is the order PHP's own
 * `array_find`, `array_any` and `array_all` use — `Element::renderChildren()` already calls one —
 * and the order `ARRAY_FILTER_USE_BOTH` passes. It also costs nothing at the call sites that do not
 * want the key: PHP hands a userland callback extra arguments harmlessly, so
 * `$links->map(self::profileLink(...))` stays a first-class callable rather than growing a closure
 * around it. Key-first would have broken every one of those.
 *
 * **{@link self::map()} answers with a `list` and not with a collection**, which is a constraint
 * rather than a preference: a collection is defined by a `class-string`, and six of the nine call
 * sites map to a `string` or an `array`, neither of which is a class. Every one of them spreads
 * straight into `containing(...)` or `implode()`, so a collection there would be built only to be
 * unwrapped again. `where()` returns `static` and chains; `map()` ends the chain.
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

    /**
     * @return int
     */
    public function count(): int { return count($this->items); }

    /**
     * Whether this collection holds nothing.
     *
     * Here because the three call sites that asked were each spelling it differently — `count() > 0`,
     * `count() === 0`, `all() === []` — and the last of those had to unwrap the collection to ask a
     * question about the collection.
     *
     * @return bool
     */
    #[NoDiscard('isEmpty() answers a question and changes nothing, so a call whose result goes nowhere does nothing')]
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * Returns a copy holding only the items $predicate accepts.
     *
     * Copies rather than filters in place, for the reason {@link Collection::with()} gives: a
     * collection held inside a `readonly` value object is only as immutable as its own methods make
     * it.
     *
     * @param callable(T, array-key): bool $predicate Takes the item, then its key.
     * @return static
     */
    #[NoDiscard('where() returns a copy holding the matches; the collection it was called on is unchanged')]
    public function where(callable $predicate): static
    {
        return $this->rebuilt(array_filter($this->items, $predicate, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Every item through $callback, as a plain list.
     *
     * The list is reindexed even where the collection was keyed, because `array_map` given two
     * arrays returns one — and because every caller here spreads or joins the result, where a key
     * would mean nothing.
     *
     * @template TOut
     * @param callable(T, array-key): TOut $callback Takes the item, then its key.
     * @return list<TOut>
     */
    #[NoDiscard('map() answers with a new list and changes nothing, so a call whose result goes nowhere does nothing')]
    public function map(callable $callback): array
    {
        return array_map($callback, array_values($this->items), array_keys($this->items));
    }

    /**
     * Every item through $callback, joined by $glue.
     *
     * {@link self::map()} and then `implode()`, which is how all three of its call sites were
     * already written — an options list separated by spaces, a plugin list separated by commas. It
     * earns a member of its own rather than being left as two calls because the pair reads
     * inside-out: the separator is written first and applies last, and the collection being joined
     * ends up furthest from the word that says what is happening to it.
     *
     * No default glue. `implode()` has one — the empty string — and it is the answer to a question
     * nobody asks on purpose.
     *
     * @param string $glue
     * @param callable(T, array-key): string $callback Takes the item, then its key.
     * @return string
     */
    #[NoDiscard('join() answers with a string and changes nothing, so a call whose result goes nowhere does nothing')]
    public function join(string $glue, callable $callback): string
    {
        return implode($glue, $this->map($callback));
    }

    /**
     * The first item $predicate accepts, or the first item at all, or null for neither.
     *
     * Replaces the `foreach`-and-return that {@link \NeuroSYS\Model\Release::findFormat()} was
     * written as. Null rather than an exception for the same reason `find()` answers null: not
     * finding something is a normal answer to a search.
     *
     * @param (callable(T, array-key): bool)|null $predicate
     * @return T|null
     */
    #[NoDiscard('first() answers with an item and changes nothing, so a call whose result goes nowhere does nothing')]
    public function first(?callable $predicate = null): mixed
    {
        return array_find($this->items, $predicate ?? static fn(): bool => true);
    }

    /**
     * The last item, or null for an empty collection.
     *
     * Here because {@link \NeuroSYS\Model\Production\Arrangement::lastStart()} was the one
     * remaining place in `src/` that called {@link Collection::all()} to get at an array — not to
     * do anything with the array, but because `end()` was the only way to ask this question. That
     * is exactly the escape hatch the query methods above were written to close, and it stayed open
     * only because nothing had needed the far end of a collection before.
     *
     * `array_key_last()` rather than `end()`: `end()` moves the array's internal pointer, which is
     * a write to the store from a method that promises to be a read.
     *
     * **No predicate, unlike {@link self::first()}**, and the asymmetry is deliberate rather than
     * an omission. `first()` has one because {@link \NeuroSYS\Model\Release::findFormat()} is a
     * search; nothing here searches backwards. PHP gives `array_find()` and no `array_find_last()`,
     * so the predicate form would be a hand-rolled reverse loop written for nobody — and the day
     * something wants one, `where(…)->last()` already answers it.
     *
     * @return T|null
     */
    #[NoDiscard('last() answers with an item and changes nothing, so a call whose result goes nowhere does nothing')]
    public function last(): mixed
    {
        return $this->items === [] ? null : $this->items[array_key_last($this->items)];
    }

    /**
     * The keys, in insertion order.
     *
     * Integers for a {@link Collection} and strings for a {@link SearchableCollection}, which is why
     * this is one of the few members here whose usefulness is entirely on one side: only the map has
     * keys worth reading.
     *
     * @return list<array-key>
     */
    #[NoDiscard('keys() answers with a new list and changes nothing, so a call whose result goes nowhere does nothing')]
    public function keys(): array
    {
        return array_keys($this->items);
    }

    /**
     * A copy of this collection holding $items.
     *
     * Abstract because it is the one thing {@link self::where()} cannot decide for both: a
     * `Collection` is a `list<T>` and `array_filter` leaves the holes a list may not have, so it
     * reindexes; a `SearchableCollection` is keyed and reindexing would throw away what it is for.
     *
     * Private rather than protected, for the reason `$items` is: PHP flattens a trait's members into
     * the using class, so `where()` above can still reach it without any of this becoming a surface
     * that something outside could implement differently.
     *
     * @param array<array-key, T> $items
     * @return static
     */
    abstract private function rebuilt(array $items): static;

    /**
     * Throws unless $item is one of the things this collection was declared to hold.
     *
     * The one check a PHP generic cannot make. `@template T` is a docblock, erased at runtime, so
     * `$this->type` is the only thing that actually knows — which is also why it is a public
     * readonly property rather than an implementation detail: `Release`, `Terminal` and both embeds
     * read it to check the *element* type of a collection they were handed.
     *
     * @param T $item
     * @return void
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
