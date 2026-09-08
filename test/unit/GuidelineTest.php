<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use FilesystemIterator;
use InvalidArgumentException;
use NeuroSYS\Support\BareArray;
use NeuroSYS\Support\BareString;
use PhpToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionEnum;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * The two habits this codebase is built against, checked by asking PHP about itself.
 *
 * Everything under `src/` is an argument against two shapes. A **bare array** announces nothing
 * about what it holds, which is why {@link \NeuroSYS\Support\Collection} exists; a **bare string**
 * is a name with no vocabulary, which is why there are fifty-odd enums. Both arguments were made
 * one class at a time and neither had anything watching it, so the only thing standing between the
 * codebase and a slow return to arrays-and-strings was whoever wrote the next method.
 *
 * This is that thing. It reads the code the way PHP does — reflection for what is *declared*, the
 * tokenizer for what is *written* — and it wants an argument for every exception rather than
 * silence. An exception is an attribute with a sentence in it: {@link BareArray},
 * {@link BareString}. The lists below are what those sentences are attached to, pinned so the set
 * cannot grow without somebody noticing, in the same spirit as
 * {@link NoDiscardTest::testExactlyTheseResultsMayNotBeDiscarded()} and
 * {@link HtmlTest::testExactlyTheAddressCarryingAttributesAreCheckedAsUrls()}.
 *
 * **Both rules are checked in both directions.** An unexcused violation fails, and so does an
 * excuse for something that is no longer a violation — an attribute left behind after the array
 * became a collection is a sentence describing code that is not there, and the next reader will
 * believe it.
 *
 * Three smaller guidelines ride along at the bottom. All three are at zero today and are here as
 * regressions rather than as work: `strict_types` on every file, a declared type on every
 * declaration, a backing value on every enum. Each is invisible when it slips — scalars quietly
 * start coercing, a missing type is only a missing type, an unbacked case has no wire form until
 * something asks for one.
 *
 * **What is deliberately not checked.** `mixed` appears twelve times under `src/`, always as a
 * collection's element type, and it is there because PHP has no generics rather than because
 * anybody chose it; a third attribute for a set that cannot change would be ceremony.
 * `tools/lib/` is not walked either — it is not deployed, it is outside the coverage source, and
 * the doors it is made of (`unpack`, `preg_match`, `file`) are most of what it does.
 *
 * It covers the two attributes and nothing else, which is not the `#[CoversNothing]`
 * {@link NoDiscardTest} carries and is the honest difference between them: that test only ever
 * *asks* reflection what an attribute says, where this one also constructs both of these and
 * proves each refuses a hole. Everything else here executes no line of `src/` at all.
 */
#[CoversClass(BareArray::class)]
#[CoversClass(BareString::class)]
final class GuidelineTest extends TestCase
{
    /**
     * The scalar types a {@link \NeuroSYS\Support\Collection} may be declared to hold — a value in
     * a class-string's place, and the one place a type name is genuinely a string.
     *
     * Named here rather than read off {@link \NeuroSYS\Support\TypedItems}, because a test that
     * derives its expectation from the thing it is testing asserts nothing.
     */
    private const array SCALAR_TYPES = ['string', 'int', 'float', 'bool'];

    /** Cache for {@link self::sourceTree()}: PHPUnit builds a fresh instance per test method. */
    private static ?array $tree = null;

    /**
     * Every `array` in a declared type under `src/` carries an excuse.
     *
     * Parameters, returns and properties, reflected rather than grepped: the engine is what knows
     * what a signature says, and a docblock's `list<Element>` is exactly the promise this rule
     * exists to stop trusting.
     *
     * A **variadic** is not on the list and never will be. `deny(PermissionsPolicyFeature
     * ...$features)` is a check PHP makes for free, and a collection parameter there would replace
     * it with one we make ourselves — the distinction CLAUDE.md draws between what a class *takes*
     * and what it *stores*.
     *
     * @return void
     */
    public function testEveryBareArrayIsExcused(): void
    {
        self::assertSame(
            [],
            self::bareArrays()['unexcused'],
            'an array in a declared type with no #[BareArray] saying why it is not a Collection',
        );
    }

