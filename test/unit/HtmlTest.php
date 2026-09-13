<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use BackedEnum;
use NeuroSYS\DataFile;
use NeuroSYS\Model\Embed\SoundCloudPlayerAttribute;
use NeuroSYS\Model\Production\SectionKind;
use NeuroSYS\Site;
use NeuroSYS\View\Html\ArrangementAttribute;
use NeuroSYS\View\Html\CardAttribute;
use NeuroSYS\View\Html\CoverArtAttribute;
use NeuroSYS\View\Html\CssClass;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\Terminal\TerminalAttribute;
use NeuroSYS\View\Terminal\TerminalTone;
use Phpanta\View\Html\AttributeName;
use Phpanta\View\Html\Element;
use Phpanta\View\Html\HtmlAttribute;
use Phpanta\View\Html\HtmlTag;
use Phpanta\View\Html\MarkupParser;
use Phpanta\View\Html\TagName;
use Phpanta\View\Html\Text;
use Phpanta\View\Html\Vocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The site's own markup: its tags, attributes and class names, the stylesheet that reads them, its
 * vocabulary, and the one document it parses.
 *
 * How the tree itself renders, escapes, scheme-checks and parses is the framework's — its
 * `MarkupTest`, run under a test app of its own. What is left here holds only because this site's
 * enums, stylesheet and data files exist.
 */
#[CoversClass(Element::class)]
#[CoversClass(Text::class)]
#[CoversClass(MarkupParser::class)]
#[CoversClass(Vocabulary::class)]
#[CoversClass(HtmlTag::class)]
#[CoversClass(HtmlAttribute::class)]
#[CoversClass(CssClass::class)]
#[CoversClass(Tag::class)]
#[CoversClass(CardAttribute::class)]
#[CoversClass(CoverArtAttribute::class)]
#[CoversClass(TerminalAttribute::class)]
#[CoversClass(SoundCloudPlayerAttribute::class)]
final class HtmlTest extends TestCase
{
    // ───────────────────────── the names themselves ─────────────────────────

    /**
     * Every attribute name is a case rather than a string typed out at each call site. A name that
     * renders as something other than its backing value would be a silent null on the client, so
     * assert the two are the same thing — for the site's own enums; the framework's are its suite's.
     *
     * @param AttributeName&BackedEnum $name
     * @return void
     */
    #[DataProvider('attributeNameProvider')]
    public function testAnAttributeNameRendersAsItsBackingValue(AttributeName&BackedEnum $name): void
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

    /**
     * Every attribute enum the site declares.
     *
     * @return iterable<string, array{AttributeName&BackedEnum}>
     */
    public static function attributeNameProvider(): iterable
    {
        foreach (
            [
                CardAttribute::class,
                CoverArtAttribute::class,
                TerminalAttribute::class,
                SoundCloudPlayerAttribute::class,
            ] as $enum
        ) {
            // $enum is a class-string, so this is a static call on it and not an
            // instantiation — an enum cannot be constructed at all.
            foreach ($enum::cases() as $case) {
                yield $enum . '::' . $case->name => [$case];
            }
        }
    }

    /**
     * A custom element the browser has never heard of renders as an inert inline box with no error
     * anywhere, so the tag name is a contract with `assets/ts/elements/` that fails in silence.
     *
     * @return void
     */
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
     *
     * @return void
     */
    public function testNoCustomTagIsVoid(): void
    {
        foreach (Tag::cases() as $tag) {
            self::assertFalse($tag->isVoid(), "<{$tag->value}> would render without a closing tag");
        }
    }

    /**
     * A custom element is never void: no closing tag means the browser swallows what follows.
     *
     * @return void
     */
    public function testNoCustomElementIsVoid(): void
    {
        foreach (Tag::cases() as $tag) {
            self::assertFalse($tag->isVoid(), $tag->value . ' must not be void');
        }
    }

    // ───────────────────────────── class names ─────────────────────────────

