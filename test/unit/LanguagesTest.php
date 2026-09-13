<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Site;
use Phpanta\Text\Language;
use Phpanta\Text\Languages;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The languages this site offers. How a {@link Languages} narrows and orders is the framework's to
 * test; which languages it holds, and which comes first, is this site's.
 */
#[CoversClass(Languages::class)]
final class LanguagesTest extends TestCase
{
    /**
     * This site is written in English and German, English first.
     *
     * @return void
     */
    public function testTheSiteOffersEnglishThenGerman(): void
    {
        self::assertSame(
            [Language::English, Language::German],
            Site::current()->languages()->offered()->toValues(),
        );
    }
}
