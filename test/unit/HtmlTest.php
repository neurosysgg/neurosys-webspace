<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Exception\MarkupException;
use NeuroSYS\View\Html\CoverArtAttribute;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Doctype;
use NeuroSYS\View\Html\Document;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Fragment;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\RawHtml;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\Html\Text;
use NeuroSYS\View\Terminal\TerminalAttribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The markup tree: every page is one of these, so what it can and cannot do is what the site can
 * and cannot emit.
 */
#[CoversClass(Element::class)]
#[CoversClass(Text::class)]
#[CoversClass(RawHtml::class)]
#[CoversClass(Fragment::class)]
#[CoversClass(Document::class)]
#[CoversClass(Doctype::class)]
#[CoversClass(HtmlTag::class)]
#[CoversClass(HtmlAttribute::class)]
#[CoversClass(CssClass::class)]
final class HtmlTest extends TestCase
{
    // ───────────────────────────── attributes ─────────────────────────────

    public function testAnElementRendersItsTagAndAttributes(): void
    {
        self::assertSame(
            '<cover-art src="/a.png" alt="a"></cover-art>',
            new Element(Tag::CoverArt)
                ->attr(CoverArtAttribute::Src, '/a.png')
                ->attr(CoverArtAttribute::Alt, 'a')
                ->render(),
        );
    }

    /**
     * The reason this class exists. Escaping used to be a htmlspecialchars() call per attribute at
     * every call site, and forgetting one is an injection — so it happens here, once, or not at all.
     */
    public function testAnAttributeValueCannotBreakOutOfItsAttribute(): void
    {
        $html = new Element(Tag::CoverArt)
            ->attr(CoverArtAttribute::Alt, '" onload="alert(1)')
            ->render();

        self::assertSame('<cover-art alt="&quot; onload=&quot;alert(1)"></cover-art>', $html);
    }

    /** true is a bare attribute, '' is a real empty value, and the two must not collapse. */
    public function testABooleanAttributeIsBareAndAnEmptyValueIsNot(): void
    {
        self::assertSame(
            '<terminal-window command="" narrow></terminal-window>',
            new Element(Tag::TerminalWindow)
                ->attr(TerminalAttribute::Command, '')
                ->attr(TerminalAttribute::Narrow)
                ->render(),
        );
    }

    public function testFalseAndNullBothLeaveTheAttributeOffEntirely(): void
    {
        self::assertSame(
            '<terminal-window></terminal-window>',
            new Element(Tag::TerminalWindow)
                ->attr(TerminalAttribute::Narrow, false)
                ->attr(TerminalAttribute::Command, null)
                ->render(),
        );
    }

    public function testAnIntegerValueRendersAsItsDigits(): void
    {
        self::assertSame(
            '<img height="56">',
            new Element(HtmlTag::Img)->attr(HtmlAttribute::Height, 56)->render(),
        );
    }

    public function testTheSameAttributeTwiceKeepsTheLastValueAndItsPosition(): void
    {
        self::assertSame(
            '<cover-art src="/b.png" alt="a"></cover-art>',
            new Element(Tag::CoverArt)
                ->attr(CoverArtAttribute::Src, '/a.png')
                ->attr(CoverArtAttribute::Alt, 'a')
                ->attr(CoverArtAttribute::Src, '/b.png')
                ->render(),
        );
    }

    /** Immutable like the policies and the collections — every builder method returns a copy. */
    public function testBuildingDoesNotMutateTheElementBuiltFrom(): void
    {
        $empty = new Element(Tag::CoverArt);
        $empty->attr(CoverArtAttribute::Src, '/a.png');
        $empty->containing('x');

        self::assertSame('<cover-art></cover-art>', $empty->render());
    }

    // ───────────────────────────── content ─────────────────────────────

    public function testTextIsEscaped(): void
    {
        self::assertSame('rock &amp; &lt;roll&gt;', new Text('rock & <roll>')->render());
    }

    /**
     * The safe reading of the ambiguous case: a string child is content, never markup. Markup
     * passed as a string shows up as visible &lt;b&gt; — wrong on the page, but visibly wrong,
     * which is the failure mode to prefer.
     */
    public function testAStringChildIsEscapedTextRatherThanMarkup(): void
    {
        self::assertSame(
            '<p>&lt;b&gt;bold&lt;/b&gt;</p>',
            new Element(HtmlTag::P)->containing('<b>bold</b>')->render(),
        );
    }

    public function testAVoidElementHasNoClosingTag(): void
    {
        self::assertSame(
            '<meta charset="UTF-8">',
            new Element(HtmlTag::Meta)->attr(HtmlAttribute::Charset, 'UTF-8')->render(),
        );
    }

    /** <img>text</img> is not markup the browser fixes — it is markup it reinterprets. */
    public function testAVoidElementRefusesChildren(): void
    {
        $this->expectException(MarkupException::class);
        $this->expectExceptionMessage('<img>');

        new Element(HtmlTag::Img)->containing('x');
    }

    /** A custom element is never void: no closing tag means the browser swallows what follows. */
    public function testNoCustomElementIsVoid(): void
    {
        foreach (Tag::cases() as $tag) {
            self::assertFalse($tag->isVoid(), $tag->value . ' must not be void');
        }
    }

    // ───────────────────────────── layout ─────────────────────────────