    /**
     * These, and only these, stay arrays.
     *
     * Four kinds, and it is worth knowing which is which before adding a fifth:
     *
     * - **A door.** `preg_match`'s `$matches`, `file()`'s lines, `require`'s data file,
     *   `jsonSerialize()`'s contract, `toArray()` itself. PHP hands these over as arrays and no
     *   amount of typing on this side changes that; the collection is what crosses the boundary,
     *   and the door is where it is built.
     * - **A variadic's argument.** `varyOn()`, `accented()`, `nodes()`, `modulePreloads()`,
     *   `terminalFields()` are each spread into a call PHP already guards.
     * - **A tuple.** `entry()` and `credentials()` return two values of different kinds in a fixed
     *   order, which is the one shape a homogeneous collection cannot hold.
     * - **An accumulator.** `$qualities` and `counts()` are written to in a loop, where `with()`
     *   would copy — the same argument `tools/lib/Dsp/` makes at a larger scale.
     *
     * The collections' own five members appear three times each. That is this test working and not
     * failing, for the reason {@link NoDiscardTest} states about the same trait: PHP flattens a
     * trait's members into each using class, and counting one twice is the direction a test like
     * this can survive.
     *
     * @return void
     */
    public function testExactlyTheseArraysStayBare(): void
    {
        self::assertSame(
            [
                'NeuroSYS\Http\AcceptedLanguages::$qualities',
                'NeuroSYS\Http\AcceptedLanguages::__construct()',
                'NeuroSYS\Http\AcceptedLanguages::entry()',
                'NeuroSYS\Http\AuthScheme::credentials()',
                'NeuroSYS\Http\SecurityHeaders::headers()',
                'NeuroSYS\Http\Security\ContentSecurityPolicy::hosts()',
                'NeuroSYS\Layout::modulePreloads()',
                'NeuroSYS\Service\DownloadLogEntry::jsonSerialize()',
                'NeuroSYS\Service\DownloadStats::counts()',
                'NeuroSYS\Service\ProfileRepository::$links',
                'NeuroSYS\Support\Collection::$items',
                'NeuroSYS\Support\Collection::$steps',
                'NeuroSYS\Support\Collection::toArray()',
                'NeuroSYS\Support\Collection::toKeys()',
                'NeuroSYS\Support\Collection::toValues()',
                'NeuroSYS\Support\File::lines()',
                'NeuroSYS\Support\Route::createController()',
                'NeuroSYS\Support\Route::matches()',
                'NeuroSYS\Support\SearchableCollection::$items',
                'NeuroSYS\Support\SearchableCollection::$steps',
                'NeuroSYS\Support\SearchableCollection::toArray()',
                'NeuroSYS\Support\SearchableCollection::toKeys()',
                'NeuroSYS\Support\SearchableCollection::toValues()',
                'NeuroSYS\Support\TypedItems::$items',
                'NeuroSYS\Support\TypedItems::$steps',
                'NeuroSYS\Support\TypedItems::toArray()',
                'NeuroSYS\Support\TypedItems::toKeys()',
                'NeuroSYS\Support\TypedItems::toValues()',
                'NeuroSYS\View\ImprintView::varyOn()',
                'NeuroSYS\View\PrivacyView::varyOn()',
                'NeuroSYS\View\ReleaseView::terminalFields()',
                'NeuroSYS\View\Terminal\TerminalField::jsonSerialize()',
                'NeuroSYS\View\View::accented()',
                'NeuroSYS\View\View::varyOn()',
                'NeuroSYS\View\Wordmark::nodes()',
            ],
            array_keys(self::bareArrays()['excused']),
        );
    }

