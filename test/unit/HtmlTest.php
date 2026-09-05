<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Exception\MarkupException;
use NeuroSYS\Model\Embed\SoundCloudPlayerAttribute;
use NeuroSYS\View\Html\AttributeName;
use NeuroSYS\View\Html\CardAttribute;
use NeuroSYS\View\Html\CoverArtAttribute;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\LinkAttribute;
use NeuroSYS\View\Html\Doctype;
use NeuroSYS\View\Html\Document;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Fragment;
use NeuroSYS\View\Html\HtmlAttribute;
use NeuroSYS\View\Html\HtmlTag;
use NeuroSYS\View\Html\LinkRel;
use NeuroSYS\View\Html\LinkTarget;
use NeuroSYS\View\Html\ScriptType;
use NeuroSYS\View\Html\RawHtml;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\Html\Text;
use NeuroSYS\View\Terminal\TerminalAttribute;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use FilesystemIterator;
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
#[CoversClass(Tag::class)]
#[CoversClass(CardAttribute::class)]
#[CoversClass(CoverArtAttribute::class)]
#[CoversClass(LinkAttribute::class)]
#[CoversClass(TerminalAttribute::class)]
#[CoversClass(SoundCloudPlayerAttribute::class)]
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
        (void) $empty->attr(CoverArtAttribute::Src, '/a.png');
        (void) $empty->containing('x');

        self::assertSame('<cover-art></cover-art>', $empty->render());
    }

    /**
     * A backed enum stands for its value, so a call site passes CssClass::Bang rather than
     * remembering ->value — one fewer thing to get right at twenty call sites.
     */
    public function testABackedEnumValueRendersAsItsBackingValue(): void
    {
        self::assertSame(
            '<p class="bang"></p>',
            new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, CssClass::Bang)->render(),
        );
    }

    /** An enum value is escaped on the same path a string is; nothing gets in around it. */
    public function testABackedEnumValueGoesThroughTheSameEscaping(): void
    {
        $enum = new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, CssClass::Bang)->render();
        $text = new Element(HtmlTag::P)->attr(HtmlAttribute::ClassName, 'bang')->render();

        self::assertSame($text, $enum);
    }

    // ───────────────────────── the names themselves ─────────────────────────

    /**
     * Every attribute name is a case rather than a string typed out at each call site, and
     * {@link AttributeName} is what lets {@link Element} take any of them without knowing which
     * element it is building. A name that renders as something other than its backing value would
     * be a silent null on the client, so assert the two are the same thing.
     */
    #[DataProvider('attributeNameProvider')]
    public function testAnAttributeNameRendersAsItsBackingValue(AttributeName&\BackedEnum $name): void
    {
        // A URL attribute is scheme-checked on the way out, so it gets a value that is one. The
        // name is what this test is about either way; that the check fires is asserted below.
        $value = $name->isUrl() ? '/x' : 'x';

        self::assertSame($name->value, $name->attribute());
        self::assertStringContainsString(
            $name->attribute() . '="' . $value . '"',
            new Element(HtmlTag::P)->attr($name, $value)->render(),
        );
    }

    public static function attributeNameProvider(): iterable
    {
        foreach (
            [
                CardAttribute::class,
                CoverArtAttribute::class,
                LinkAttribute::class,
                TerminalAttribute::class,
                SoundCloudPlayerAttribute::class,
                HtmlAttribute::class,
            ] as $enum
        ) {
            foreach ($enum::cases() as $case) {
                yield $enum . '::' . $case->name => [$case];
            }
        }
    }

    /**
     * A custom element the browser has never heard of renders as an inert inline box with no error
     * anywhere, so the tag name is a contract with `assets/ts/elements/` that fails in silence.
     */
    /**
     * `rel` is the one attribute value on this site that is a set rather than a single fact, so the
     * enum builds the list and the call site does not. Pinned in the order the markup reads.
     */
    public function testLinkRelationsJoinIntoOneAttributeValue(): void
    {
        self::assertSame(
            'noopener noreferrer external',
            LinkRel::tokens(LinkRel::NoOpener, LinkRel::NoReferrer, LinkRel::External),
        );
        self::assertSame('stylesheet', LinkRel::tokens(LinkRel::Stylesheet));
        self::assertSame('', LinkRel::tokens());
    }

    /**
     * A value enum reaches the markup as its backing value with nothing in between —
     * {@link Element::attr()} unwraps any BackedEnum, which is what lets these be passed as cases
     * rather than as `->value` at every call site.
     */
    #[DataProvider('attributeValueProvider')]
    public function testAnAttributeValueRendersAsItsBackingValue(
        AttributeName $attribute,
        \BackedEnum $value,
    ): void {
        self::assertSame(
            '<a ' . $attribute->attribute() . '="' . $value->value . '"></a>',
            new Element(HtmlTag::A)->attr($attribute, $value)->render(),
        );
    }

    /** @return iterable<string, array{AttributeName, \BackedEnum}> */
    public static function attributeValueProvider(): iterable
    {
        foreach (LinkRel::cases() as $case) {
            yield 'LinkRel::' . $case->name => [HtmlAttribute::Rel, $case];
        }
        foreach (LinkTarget::cases() as $case) {
            yield 'LinkTarget::' . $case->name => [HtmlAttribute::Target, $case];
        }
        foreach (ScriptType::cases() as $case) {
            yield 'ScriptType::' . $case->name => [HtmlAttribute::Type, $case];
        }
    }

    public function testEveryCustomTagRendersAsItsBackingValue(): void
    {
        foreach (Tag::cases() as $tag) {
            self::assertSame($tag->value, $tag->tagName());
            self::assertSame("<{$tag->value}></{$tag->value}>", new Element($tag)->render());
        }
    }

    /**
     * Never void. A custom element with no closing tag is a parse error the browser recovers from
     * by swallowing everything after it, which is about as quiet as a failure gets.
     */
    public function testNoCustomTagIsVoid(): void
    {
        foreach (Tag::cases() as $tag) {
            self::assertFalse($tag->isVoid(), "<{$tag->value}> would render without a closing tag");
        }
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
        $this->expectExceptionMessageIsOrContains('<img>');

        (void) new Element(HtmlTag::Img)->containing('x');
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

    // ───────────────────────────── the stylesheet's parts ─────────────────────────────

    /**
     * The other half of the class check above, for the tags — and the half that did not exist.
     *
     * A tag name in the stylesheet is a bare string with nothing on the other end of it. Rename a
     * case here and the PHP↔TS parity test catches the element; the stylesheet just quietly stops
     * matching, and an unstyled custom element on a dark page reads as a layout bug rather than a
     * typo. Both directions again: a case nothing styles is an element with no look, and a selector
     * naming no case is a rule that can never match.
     */
    public function testEveryTagIsStyledAndEveryStyledTagIsATagCase(): void
    {
        $declared = array_map(static fn(Tag $t): string => $t->value, Tag::cases());
        $styled   = self::tagSelectors(self::stylesheet());

        sort($declared);
        sort($styled);

        self::assertNotEmpty($styled, 'found no custom-element selectors at all — the scan is broken');
        self::assertSame($declared, $styled);
    }

    /**
     * Every tag is styled by exactly one part, so "where is this styled?" has one answer.
     *
     * Two parts naming a tag is the failure worth naming: whichever comes later in main.css wins,
     * silently, and the loser reads as a rule that simply does not apply. The build refuses to
     * import a part twice for the same reason; this is the same rule one level down.
     */
    public function testEveryTagIsStyledByExactlyOnePart(): void
    {
        $owners = [];

        foreach (self::elementParts() as $part => $css) {
            foreach (self::tagSelectors($css) as $tag) {
                $owners[$tag][] = $part;
            }
        }

        $shared = array_filter($owners, static fn(array $parts): bool => count($parts) > 1);
        $shared = array_map(static fn(array $parts): string => implode(' and ', $parts), $shared);

        self::assertSame([], $shared, 'styled by more than one part');

        $unowned = array_diff(
            array_map(static fn(Tag $t): string => $t->value, Tag::cases()),
            array_keys($owners),
        );

        self::assertSame([], array_values($unowned), 'named by no part under assets/css/elements/');
    }

    /**
     * A part is named for its component, and card.css is the one that is not.
     *
     * assets/css/elements/ mirrors assets/ts/elements/ at the component level, because there the
     * directory is the component and not the file. So a part named for one may only style tags whose
     * modules live in it — a rule wandering into the wrong file is how a component stops being one.
     *
     * card.css is the single exception, and is meant to be conspicuous the way RawHtml is: the
     * catalogue entry and the download entry genuinely share a look across two component
     * directories. Pinned here, so a second concept-named part has to be argued for in this test.
     */
    public function testAPartStylesOnlyItsOwnComponentAndCardIsTheOneException(): void
    {
        $exceptions = [];
        $strays     = [];

        foreach (self::elementParts() as $part => $css) {
            $component = basename($part, '.css');
            $directory = NEUROSYS_ROOT . '/assets/ts/elements/' . $component;
            $module    = $directory . '.ts';

            if (!is_dir($directory) && !is_file($module)) {
                $exceptions[] = basename($part);

                continue;
            }

            foreach (self::tagSelectors($css) as $tag) {
                if (!is_file(self::moduleFor($tag))) {
                    self::fail("no element module registers <{$tag}>, which {$part} styles");
                }

                if (!str_starts_with(self::moduleFor($tag), $directory)) {
                    $strays[] = "{$part} styles <{$tag}>";
                }
            }
        }

        self::assertSame([], $strays, 'a part styling a tag from another component');
        self::assertSame(['card.css'], $exceptions, 'parts named for a concept rather than a component');
    }

    /** The one file the browser loads, which is the concatenation of every part. */
    private static function stylesheet(): string
    {
        $css = file_get_contents(NEUROSYS_ROOT . '/public/assets/css/style.css');

        self::assertIsString($css);

        return $css;
    }

    /**
     * @return array<string, string> Every part under assets/css/elements/, keyed by its path from
     *                               assets/css/ — the same name main.css imports it under.
     */
    private static function elementParts(): array
    {
        $parts = glob(NEUROSYS_ROOT . '/assets/css/elements/*.css');

        self::assertIsArray($parts);
        self::assertNotEmpty($parts, 'found no element parts at all — the scan is broken');

        $sources = [];

        foreach ($parts as $part) {
            $css = file_get_contents($part);

            self::assertIsString($css);

            $sources['elements/' . basename($part)] = $css;
        }

        ksort($sources);

        return $sources;
    }

    /**
     * The module that registers a tag, or '' if none does.
     *
     * A tag is its class in kebab-case and the class is the file, so this could be derived — but
     * <soundcloud-player> is `SoundCloudPlayer`, which no casing rule produces. Scanning for the
     * file that exists sidesteps the exception rather than spelling it, and it is the same walk the
     * RawHtml check below does.
     */
    private static function moduleFor(string $tag): string
    {
        $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $tag)));

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(NEUROSYS_ROOT . '/assets/ts/elements'),
        );

        foreach ($files as $file) {
            if (strcasecmp($file->getFilename(), $class . '.ts') === 0) {
                return $file->getPathname();
            }
        }

        return '';
    }

    /**
     * @return list<string> Every custom element the given CSS selects on.
     *
     * Selector position only — a hyphenated word is a property name far more often than it is a
     * tag, so `font-family` inside a block must not read as an element. Comments go first for the
     * same reason classSelectors() drops them: this file's prose names tags it does not style.
     */
    private static function tagSelectors(string $css): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        $tags = [];

        preg_match_all('/(^|[;}])([^{}();]+)\{/m', $css, $blocks);

        foreach ($blocks[2] as $selector) {
            if (str_starts_with(ltrim($selector), '@')) {
                continue;
            }

            preg_match_all('/(?:^|[\s,>+~(])([a-z][a-z0-9]*-[a-z0-9-]+)/', $selector, $matches);

            foreach ($matches[1] as $tag) {
                $tags[] = $tag;
            }
        }

        return array_values(array_unique($tags));
    }

    // ─────────────────────── urls, which escaping cannot help with ───────────────────────

    /**
     * The mistake escaping cannot catch, and the reason {@link AttributeName::isUrl()} exists.
     *
     * htmlspecialchars() does its job perfectly on `javascript:alert(document.cookie)` — there is
     * not a single character in it to escape — and the browser then runs it. Whether a URL is safe
     * is a question about its *scheme*, so that is asked separately, and asked at render, where
     * every element passes through however it was built.
     */
    #[DataProvider('refusedUrlProvider')]
    public function testAUrlAttributeRefusesASchemeTheSiteMayNotEmit(string $url): void
    {
        $this->expectException(MarkupException::class);

        new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $url)->render();
    }

    public static function refusedUrlProvider(): iterable
    {
        yield 'javascript'         => ['javascript:alert(1)'];
        yield 'javascript, cased'  => ['JaVaScRiPt:alert(1)'];
        yield 'javascript, spaced' => ['  javascript:alert(1)'];

        // Browsers strip tabs and newlines from inside a scheme before resolving it, which is how
        // this arrives at the parser as `javascript:` regardless. An allowlist never needs to know
        // that, because it is not in the business of recognising the bad ones.
        yield 'javascript, split'  => ["jav\tascript:alert(1)"];

        yield 'data'               => ['data:text/html,alert()'];
        yield 'vbscript'           => ['vbscript:msgbox(1)'];

        // Starts with a slash exactly as `/releases` does, and is a different origin. This is the
        // same trap the SPA router has on the other side; see Navigation.onClick().
        yield 'protocol-relative'  => ['//evil.example/x'];

        // The same URL, spelled the way that does not look like it. The WHATWG parser treats `\`
        // as `/` for as long as it is hunting for an authority, so both of these resolve to
        // https://evil.example — `new URL('/\evil.example/x', 'https://neurosys.gg/')` says so.
        // Listed separately from the one above because guarding `//` alone is how this gets missed.
        yield 'backslash authority'      => ['/\evil.example/x'];
        yield 'backslash authority, deep' => ['/\\\\evil.example'];

        // And the two the enumerated prefix list did not have, which is why there is no longer a
        // list. The WHATWG parser strips tab, CR and LF from a URL *before* parsing it, so by the
        // time anything resolves these they are `//evil.example` — while every "starts with a
        // slash" test, including the one this class used to run, says they are paths on this site.
        // Element asks the parser now, so it refuses whatever the parser calls an authority rather
        // than whatever somebody thought to write down.
        yield 'authority behind a newline' => ["/\r\n/evil.example"];
        yield 'authority behind a tab'     => ["/\t/evil.example"];

        yield 'plaintext http'     => ['http://evil.example/x'];
        yield 'no scheme at all'   => ['evil.example/x'];
        yield 'empty'              => [''];
    }

    #[DataProvider('allowedUrlProvider')]
    public function testAUrlAttributeAllowsWhatTheSiteActuallyEmits(string $url): void
    {
        self::assertStringContainsString(
            'href="' . $url . '"',
            new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $url)->render(),
        );
    }

    public static function allowedUrlProvider(): iterable
    {
        yield 'root'          => ['/'];
        yield 'a page'        => ['/imprint'];
        yield 'a download'    => ['/releases/ill/flac'];
        yield 'an asset'      => ['/assets/css/style.css'];
        yield 'mailto'        => ['mailto:neuro.sys@neurosys.gg'];
        yield 'the file host' => ['https://my.hidrive.com/api/sharelink/download?id=BXRsy9S7d'];
    }

    /**
     * Which attributes are checked, pinned in both directions.
     *
     * An attribute the browser dereferences that nobody marked is a hole with nothing to report it:
     * the check simply would not run, and the page would look right. So the set is asserted here
     * rather than left to each enum's own good judgement, and adding a case that carries an address
     * means adding it to this list too.
     */
    public function testExactlyTheAddressCarryingAttributesAreCheckedAsUrls(): void
    {
        $urls = [];

        foreach (self::attributeNameProvider() as $name => [$case]) {
            if ($case->isUrl()) {
                $urls[] = $name;
            }
        }

        self::assertSame(
            [
                CoverArtAttribute::class . '::Src',
                CoverArtAttribute::class . '::Fallback',
                HtmlAttribute::class . '::Href',
                HtmlAttribute::class . '::Src',
            ],
            $urls,
        );
    }

    /**
     * Both guarantees live in render(), which is what makes this class the boundary it claims to be.
     *
     * An element assembled by handing the constructor an array gets exactly the same treatment as
     * one built through attr(). It did not before: attr() escaped on the way *in* and render()
     * emitted whatever it found, so the constructor was a way around escaping entirely — a public
     * one, documented as taking values that were already escaped and trusted to have been.
     */
    public function testBothGuaranteesHoldHoweverTheElementWasBuilt(): void
    {
        self::assertSame(
            '<p class="&quot; onload=&quot;alert(1)"></p>',
            new Element(HtmlTag::P, ['class' => [HtmlAttribute::ClassName, '" onload="alert(1)']])->render(),
        );

        $this->expectException(MarkupException::class);

        new Element(HtmlTag::A, ['href' => [HtmlAttribute::Href, 'javascript:alert(1)']])->render();
    }

    /**
     * The whole document's escaping is one function call, and this is what keeps it that way.
     *
     * The same audit as the RawHtml pin below, for the same reason: a guarantee spread over two
     * call sites is a guarantee that can be half-changed. {@link Element} escapes its attribute
     * values by rendering a {@link Text} rather than reaching for htmlspecialchars() a second time,
     * so the site has one set of flags and one place to change them.
     */
    public function testEscapingHappensInExactlyOnePlace(): void
    {
        self::assertSame(['Text.php'], self::filesContaining('htmlspecialchars('));
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
        self::assertSame(['PrivacyView.php'], self::filesContaining('new RawHtml('));
    }

    /**
     * The file names under `src/` whose source contains $needle, sorted.
     *
     * How both audits above are done. Reading the source rather than the class graph is the point:
     * what is being asserted is that a second call site does not *exist*, and a call site nobody
     * reaches is still one somebody will reach later.
     *
     * @return list<string>
     */
    private static function filesContaining(string $needle): array
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(NEUROSYS_ROOT . '/src', FilesystemIterator::SKIP_DOTS),
        );

        $found = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if ($source !== false && str_contains($source, $needle)) {
                $found[] = $file->getFilename();
            }
        }

        sort($found);

        return $found;
    }
}