    /**
     * Whitespace between inline content is content, so an element with any text in it stays on one
     * line. Breaking this would put a space inside 'ill.' — between the name and its accented mark.
     */
    public function testAnElementWithTextInItStaysOnOneLine(): void
    {
        self::assertSame(
            '<h1>ill<span class="bang">.</span></h1>',
            new Element(HtmlTag::H1)->containing(
                'ill',
                new Element(HtmlTag::Span)->attr(HtmlAttribute::ClassName, 'bang')->containing('.'),
            )->render(),
        );
    }

    public function testAnElementOfOnlyElementsPutsEachOnItsOwnLine(): void
    {
        self::assertSame(
            "<section>\n  <p>a</p>\n  <p>b</p>\n</section>",
            new Element(HtmlTag::Section)->containing(
                new Element(HtmlTag::P)->containing('a'),
                new Element(HtmlTag::P)->containing('b'),
            )->render(),
        );
    }

    /** Every node is handed its depth and indents its own continuation lines, at any nesting. */
    public function testNestingIndentsAllTheWayDown(): void
    {
        self::assertSame(
            "<section>\n  <nav>\n    <p>a</p>\n  </nav>\n</section>",
            new Element(HtmlTag::Section)->containing(
                new Element(HtmlTag::Nav)->containing(new Element(HtmlTag::P)->containing('a')),
            )->render(),
        );
    }

    public function testAnEmptyElementIsOpenedAndClosedOnOneLine(): void
    {
        self::assertSame('<section></section>', new Element(HtmlTag::Section)->render());
    }

    // ───────────────────────────── fragments and documents ─────────────────────────────

    public function testAFragmentRendersItsNodesWithNoWrapper(): void
    {
        self::assertSame(
            "<p>a</p>\n<p>b</p>",
            new Fragment(
                new Element(HtmlTag::P)->containing('a'),
                new Element(HtmlTag::P)->containing('b'),
            )->render(),
        );
    }

    public function testAFragmentInsideAnElementIndentsWithIt(): void
    {
        self::assertSame(
            "<section>\n  <p>a</p>\n  <p>b</p>\n</section>",
            new Element(HtmlTag::Section)->containing(
                new Fragment(
                    new Element(HtmlTag::P)->containing('a'),
                    new Element(HtmlTag::P)->containing('b'),
                ),
            )->render(),
        );
    }

    public function testFragmentEachMapsItemsToNodes(): void
    {
        self::assertSame(
            "<p>a</p>\n<p>b</p>",
            Fragment::each(
                ['a', 'b'],
                static fn(string $t): Element => new Element(HtmlTag::P)->containing($t),
            )->render(),
        );
    }

    public function testADocumentLeadsWithTheDoctype(): void
    {
        self::assertSame(
            "<!DOCTYPE html>\n<html></html>",
            new Document(new Element(HtmlTag::Html))->render(),
        );
    }

    /** Quirks mode is what a wrong one buys, silently, on every layout calculation on the page. */
    public function testTheDoctypeIsHtml5AndThereIsOnlyOne(): void
    {
        self::assertSame([Doctype::Html5], Doctype::cases());
        self::assertSame('<!DOCTYPE html>', Doctype::Html5->render());
    }

    // ───────────────────────────── class names ─────────────────────────────

    /**
     * The one mirror that can be checked against its actual reader.
     *
     * A misspelled class errors nowhere — the element just renders unstyled, which on a dark page
     * reads as a layout bug rather than a typo. Both directions matter and fail differently: a case
     * the stylesheet never mentions is an element styled by nothing, and a selector no case names is
     * a rule that can never match. The second is how dead CSS accumulates.
     */
    public function testEveryClassNameIsStyledAndEveryStyledClassIsNamed(): void
    {
        $declared = array_map(static fn(CssClass $c): string => $c->value, CssClass::cases());
        $styled   = self::classSelectors();

        sort($declared);
        sort($styled);

        self::assertNotEmpty($styled, 'found no class selectors at all — the scan is broken');
        self::assertSame($declared, $styled);
    }

    /** @return list<string> Every class the stylesheet selects on, comments stripped first. */
    private static function classSelectors(): array
    {
        $css = file_get_contents(NEUROSYS_ROOT . '/public/assets/css/style.css');

        self::assertIsString($css);

        // Comments first: this file's own header names .out and .dot, which no rule has used since
        // the terminal moved client-side, and a scan that counted those would be measuring prose.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';

        preg_match_all('/\.([a-z][a-z0-9-]*)/', $css, $matches);

        return array_values(array_unique($matches[1]));
    }

    // ───────────────────────────── the audited hole ─────────────────────────────

    public function testRawHtmlIsNotEscaped(): void
    {
        self::assertSame('<b>bold</b>', new RawHtml('<b>bold</b>')->render());
    }

    /** It still indents, so a hand-authored document sits where it was placed. */
    public function testRawHtmlIndentsToWhereItWasPlaced(): void
    {
        self::assertSame(
            "<section>\n  <h1>a</h1>\n  <p>b</p>\n</section>",
            new Element(HtmlTag::Section)->containing(new RawHtml("<h1>a</h1>\n<p>b</p>"))->render(),
        );
    }

    /**
     * The audit. RawHtml is the one place markup goes out unchecked, so its call sites are pinned
     * rather than trusted: a second one has to be argued for here, in a test named for the fact.
     */
    public function testRawHtmlIsConstructedInExactlyOnePlace(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(NEUROSYS_ROOT . '/src', RecursiveDirectoryIterator::SKIP_DOTS),
        );

        $callers = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if ($source !== false && str_contains($source, 'new RawHtml(')) {
                $callers[] = $file->getFilename();
            }
        }

        sort($callers);

        self::assertSame(['PrivacyView.php'], $callers);
    }
}