    /**
     * Every string literal under `src/` that has a name already carries an excuse.
     *
     * The rule is not "no literals". A tagline, a heading and an exception message are all text and
     * none of them is a name; requiring an argument for each would produce four hundred arguments
     * and nobody would read the four that mattered. It catches the two shapes where a literal
     * genuinely is a vocabulary written out:
     *
     * - **A word an enum in reach already spells.** If the file names {@link
     *   \NeuroSYS\Http\HttpMethod} anywhere, `'GET'` in it is that enum's case as text. This is the
     *   clause that found the one real drift here — `Request::fromGlobals()` defaulted to a
     *   `'GET'` that `HttpMethod::Get` had spelled all along.
     * - **A word written in two classes.** One occurrence is a value; the same one in another file
     *   is a fact with two spellings and nothing keeping them in step. Two *files* rather than two
     *   lines, deliberately: a literal repeated inside one class is on one screen and has `const`
     *   waiting for it, where the failures this codebase is written against are always the two
     *   halves of something in two files, "neither knowing about the other".
     *
     * Enum declarations are exempt outright — that is where a vocabulary is supposed to live — and
     * so is anything with no letter or digit in it. `'/'`, `', '` and `"\n"` are structure, and a
     * name for them would read worse than they do.
     *
     * An attribute's own arguments are exempt too, which is not a special case so much as the same
     * one: a reason is prose about the code, like a docblock, and the two attributes below were
     * caught spelling half a sentence identically before that was true.
     *
     * @return void
     */
    public function testEveryBareStringIsExcused(): void
    {
        self::assertSame(
            [],
            self::bareStrings()['unexcused'],
            'a literal that a vocabulary already spells, with no #[BareString] saying otherwise',
        );
    }

    /**
     * These, and only these, stay literals.
     *
     * Three kinds again, and every one of them is a coincidence rather than a shortcut — which is
     * the point of listing them: each is a word that *looks* like a name and is not.
     *
     * - **Copy.** `artist`, `status`, `releases`, `error` are captions a reader sees. Two pages
     *   using the same caption is two designs agreeing, and either is free to stop; the thing that
     *   does have to be one fact is the address under the link, which is a {@link
     *   \NeuroSYS\Support\SitePath} case.
     * - **Another grammar.** `%d:%02d` is a printf format, and `#^https://…#i` is a regex — the
     *   only entry here that is genuinely one fact in two files, kept apart on purpose. See
     *   {@link \NeuroSYS\Http\Location}, where the argument is made and can be re-read.
     * - **Someone else's vocabulary.** `int` and `string` are `get_debug_type()`'s spellings, in a
     *   class-string's place; `time` is a JSON key on one side of the pair and a caption on the
     *   other.
     *
     * @return void
     */
    public function testExactlyTheseStringsStayBare(): void
    {
        self::assertSame(
            [
                'NeuroSYS\Http\Location #^https://[^\s/]+(?:[/?\#]\S*)?\z#i',
                'NeuroSYS\Layout releases',
                'NeuroSYS\Model\DemoTrack %d:%02d',
                'NeuroSYS\Model\Production\Section %d:%02d',
                'NeuroSYS\Model\Profile #^https://[^\s/]+(?:[/?\#]\S*)?\z#i',
                'NeuroSYS\Service\DownloadLogEntry time',
                'NeuroSYS\Service\DownloadStats int',
                'NeuroSYS\Support\TypedItems int',
                'NeuroSYS\Support\TypedItems string',
                'NeuroSYS\View\DemoView artist',
                'NeuroSYS\View\DemoView status',
                'NeuroSYS\View\NotFoundView error',
                'NeuroSYS\View\ReleaseView artist',
                'NeuroSYS\View\ReleaseView status',
                'NeuroSYS\View\ReleaseView time',
                'NeuroSYS\View\ReleasesView releases',
                'NeuroSYS\View\Terminal\TerminalCommand string',
            ],
            array_keys(self::bareStrings()['excused']),
        );
    }

    /**
     * No excuse outlives the thing it excused.
     *
     * This is the direction that keeps the two lists above honest rather than merely long. A
     * `#[BareArray]` on a method that now returns a collection, or a `#[BareString]` naming a word
     * the class no longer contains, is a sentence about code that is not there — and it reads as
     * true, because it did use to be.
     *
     * @return void
     */
    public function testNoExcuseOutlivesWhatItExcused(): void
    {
        self::assertSame([], self::bareArrays()['stale'], '#[BareArray] on something that is not one');
        self::assertSame([], self::bareStrings()['stale'], '#[BareString] naming a literal that is gone');
    }

