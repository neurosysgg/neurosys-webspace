<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Exception\TranslationException;
use NeuroSYS\Text\Language;
use NeuroSYS\Text\Phrase;
use NeuroSYS\Text\Translated;
use NeuroSYS\Text\Translation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;

/**
 * The text layer: a translation, a catalog case, and a phrase with its arguments bound.
 *
 * How the markup tree decides which language one renders in is {@link HtmlTest}'s.
 */
#[CoversClass(Translation::class)]
#[CoversClass(Phrase::class)]
#[CoversTrait(Translated::class)]
final class TextTest extends TestCase
{
    /**
     * @return void
     */
    public function testATranslationAnswersInEachLanguageAndGermanFallsBackToEnglish(): void
    {
        $both    = new Translation(en: 'downloads', de: 'Downloads');
        $english = new Translation(en: 'downloads');

        self::assertSame('downloads', $both->in(Language::English));
        self::assertSame('Downloads', $both->in(Language::German));
        self::assertSame('downloads', $english->in(Language::German), 'German falls back to English');

        self::assertTrue($both->has(Language::German));
        self::assertFalse($english->has(Language::German), 'a fallback is not a translation');
        self::assertTrue($english->has(Language::English));
    }

    /**
     * English is the language every other falls back to, so a translation without it has nothing to
     * fall back on.
     *
     * @return void
     */
    public function testATranslationWithNoEnglishIsRefused(): void
    {
        $this->expectException(TranslationException::class);

        new Translation(en: '  ', de: 'etwas');
    }

    /**
     * Unbound text is shown as written, so a description with a brace in it cannot fail to render.
     *
     * @return void
     */
    public function testUnboundTextIsLiteralEvenWhereItLooksLikeAMessage(): void
    {
        self::assertSame("it's {not} a message", new Translation("it's {not} a message")->in(Language::German));
    }

    /**
     * A catalog case is its attribute's text, read once and then kept.
     *
     * @return void
     */
    public function testACaseIsItsOwnAttributesText(): void
    {
        self::assertSame('Downloads', TextFixture::Plain->in(Language::German));
        self::assertSame('downloads', TextFixture::Plain->in(Language::English));
        self::assertSame('only in English', TextFixture::EnglishOnly->in(Language::German));
        self::assertSame(TextFixture::Plain->translation(), TextFixture::Plain->translation(), 'read once per case');
    }

    /**
     * @return void
     */
    public function testACaseWithNoTranslationIsLoud(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('TextFixture::Untranslated has no #[Translation]');

        TextFixture::Untranslated->in(Language::English);
    }

    /**
     * The plural form and the number both follow the language — which is the whole of what ICU is
     * here for.
     *
     * @return void
     */
    public function testAPhraseFormatsItsArgumentsByTheLanguagesOwnRules(): void
    {
        self::assertSame('1 download', TextFixture::Counted->with(count: 1)->in(Language::English));
        self::assertSame('3 downloads', TextFixture::Counted->with(count: 3)->in(Language::English));
        self::assertSame('1,000 downloads', TextFixture::Counted->with(count: 1000)->in(Language::English));
        self::assertSame('1.000 Downloads', TextFixture::Counted->with(count: 1000)->in(Language::German));
    }

    /**
     * @return void
     */
    public function testAPhraseIcuCannotFormatIsLoud(): void
    {
        $this->expectException(TranslationException::class);
        $this->expectExceptionMessage('is not a message ICU can format in English');

        new Phrase(new Translation('{count, plural,'), ['count' => 1])->in(Language::English);
    }
}