    /**
     * The one mirror that can be checked against its actual reader.
     *
     * A misspelled class errors nowhere — the element just renders unstyled, which on a dark page
     * reads as a layout bug rather than a typo. Both directions matter and fail differently: a case
     * the stylesheet never mentions is an element styled by nothing, and a selector no case names is
     * a rule that can never match. The second is how dead CSS accumulates.
     *
     * @return void
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
        // Comments first: this file's own header names .out and .dot, which no rule has used since
        // the terminal moved client-side, and a scan that counted those would be measuring prose.
        $css = preg_replace('#/\*.*?\*/#s', '', self::stylesheet()) ?? '';

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
     *
     * @return void
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
     *
     * @return void
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
     * card.css is the single exception, and is meant to be conspicuous the way a parsed document's
     * one call site is: the
     * catalogue entry and the download entry genuinely share a look across two component
     * directories. Pinned here, so a second concept-named part has to be argued for in this test.
     *
     * @return void
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

    /**
     * The one file the browser loads, which is the concatenation of every part.
     *
     * @return string
     */
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
     * call-site audit below does.
     *
     * @param string $tag
     * @return string
     */
    private static function moduleFor(string $tag): string
    {
        $class = str_replace('-', ' ', $tag)
                |> ucwords(...)
                |> (fn($x) => str_replace(' ', '', $x));

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
     *
     * @param string $css
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

    // ──────────────────── the stylesheet's third copy of two vocabularies ────────────────────

    /**
     * Which enum each attribute-value selector in the stylesheet draws its values from.
     *
     * Declared here rather than discovered, so an attribute-value selector for something new fails
     * this test until somebody says where its vocabulary lives. That is the point: the two below
     * are *third* copies. `SectionKind` and `TerminalTone` are pinned PHP↔TS case for case by
     * `test/js/enum-parity.test.mjs`, and the stylesheet — which reads the same values off the same
     * attributes — was pinned by nothing at all.
     *
     * `tone` has no attribute-name case on the PHP side to key this by, deliberately: it is written
     * by `<terminal-window>` and read only by the stylesheet, which is exactly the arrangement that
     * makes this check worth having.
     *
     * @var array<string, class-string<BackedEnum>>
     */
    private const array VALUE_VOCABULARIES = [
        'kind' => SectionKind::class,
        'tone' => TerminalTone::class,
    ];

    /**
     * Cases with deliberately no rule of their own, keyed by attribute.
     *
     * `plain` is a terminal row with no accent — the default look, which is the absence of a rule
     * rather than a rule saying nothing. Pinned the way card.css is: a second one has to be argued
     * for here.
     *
     * @var array<string, list<string>>
     */
    private const array UNSTYLED_VALUES = ['tone' => ['plain']];

    /**
     * Every value the stylesheet selects on is a case some enum declares.
     *
     * The direction that always holds, and the one that rots silently. Rename `SectionKind::Drop`
     * and `[kind="drop"]` does not error, it stops matching — so the drop of every arrangement on
     * the site draws in the default accent, which on a dark page reads as a design decision rather
     * than as a rename that missed a file. Nothing in any console, and the PHP↔TS parity test is
     * perfectly happy, because both of the copies it compares were changed together.
     *
     * @return void
     */
    public function testEveryStyledAttributeValueIsADeclaredCase(): void
    {
        $selected = self::valueSelectors();

        self::assertNotEmpty($selected, 'found no attribute-value selectors at all — the scan is broken');
        self::assertSame(
            array_keys(self::VALUE_VOCABULARIES),
            array_keys($selected),
            'the stylesheet selects on an attribute value with no vocabulary declared for it',
        );

        foreach ($selected as $attribute => $values) {
            self::assertSame(
                [],
                array_values(array_diff($values, self::casesOf($attribute))),
                "[{$attribute}=\"…\"] selects on a value no case declares",
            );
        }
    }

    /**
     * And the other direction: a case with no rule is a look nobody gave it.
     *
     * Weaker than the check above, because one case genuinely has no rule — see
     * {@link self::UNSTYLED_VALUES}. What it catches is a case *added* on both typed sides and
     * forgotten on the third, which is a section that renders unaccented rather than one that
     * renders wrong.
     *
     * @return void
     */
    public function testEveryDeclaredValueIsStyledOrDeliberatelyNot(): void
    {
        $selected = self::valueSelectors();

        foreach (self::VALUE_VOCABULARIES as $attribute => $enum) {
            $expected = array_values(array_diff(
                self::casesOf($attribute),
                self::UNSTYLED_VALUES[$attribute] ?? [],
            ));
            $styled = $selected[$attribute] ?? [];

            sort($expected);
            sort($styled);

            self::assertSame($expected, $styled, "{$enum} has a case the stylesheet never accents");
        }
    }

    /**
     * The backing values of the enum behind $attribute.
     *
     * @param string $attribute
     * @return list<string>
     */
    private static function casesOf(string $attribute): array
    {
        return array_map(
            static fn(BackedEnum $case): string => (string) $case->value,
            self::VALUE_VOCABULARIES[$attribute]::cases(),
        );
    }

    /**
     * Every `[attr="value"]` the stylesheet selects on, as values keyed by attribute.
     *
     * Comments stripped first, for the reason {@link self::classSelectors()} strips them: prose
     * naming a selector is not a selector. {@link ArrangementAttribute::Kind} is asserted against
     * rather than assumed, so this scan cannot quietly stop finding the attribute it exists to
     * find.
     *
     * @return array<string, list<string>>
     */
    private static function valueSelectors(): array
    {
        $css = preg_replace('#/\*.*?\*/#s', '', self::stylesheet()) ?? '';

        preg_match_all('/\[([a-z-]+)="([^"]*)"\]/', $css, $matches, PREG_SET_ORDER);

        $found = [];

        foreach ($matches as [, $attribute, $value]) {
            $found[$attribute][] = $value;
        }

        foreach ($found as $attribute => $values) {
            $found[$attribute] = array_values(array_unique($values));
        }

        self::assertArrayHasKey(ArrangementAttribute::Kind->value, $found, 'the scan lost `kind`');

        ksort($found);

        return $found;
    }

    // ─────────────────────── urls, which escaping cannot help with ───────────────────────

    /**
     * The addresses this site actually emits pass the scheme check the framework makes — its
     * downloads, its mail address, and its file host, each a shape the framework's own rows only
     * stand in for.
     *
     * @param string $url
     * @return void
     */
    #[DataProvider('allowedUrlProvider')]
    public function testAUrlAttributeAllowsWhatTheSiteActuallyEmits(string $url): void
    {
        self::assertStringContainsString(
            'href="' . $url . '"',
            new Element(HtmlTag::A)->attr(HtmlAttribute::Href, $url)->render(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedUrlProvider(): iterable
    {
        yield 'a page'        => ['/imprint'];
        yield 'a download'    => ['/releases/ill/flac'];
        yield 'mailto'        => ['mailto:neuro.sys@neurosys.gg'];
        yield 'the file host' => ['https://my.hidrive.com/api/sharelink/download?id=BXRsy9S7d'];
    }

    /**
     * Which of the site's attributes are checked, pinned in both directions.
     *
     * An attribute the browser dereferences that nobody marked is a hole with nothing to report it:
     * the check simply would not run, and the page would look right. So the set is asserted here
     * rather than left to each enum's own good judgement, and adding a case that carries an address
     * means adding it to this list too. The framework pins `href` and `src` in its own suite.
     *
     * @return void
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
            ],
            $urls,
        );
    }

    /**
     * The site never escapes for itself.
     *
     * The framework's `Text` is the one `htmlspecialchars()` call there is, and its suite pins that.
     * A second one here would be a second set of flags that could drift from the first, so the
     * site's half of the audit is that it has none.
     *
     * @return void
     */
    public function testNothingInTheSiteEscapesForItself(): void
    {
        self::assertSame([], self::filesContaining('htmlspecialchars('));
    }

    // ───────────────────────────── markup read back in ─────────────────────────────

    /**
     * The policy is parsed, and this is the test that says so.
     *
     * {@link MarkupParser} reads the two halves of the policy, so every element and every
     * attribute in them has to be one this site emits — which makes this the regression test that
     * matters: it is what fails the day a re-export from e-recht24 brings a tag the enums do not
     * have, rather than that tag reaching a page unread.
     *
     * The real files rather than a fixture, deliberately. A fixture would pin the parser and prove
     * nothing about the documents actually served, which is the whole question here.
     *
     * @param DataFile $policy
     * @return void
     */
    #[DataProvider('policyProvider')]
    public function testTheRealPolicyParsesIntoTheTree(DataFile $policy): void
    {
        $parsed = MarkupParser::parse(self::policy($policy));

        self::assertGreaterThan(100, $parsed->count());
        self::assertNotSame('', new Element(HtmlTag::Section)->containingHtml(self::policy($policy))->render());
    }

    /**
     * A parsed document says the same thing it said before it was parsed.
     *
     * The bytes deliberately do *not* match: a character reference becomes the character it names,
     * so `&auml;` goes out as `ä` and the German half loses about 2.4 KB. What must not change is
     * what a reader sees, so this compares the text content with whitespace collapsed — which is
     * the strongest claim that survives entity decoding, and the one worth making.
     *
     * @param DataFile $policy
     * @return void
     */
    #[DataProvider('policyProvider')]
    public function testAParsedDocumentSaysWhatItSaidBefore(DataFile $policy): void
    {
        $source   = self::policy($policy);
        $rendered = new Element(HtmlTag::Section)->containingHtml($source)->render();

        self::assertSame(self::readable($source), self::readable($rendered));
    }

    /**
     * @return iterable<string, array{DataFile}>
     */
    public static function policyProvider(): iterable
    {
        yield DataFile::PrivacyGerman->value  => [DataFile::PrivacyGerman];
        yield DataFile::PrivacyEnglish->value => [DataFile::PrivacyEnglish];
    }

    /**
     * A custom element parses too, and it is the case that exercises the second enum in each list.
     *
     * `cover-art` is a {@link Tag} rather than an {@link HtmlTag}, and `fallback` is a
     * {@link CoverArtAttribute} rather than an {@link HtmlAttribute} — so this is the row that
     * proves the site's registries are walked rather than only their first entry being asked.
     *
     * @return void
     */
    public function testAParsedCustomElementResolvesThroughTheWholeRegistry(): void
    {
        $markup = '<cover-art src="/a.png" fallback="/b.png"></cover-art>';

        self::assertSame(
            "<section>\n  " . $markup . "\n</section>",
            new Element(HtmlTag::Section)->containingHtml($markup)->render(),
        );
    }

    /**
     * Every vocabulary enum the site declares spells its own name as its backing value.
     *
     * This is not tidiness, it is what makes {@link MarkupParser} correct. The parser resolves a
     * name with `tryFrom()` — a native O(1) lookup — where the honest question is "which case has
     * this `tagName()`", and the two are the same question only for as long as this holds. An enum
     * that computed its name would make the parser quietly unable to find it, so the shortcut is
     * pinned rather than assumed. The framework pins its own.
     *
     * @return void
     */
    public function testEveryNameEnumSpellsItsNameAsItsBackingValue(): void
    {
        $ours = static fn(string $class): bool => str_starts_with($class, 'NeuroSYS\\');

        foreach (array_filter(self::implementationsOf(TagName::class), $ours) as $enum) {
            foreach ($enum::cases() as $case) {
                self::assertSame($case->value, $case->tagName(), $enum . '::' . $case->name);
            }
        }

        foreach (array_filter(self::implementationsOf(AttributeName::class), $ours) as $enum) {
            foreach ($enum::cases() as $case) {
                self::assertSame($case->value, $case->attribute(), $enum . '::' . $case->name);
            }
        }
    }

    /**
     * The site's vocabulary names every enum there is — the framework's and its own — and no others.
     *
     * Pinned in both directions, the way {@link NoDiscardTest} pins its set, because the two
     * failures are different and both are quiet. An enum missing from a registry does not break the
     * parser — it makes every one of its names unparseable, which reads as the *markup* being
     * wrong. An enum listed that no longer exists is a fatal on the first parse.
     *
     * Compared as sets rather than as sequences, because the order of a registry means nothing:
     * a name resolves through whichever entry spells it, and no two of them spell the same name.
     *
     * @return void
     */
    public function testTheParserKnowsEveryVocabularyEnum(): void
    {
        $vocabulary = Site::current()->vocabulary();
        $tags       = $vocabulary->tags()->toValues();
        $attributes = $vocabulary->attributes()->toValues();

        sort($tags);
        sort($attributes);

        self::assertSame(self::implementationsOf(TagName::class), $tags);
        self::assertSame(self::implementationsOf(AttributeName::class), $attributes);
    }

    /**
     * The audit, retargeted. The hole became a door, and the door is still watched.
     *
     * Markup this codebase did not assemble goes through a parse rather than around one, so a
     * second call site is no longer a way to get unescaped markup onto a page. It is still the one
     * place a document written outside PHP enters the tree, and the standing instruction on it —
     * never from anything a request can influence — is only worth having if somebody has to argue
     * for the next caller. That argument belongs here. The framework pins its half: `Element` is
     * the parser's one caller, and nothing of its own calls `containingHtml()`.
     *
     * @return void
     */
    public function testMarkupIsParsedInExactlyOnePlace(): void
    {
        self::assertSame(['PrivacyView.php'], self::filesContaining('->containingHtml('));
        self::assertSame([], self::filesContaining('MarkupParser::parse('));
    }

    /**
     * The enums under both source trees implementing $interface, sorted — the same order a registry
     * is listed in.
     *
     * Derived from the filesystem rather than from a list, because a list is the thing being
     * checked.
     *
     * @param class-string $interface
     * @return list<class-string>
     */
    private static function implementationsOf(string $interface): array
    {
        $found = array_values(array_filter(
            SourceTree::classes(),
            static fn(string $class): bool => enum_exists($class) && is_a($class, $interface, true),
        ));

        sort($found);

        return $found;
    }

    /**
     * $file's contents, which the test suite requires to be there.
     *
     * @param DataFile $file
     * @return string
     */
    private static function policy(DataFile $file): string
    {
        return Site::current()->dataFile($file)->read() ?? '';
    }

    /**
     * $markup as a reader meets it: tags gone, entities resolved, runs of whitespace collapsed.
     *
     * @param string $markup
     * @return string
     */
    private static function readable(string $markup): string
    {
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(strip_tags($markup), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ));
    }

    /**
     * The file names under the site's `src/` whose source contains $needle, sorted.
     *
     * How the audits above are done. Reading the source rather than the class graph is the point:
     * what is being asserted is that a second call site does not *exist*, and a call site nobody
     * reaches is still one somebody will reach later. The framework's tree is its own suite's.
     *
     * @param string $needle
     * @return list<string>
     */
    private static function filesContaining(string $needle): array
    {
        $found = [];

        foreach (SourceTree::files() as $path) {
            if (!str_starts_with($path, NEUROSYS_ROOT . '/src/')) {
                continue;
            }

            $source = file_get_contents($path);

            if ($source !== false && str_contains($source, $needle)) {
                $found[] = basename($path);
            }
        }

        sort($found);

        return $found;
    }
}
