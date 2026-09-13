<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use FilesystemIterator;
use MessageFormatter;
use NeuroSYS\Text\ReleaseDescription;
use NeuroSYS\Text\Texts;
use Phpanta\Text\Language;
use Phpanta\Text\Translatable;
use Phpanta\Text\Translated;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use UnitEnum;

/**
 * Every word the site says, in both languages.
 *
 * The catalogs are code, so this checks what a compiler would check if PHP had one for text: every
 * case carries its words, both languages read as the ICU message they are, a phrase names the same
 * arguments in German as in English, and German is actually written rather than falling back. A
 * missed translation is this test failing, not an English word on a German page.
 */
#[CoversTrait(Translated::class)]
final class TranslationTest extends TestCase
{
    /**
     * The catalogs whose German may fall back to the English: text a release's author writes,
     * possibly before the German exists. See {@link ReleaseDescription}.
     */
    private const array FALLS_BACK = [ReleaseDescription::class];

    /**
     * Every case of every catalog reachable from the index.
     *
     * @return iterable<string, array{UnitEnum&Translatable}>
     */
    public static function caseProvider(): iterable
    {
        foreach (self::catalogs() as $catalog) {
            foreach ($catalog::cases() as $case) {
                yield $case::class . '::' . $case->name => [$case];
            }
        }
    }

    /**
     * @param UnitEnum&Translatable $case
     * @return void
     */
    #[DataProvider('caseProvider')]
    public function testEveryCaseIsWrittenInBothLanguagesAsAMessageIcuCanRead(UnitEnum&Translatable $case): void
    {
        $translation = $case->translation();

        if (!in_array($case::class, self::FALLS_BACK, true)) {
            self::assertTrue($translation->has(Language::German), 'no German: it would fall back to English');
        }

        foreach (Language::cases() as $language) {
            self::assertNotNull(
                MessageFormatter::create($language->value, $translation->pattern($language)),
                "not a message ICU can read in {$language->name}",
            );
        }

        self::assertSame(
            self::arguments($translation->pattern(Language::English)),
            self::arguments($translation->pattern(Language::German)),
            'the two languages name different arguments',
        );
    }

    /**
     * Every translated enum under the site's `src/` is reachable from the index — the provider above
     * walks the index, so one that is not would be one nothing checks. The framework's catalogs are
     * found and checked by its own suite, whether or not an index names them.
     *
     * @return void
     */
    public function testTheIndexReachesEveryTranslatedEnum(): void
    {
        $ours  = static fn(string $class): bool => str_starts_with($class, 'NeuroSYS\\');
        $found = [];

        foreach (SourceTree::classes() as $class) {
            if ($ours($class) && enum_exists($class) && in_array(Translated::class, class_uses($class), true)) {
                $found[] = $class;
            }
        }

        $reached = array_values(array_filter(self::catalogs(), $ours));

        sort($found);
        sort($reached);

        self::assertSame($found, $reached);
    }

    /**
     * A view writes its words as catalog cases, never as literals — so a word nobody translated is
     * this test failing, rather than an English word on a German page.
     *
     * The shape it looks for is the one a word takes: a string literal with a letter in it, passed
     * straight to `containing()`, or as the value of an attribute a person reads — `alt`, `title`,
     * `aria-label`. Punctuation (` · `, ` →`) has no letter and is the same in every language, and a
     * word reached through a variable or a constant is its call site's to answer for — the legal
     * pages' `ImprintView::CONTACT` says why it is one.
     *
     * @return void
     */
    public function testNoViewWritesAWordAsALiteral(): void
    {
        self::assertSame([], self::literalWords());
    }

    /**
     * Every word a view writes as a literal, as `File.php:line 'literal'`.
     *
     * @return list<string>
     */
    private static function literalWords(): array
    {
        $paths = [NEUROSYS_ROOT . '/src/NeuroSYS/Layout.php'];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(NEUROSYS_ROOT . '/src/NeuroSYS/View', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        $found = [];

        foreach ($paths as $path) {
            $tokens = array_values(array_filter(
                token_get_all((string) file_get_contents($path)),
                static fn(array|string $token): bool => !is_array($token)
                    || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
            ));

            foreach ($tokens as $i => $token) {
                $before = $tokens[$i - 1] ?? null;

                if (
                    !is_array($token) || $token[0] !== T_STRING || ($tokens[$i + 1] ?? null) !== '('
                    || !is_array($before)
                    || !in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                ) {
                    continue;
                }

                $arguments = self::argumentsOf($tokens, $i + 1);
                $words     = match ($token[1]) {
                    'containing' => $arguments,
                    'attr'       => self::namesARead($arguments[0]) ? array_slice($arguments, 1) : [],
                    default      => [],
                };

                foreach ($words as $argument) {
                    foreach ($argument as $part) {
                        if (
                            is_array($part) && $part[0] === T_CONSTANT_ENCAPSED_STRING
                            && preg_match('/\p{L}/u', substr($part[1], 1, -1)) === 1
                        ) {
                            $found[] = sprintf('%s:%d %s', basename($path), $part[2], $part[1]);
                        }
                    }
                }
            }
        }

        return $found;
    }

    /**
     * The arguments of the call whose `(` is at $open, each as the tokens written directly in it —
     * not those of a call nested inside, which is found and read on its own.
     *
     * @param list<string|array{int, string, int}> $tokens
     * @param int                                   $open
     * @return list<list<string|array{int, string, int}>>
     */
    private static function argumentsOf(array $tokens, int $open): array
    {
        $arguments = [[]];
        $depth     = 0;
        $count     = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token   = $tokens[$i];
            $opens   = in_array($token, ['(', '[', '{'], true)
                || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
            $closes  = in_array($token, [')', ']', '}'], true);

            if ($opens) {
                $depth++;
            } elseif ($closes) {
                $depth--;
            }

            if ($depth === 0) {
                break;
            }

            if ($depth === 1 && $token === ',') {
                $arguments[] = [];
            } elseif ($depth === 1 && !$opens && !$closes) {
                $arguments[count($arguments) - 1][] = $token;
            }
        }

        return $arguments;
    }

    /**
     * Whether an attribute argument names one a person reads: `…::Alt`, `…::Title`, `…::AriaLabel`.
     *
     * @param list<string|array{int, string, int}> $argument
     * @return bool
     */
    private static function namesARead(array $argument): bool
    {
        $name = end($argument);

        return is_array($name) && in_array($name[1], ['Alt', 'Title', 'AriaLabel'], true);
    }

    /**
     * The catalogs the index names, and any a catalog names in turn — `Texts::Releases::Descriptions`.
     *
     * @return list<class-string<UnitEnum&Translatable>>
     */
    private static function catalogs(): array
    {
        $reached = [];
        $queue   = array_values(new ReflectionClass(Texts::class)->getConstants());

        while ($queue !== []) {
            $class = array_shift($queue);

            if (in_array($class, $reached, true)) {
                continue;
            }

            $reached[] = $class;

            // An enum's constants include its cases, which are objects; a step down is a string.
            foreach (new ReflectionClass($class)->getConstants() as $value) {
                if (is_string($value) && enum_exists($value)) {
                    $queue[] = $value;
                }
            }
        }

        return $reached;
    }

    /**
     * The argument names a message uses, sorted — `{title}`, and `{count, plural, …}`'s `count`.
     *
     * @param string $pattern
     * @return list<string>
     */
    private static function arguments(string $pattern): array
    {
        preg_match_all('/\{\s*(\w+)\s*[,}]/', $pattern, $matches);

        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
