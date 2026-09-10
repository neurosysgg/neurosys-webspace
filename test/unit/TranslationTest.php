<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use FilesystemIterator;
use MessageFormatter;
use NeuroSYS\Text\Language;
use NeuroSYS\Text\ReleaseDescription;
use NeuroSYS\Text\Texts;
use NeuroSYS\Text\Translatable;
use NeuroSYS\Text\Translated;
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
     * Every translated enum under `src/` is reachable from the index — the provider above walks the
     * index, so one that is not would be one nothing checks.
     *
     * @return void
     */
    public function testTheIndexReachesEveryTranslatedEnum(): void
    {
        $root  = NEUROSYS_ROOT . '/src/';
        $found = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = str_replace('/', '\\', substr($file->getPathname(), strlen($root), -4));

            if (enum_exists($class) && in_array(Translated::class, class_uses($class), true)) {
                $found[] = $class;
            }
        }

        $reached = self::catalogs();

        sort($found);
        sort($reached);

        self::assertSame($found, $reached);
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