    /**
     * An excuse with a hole in it is refused where it is written.
     *
     * The lists above report a fault against a set; the constructors report it against the line
     * that is wrong, which is the same division of labour {@link \NeuroSYS\Model\Link\HiDriveLink}
     * has with its own tests. A bare `#[BareArray]` would say the array is deliberate — which the
     * reader already suspected — where what is worth saying is which door it is.
     *
     * @return void
     */
    public function testAnExcuseMustExplainItself(): void
    {
        $holes = [
            'an array excused without a reason'   => static fn(): object => new BareArray(''),
            'a literal excused without a reason'  => static fn(): object => new BareString('x', ''),
            'a reason attached to no literal'     => static fn(): object => new BareString('', 'why'),
        ];

        foreach ($holes as $what => $construct) {
            try {
                $construct();
                self::fail($what . ' was accepted');
            } catch (InvalidArgumentException $refused) {
                self::assertNotSame('', $refused->getMessage(), $what . ' threw without saying what');
            }
        }
    }

    /**
     * Every file under `src/` declares strict types.
     *
     * At zero today, and here because the failure is silent in the worst way: without it a `string`
     * parameter accepts an `int` and coerces, so every type this codebase spent its effort on
     * becomes a suggestion in exactly one file and nothing says so.
     *
     * @return void
     */
    public function testEveryFileUnderSrcDeclaresStrictTypes(): void
    {
        $missing = [];

        foreach (self::sourceTree() as $path => $class) {
            $strict = false;

            foreach (PhpToken::tokenize(file_get_contents($path)) as $token) {
                if ($token->id === T_DECLARE) {
                    $strict = true;
                    break;
                }
            }

            if (!$strict) {
                $missing[] = $class;
            }
        }

        self::assertSame([], $missing, 'declare(strict_types=1) is missing');
    }

