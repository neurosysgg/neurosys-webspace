<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use MessageFormatter;
use NeuroSYS\Text\Language;
use NeuroSYS\Text\Texts;
use NeuroSYS\Text\Translatable;
use NeuroSYS\Text\Translated;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
     * Every case of every catalog in the index.
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

        self::assertTrue($translation->has(Language::German), 'no German: it would fall back to English');

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
     * The index names every catalog there is — the provider above reads the index, so a catalog
     * missing from it would be one nothing checks.
     *
     * @return void
     */
    public function testTheIndexNamesEveryCatalog(): void
    {
        $found = [];

        foreach (glob(NEUROSYS_ROOT . '/src/NeuroSYS/Text/*.php') ?: [] as $file) {
            $class = 'NeuroSYS\Text\\' . basename($file, '.php');

            if (enum_exists($class) && in_array(Translated::class, class_uses($class), true)) {
                $found[] = $class;
            }
        }

        $indexed = self::catalogs();

        sort($found);
        sort($indexed);

        self::assertSame($found, $indexed);
    }

    /**
     * @return list<class-string<UnitEnum&Translatable>>
     */
    private static function catalogs(): array
    {
        return array_values(new ReflectionClass(Texts::class)->getConstants());
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
