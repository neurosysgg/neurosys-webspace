<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use BackedEnum;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Text\DemoText;
use NeuroSYS\Text\ErrorText;
use NeuroSYS\Text\HomeText;
use NeuroSYS\Text\LayoutText;
use NeuroSYS\Text\ProfileText;
use NeuroSYS\Text\ReleaseDescription;
use NeuroSYS\Text\ReleaseText;
use NeuroSYS\Text\TerminalText;
use NeuroSYS\Tool\Command\AppData;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Tag;
use Phpanta\Text\Language;
use Phpanta\Tool\Cli\ExitCode;
use Phpanta\Tool\Cli\Output;
use Phpanta\Tool\Cli\Runner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * tools/app-data.php: what the C app is compiled with, printed from the PHP site. The files it
 * prints are committed; these tests read them as the C compiler will, and ask the command whether
 * they are still what it would write. Whether the pages the C builds out of them are the PHP's is
 * test/cserve/diff.sh's question, not this file's.
 */
final class AppDataTest extends TestCase
{
    private const string GEN = __DIR__ . '/../../app/gen/';

    /**
     * @param list<string> $arguments
     * @return array{ExitCode, string}
     */
    private static function invoke(array $arguments): array
    {
        $out   = fopen('php://memory', 'r+b');
        $error = fopen('php://memory', 'r+b');

        $status = Runner::execute(new AppData(), $arguments, new Output($out, $error));

        rewind($error);

        return [$status, (string) stream_get_contents($error)];
    }

    /**
     * @return void
     */
    public function testTheCommittedFilesAreWhatItWouldWrite(): void
    {
        [$status, $error] = self::invoke(['--check']);

        self::assertSame(ExitCode::Success, $status, $error);
    }

    /**
     * @return void
     */
    public function testAnOperandIsRefused(): void
    {
        [$status] = self::invoke(['app/gen']);

        self::assertSame(ExitCode::Usage, $status);
    }

    /**
     * Every case of every catalog the pages say anything from is a T(...) line, its English and its
     * German written out — the C macro takes both, so one missing would not compile, and this says
     * the PHP's words are the ones it took.
     *
     * @param class-string<BackedEnum&\Phpanta\Text\Translatable> $catalog
     * @return void
     */
    #[DataProvider('catalogProvider')]
    public function testEveryCatalogCaseIsPrintedInBothLanguages(string $catalog): void
    {
        $texts = (string) file_get_contents(self::GEN . 'texts.c');

        foreach ($catalog::cases() as $case) {
            $translation = $case->translation();
            self::assertStringContainsString(
                self::c($translation->pattern(Language::English)) . ', '
                . self::c($translation->pattern(Language::German)) . ');',
                $texts,
                "$catalog::{$case->name}",
            );
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function catalogProvider(): iterable
    {
        $catalogs = [LayoutText::class, HomeText::class, ReleaseText::class, ReleaseDescription::class,
            MusicalKey::class, DemoText::class, ErrorText::class, TerminalText::class, ProfileText::class];
        foreach ($catalogs as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @return void
     */
    public function testEveryElementAndClassOfTheSiteIsDeclared(): void
    {
        $vocab = (string) file_get_contents(self::GEN . 'vocab.h');

        foreach (Tag::cases() as $tag) {
            self::assertStringContainsString('ns_tag_' . str_replace('-', '_', $tag->value) . ';', $vocab);
        }
        foreach (CssClass::cases() as $class) {
            self::assertStringContainsString(self::c($class->value), $vocab);
        }
    }

    /**
     * The lint c-serve runs over an app refuses a string holding a tag outside kit/markup.c; what
     * is printed escapes every '<', so the privacy policy's text is never mistaken for markup.
     *
     * @return void
     */
    public function testNothingPrintedHoldsAnOpeningAngleBracket(): void
    {
        foreach (glob(self::GEN . '*') ?: [] as $path) {
            $code = (string) preg_replace('/^#include <.*$/m', '', (string) file_get_contents($path));
            self::assertStringNotContainsString('<', $code, basename($path));
        }
    }

    /**
     * The command's own escaping, read back: printable ASCII as itself, anything else three-digit
     * octal.
     *
     * @param string $s
     * @return string
     */
    private static function c(string $s): string
    {
        $out = '"';
        foreach (str_split($s) as $ch) {
            $out .= preg_match('/[A-Za-z0-9 _.,:;=+\-\/*#&>()\[\]{}!%@^~|$`\']/', $ch) === 1
                ? $ch
                : sprintf('\\%03o', ord($ch));
        }

        return $out . '"';
    }
}