    /**
     * Every declaration under `src/` says what it is.
     *
     * Parameters, returns and properties. `__construct` is excused its return type, which PHP does
     * not let it have; engine-synthesised members — an enum's `cases()`, `from()`, `tryFrom()` —
     * are not ours and are skipped by asking whether they came from a file.
     *
     * @return void
     */
    public function testEveryDeclarationUnderSrcCarriesAType(): void
    {
        $untyped = [];

        foreach (self::sourceTree() as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || !$method->isUserDefined()) {
                    continue;
                }

                if (!$method->hasReturnType() && $method->getName() !== '__construct') {
                    $untyped[] = $class . '::' . $method->getName() . '() has no return type';
                }

                foreach ($method->getParameters() as $parameter) {
                    if (!$parameter->hasType()) {
                        $untyped[] = $class . '::' . $method->getName() . '($' . $parameter->getName() . ')';
                    }
                }
            }

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $class && !$property->hasType()) {
                    $untyped[] = $class . '::$' . $property->getName();
                }
            }
        }

        self::assertSame([], $untyped, 'a declaration under src/ that states no type');
    }

    /**
     * Every enum under `src/` is backed.
     *
     * A pure enum has no wire form, and every vocabulary here has one — a header name, a tag, an
     * attribute, a path pattern, a byte offset. The one that would hurt most is a mirror: the
     * TypeScript side of {@link \NeuroSYS\View\Html\Tag} and friends compares *values*, so a case
     * with none is a parity test with nothing to compare.
     *
     * @return void
     */
    public function testEveryEnumUnderSrcIsBacked(): void
    {
        $pure = [];

        foreach (self::sourceTree() as $class) {
            if (enum_exists($class) && !new ReflectionEnum($class)->isBacked()) {
                $pure[] = $class;
            }
        }

        self::assertSame([], $pure, 'an enum under src/ with no backing value');
    }

    /**
     * Every file under `src/`, as `path => class-string`, sorted by path.
     *
     * Not the walk {@link NoDiscardTest::classesUnderSrc()} does, and deliberately not shared with
     * it: this one keeps the path, because half of what is checked here is read by the tokenizer
     * rather than by reflection, and a `ReflectionClass` cannot be asked what its file's tokens
     * were. The mapping is the autoloader's own, so a file this cannot name is one the site could
     * not have loaded either.
     *
     * @return array<string, class-string>
     */
    private static function sourceTree(): array
    {
        if (self::$tree !== null) {
            return self::$tree;
        }

        $root  = NEUROSYS_ROOT . '/src/NeuroSYS/';
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        $tree = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root), -strlen('.php'));
            $class    = 'NeuroSYS\\' . str_replace('/', '\\', $relative);

            if (
                class_exists($class)
                || interface_exists($class)
                || enum_exists($class)
                || trait_exists($class)
            ) {
                /** @var class-string $class */
                $tree[$file->getPathname()] = $class;
            }
        }

        ksort($tree);

        return self::$tree = $tree;
    }

    /**
     * True if $type is, or contains, `array`.
     *
     * A union counts: `array|false` is still an array on the branch that matters, and
     * {@link \NeuroSYS\Support\Route::matches()} is exactly that shape.
     *
     * @param ?ReflectionType $type
     * @return bool
     */
    private static function namesAnArray(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName() === 'array';
        }

        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (self::namesAnArray($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every `array` in a declared type under `src/`, judged against its `#[BareArray]`.
     *
     * `excused` is keyed `Class::member()` and valued by the reason; `unexcused` and `stale` are
     * lists of names, which is what an assertion against `[]` prints usefully.
     *
     * @return array{excused: array<string, string>, unexcused: list<string>, stale: list<string>}
     */
    private static function bareArrays(): array
    {
        $excused = [];
        $unexcused = [];
        $stale = [];

        foreach (self::sourceTree() as $class) {
            $reflection = new ReflectionClass($class);
            $declared   = [];

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || !$method->isUserDefined()) {
                    continue;
                }

                $bare = self::namesAnArray($method->getReturnType());

                foreach ($method->getParameters() as $parameter) {
                    // A variadic is PHP's own check and needs no excuse; see the rule this test
                    // states about what a class takes versus what it stores.
                    if (!$parameter->isVariadic() && self::namesAnArray($parameter->getType())) {
                        $bare = true;
                    }
                }

                $declared[$class . '::' . $method->getName() . '()'] = [$bare, $method];
            }

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                // An enum's $name and $value are the engine's, not this codebase's.
                if ($reflection->isEnum() && in_array($property->getName(), ['name', 'value'], true)) {
                    continue;
                }

                $declared[$class . '::$' . $property->getName()] = [
                    self::namesAnArray($property->getType()),
                    $property,
                ];
            }

            foreach ($declared as $name => [$bare, $member]) {
                $attributes = $member->getAttributes(BareArray::class);

                if ($bare && $attributes === []) {
                    $unexcused[] = $name;
                } elseif ($bare) {
                    $excused[$name] = $attributes[0]->newInstance()->reason;
                } elseif ($attributes !== []) {
                    $stale[] = $name;
                }
            }
        }

        ksort($excused);
        sort($unexcused);
        sort($stale);

        return ['excused' => $excused, 'unexcused' => $unexcused, 'stale' => $stale];
    }

    /**
     * Every literal under `src/` that a vocabulary already spells, judged against its
     * `#[BareString]`.
     *
     * Two passes, because the second clause is a question about the whole tree: the first collects
     * what every file writes, the second asks of each literal whether an enum the file names can
     * spell it, or whether another class writes it too.
     *
     * @return array{excused: array<string, string>, unexcused: list<string>, stale: list<string>}
     */
    private static function bareStrings(): array
    {
        $written = [];
        $named   = [];
        $shorts  = [];

        foreach (self::sourceTree() as $path => $class) {
            if (enum_exists($class)) {
                $shorts[substr($class, strrpos($class, '\\') + 1)] = $class;
                continue;
            }

            [$written[$class], $named[$class]] = self::read($path);
        }

        $writers = [];

        foreach ($written as $class => $literals) {
            foreach ($literals as $literal) {
                $writers[$literal][$class] = true;
            }
        }

        $excused = [];
        $unexcused = [];
        $stale = [];

        foreach ($written as $class => $literals) {
            $vocabulary = [];

            foreach ($named[$class] as $mentioned => $_) {
                $enum = $shorts[$mentioned] ?? null;

                if ($enum === null || !new ReflectionEnum($enum)->isBacked()) {
                    continue;
                }

                foreach ($enum::cases() as $case) {
                    if (is_string($case->value)) {
                        $vocabulary[$case->value] = $enum . '::' . $case->name;
                    }
                }
            }

            $bare = [];

            foreach (array_unique($literals) as $literal) {
                if (isset($vocabulary[$literal])) {
                    $bare[$literal] = $vocabulary[$literal] . ' spells it';
                } elseif (count($writers[$literal]) > 1) {
                    $bare[$literal] = 'also written in ' . implode(
                        ', ',
                        array_diff(array_keys($writers[$literal]), [$class]),
                    );
                }
            }

            $declared = [];

            foreach (new ReflectionClass($class)->getAttributes(BareString::class) as $attribute) {
                $instance = $attribute->newInstance();
                $declared[$instance->literal] = $instance->reason;
            }

            foreach ($bare as $literal => $why) {
                if (isset($declared[$literal])) {
                    $excused[$class . ' ' . $literal] = $declared[$literal];
                } else {
                    $unexcused[] = $class . ' ' . var_export($literal, true) . ' — ' . $why;
                }
            }

            foreach (array_keys($declared) as $literal) {
                if (!isset($bare[$literal])) {
                    $stale[] = $class . ' ' . var_export($literal, true);
                }
            }
        }

        ksort($excused);
        sort($unexcused);
        sort($stale);

        return ['excused' => $excused, 'unexcused' => $unexcused, 'stale' => $stale];
    }

    /**
     * One file's word-shaped literals and the bare names it mentions.
     *
     * Literals inside an attribute's arguments are skipped: a reason is prose about the code, the
     * way a docblock is, and the two attributes this test depends on were caught by their own rule
     * sharing half a sentence before that was true. The depth count opens on `#[` and closes on the
     * matching `]`, which is the only bracket an attribute's arguments can nest.
     *
     * The names are every bare `T_STRING` in the file, which is how a vocabulary is found to be "in
     * reach": if a file writes `HttpMethod` anywhere at all, it could have written
     * `HttpMethod::Get`. Reading the `use` block instead would have been narrower and would miss a
     * grouped or aliased import; this misses nothing and costs a few coincidences, which is the
     * right way round for a rule whose exceptions are read one at a time.
     *
     * @param string $path
     * @return array{list<string>, array<string, true>}
     */
    private static function read(string $path): array
    {
        $literals = [];
        $names    = [];
        $depth    = 0;

        foreach (PhpToken::tokenize(file_get_contents($path)) as $token) {
            if ($token->id === T_ATTRIBUTE) {
                $depth++;
                continue;
            }

            if ($depth > 0) {
                $depth += (int) ($token->text === '[') - (int) ($token->text === ']');
                continue;
            }

            if ($token->id === T_STRING) {
                $names[$token->text] = true;
                continue;
            }

            if ($token->id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = self::decode($token->text);

            // No letter and no digit is punctuation — structure rather than a name. `'/'`, `', '`
            // and `"\n"` are the whole of what this drops, and naming any of them would read worse.
            if (preg_match('/[A-Za-z0-9]/', $literal) === 1) {
                $literals[] = $literal;
            }
        }

        return [$literals, $names];
    }

    /**
     * A `T_CONSTANT_ENCAPSED_STRING`'s text, as the value it stands for.
     *
     * Compared as values rather than as source, so `'x'` and `"x"` are one literal and `"\n"` is
     * one character rather than two. A double-quoted token that interpolates is not this token
     * type at all, so `stripcslashes()` has nothing to get wrong.
     *
     * @param string $text
     * @return string
     */
    private static function decode(string $text): string
    {
        $body = substr($text, 1, -1);

        return $text[0] === "'"
            ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
            : stripcslashes($body);
    }
}
