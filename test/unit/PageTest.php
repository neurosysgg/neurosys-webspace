<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\DataFile;
use NeuroSYS\Http\RequestHeader;
use NeuroSYS\Service\ReleaseRepository;
use NeuroSYS\View\HomeView;
use NeuroSYS\View\Html\Language;
use NeuroSYS\View\ImprintView;
use NeuroSYS\View\NotFoundView;
use NeuroSYS\View\PrivacyView;
use NeuroSYS\View\ReleasesView;
use NeuroSYS\View\View;
use NeuroSYS\View\Wordmark;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pages that are only content: the home hero, the imprint and the privacy policy.
 *
 * None of them has a moving part — no repository, no request data, no custom element — which is
 * exactly what makes them worth pinning. They are the pages CLAUDE.md's no-JS note promises are
 * unaffected with the script off, and the imprint is a legal document that states the same address
 * four times.
 */
#[CoversClass(HomeView::class)]
#[CoversClass(ImprintView::class)]
#[CoversClass(PrivacyView::class)]
#[CoversClass(Wordmark::class)]
#[CoversClass(View::class)]
final class PageTest extends TestCase
{
    // ───────────────────────────── the wordmark ─────────────────────────────

    /**
     * The reason {@link Wordmark::nodes()} returns pieces rather than one node: {@link
     * \NeuroSYS\View\Html\Element} renders inline only when a child is text, so a wordmark wrapped
     * in a single node would be laid out as a block and gain a space either side of the dot. That
     * is a lookalike of the site's own name, rendered by the site itself.
     *
     * @return void
     */
    public function testTheWordmarkRendersOnOneLineWithNoSpaceAroundTheDot(): void
    {
        $html = new HomeView()->content()->render();

        self::assertStringContainsString('neuro<span class="logo-dot">.</span>SYS', $html);
    }

    /**
     * @return void
     */
    public function testTheWordmarkIsBuiltFromTheConfiguredNameRatherThanSpeltOut(): void
    {
        $rendered = array_map(
            static fn(mixed $node): string => is_string($node) ? $node : $node->render(),
            Wordmark::nodes(),
        );

        implode('', $rendered)
            |> strip_tags(...)
            |> (fn($x) => self::assertSame(Config::NAME, $x));
    }

    /**
     * explode(..., 2): a second dot belongs to the tail, it does not start a third piece.
     *
     * @return void
     */
    public function testOnlyTheFirstDotIsTheAccentedOne(): void
    {
        self::assertCount(3, Wordmark::nodes());
    }

    // ───────────────────────────── the home page ─────────────────────────────

    /**
     * The home page is the site, so its title is the site's name and nothing else.
     *
     * @return void
     */
    public function testTheHomePageTitleIsTheBareSiteName(): void
    {
        self::assertSame(Config::NAME, new HomeView()->pageTitle());
        self::assertStringNotContainsString('—', new HomeView()->pageTitle());
    }

    /**
     * @return void
     */
    public function testTheHomeHeadlineAccentsTheTaglinesFullStop(): void
    {
        self::assertStringContainsString(
            'electronic music<span class="bang">.</span>',
            new HomeView()->content()->render(),
        );
    }

    /**
     * The arrow is written as the character, not as `&rarr;`. Text is the only way content gets
     * in and it escapes all of it, so an entity written in the source comes back out as the
     * visible string `&amp;rarr;`.
     *
     * @return void
     */
    public function testTheCallToActionCarriesARealArrowRatherThanAnEntity(): void
    {
        $html = new HomeView()->content()->render();

        self::assertStringContainsString('releases →', $html);
        self::assertStringNotContainsString('&amp;rarr;', $html);
    }

    /**
     * @return void
     */
    public function testTheCallToActionPointsAtTheCatalogue(): void
    {
        self::assertStringContainsString('href="/releases"', new HomeView()->content()->render());
    }

    // ───────────────────────────── the imprint ─────────────────────────────

    /**
     * @return void
     */
    public function testTheImprintIsTitledInEnglish(): void
    {
        self::assertSame('Imprint — neuro.SYS', new ImprintView()->pageTitle());
    }

    /**
     * The address is built once and used from all four places that state it — twice per language,
     * under § 5 DDG and again under § 18 Abs. 2 MStV. A legal document with two copies of an
     * address is a legal document with one wrong address, eventually, so assert the occurrences
     * are byte-identical rather than merely present.
     *
     * @return void
     */
    public function testEveryCopyOfTheAddressIsTheSameAddress(): void
    {
        preg_match_all('#<p>Niclas Ahl.*?</p>#s', new ImprintView()->content()->render(), $m);

        self::assertCount(4, $m[0]);
        self::assertCount(1, array_unique($m[0]));
    }

    /**
     * One `<br>` between lines, so five lines carry four separators and no trailing one.
     *
     * @return void
     */
    public function testTheAddressLinesAreSeparatedRatherThanTerminated(): void
    {
        preg_match('#<p>Niclas Ahl.*?</p>#s', new ImprintView()->content()->render(), $m);

        self::assertSame(4, substr_count($m[0], '<br>'));
        self::assertStringEndsWith('Germany</p>', $m[0]);
    }

    /**
     * Both halves have to reach the same inbox, and it is the one the footer uses.
     *
     * @return void
     */
    public function testTheContactAddressIsTheConfiguredOneInBothHalves(): void
    {
        $html = new ImprintView()->content()->render();

        self::assertSame(2, substr_count($html, 'href="mailto:' . Config::EMAIL . '">' . Config::EMAIL . '</a>'));
    }

    /**
     * @return void
     */
    public function testBothLanguagesGetTheirOwnHeading(): void
    {
        $html = new ImprintView()->content()->render();

        self::assertStringContainsString('<h1>Impressum</h1>', $html);
        self::assertStringContainsString('<h1>Imprint</h1>', $html);
    }

    /**
     * The German text is full of characters htmlspecialchars leaves alone but a bad encode would not.
     *
     * @return void
     */
    public function testTheGermanTextSurvivesAsUtf8RatherThanAsEntities(): void
    {
        $html = new ImprintView()->content()->render();

        self::assertStringContainsString('gemäß § 5 DDG', $html);
        self::assertStringContainsString('48157 Münster', $html);
    }

    // ───────────────────────────── the privacy policy ─────────────────────────────

    /**
     * @return void
     */
    public function testThePrivacyPolicyIsTitled(): void
    {
        self::assertSame('Privacy Policy — neuro.SYS', new PrivacyView('', '')->pageTitle());
    }

    /**
     * The one view that *parses* a document instead of assembling one, and the reason
     * {@link \NeuroSYS\View\Html\MarkupParser} exists: the policy is hand-authored, so its markup
     * has to arrive as markup rather than as escaped text.
     *
     * **What is asserted here is narrower than it used to be, and stronger.** This was a pass-through
     * and the claim was that the bytes came out untouched; it is a parse now, so the claim is that
     * the *markup* comes out as markup — an `<h2>` with its `id`, and a `&amp;` still an entity
     * rather than a bare `&` or a doubled `&amp;amp;`. The second one is the round trip worth
     * pinning: the parser decodes that entity to a single `&` and {@link \NeuroSYS\View\Html\Text}
     * escapes it back, so agreement here is agreement between two separate pieces of code.
     *
     * @return void
     */
    public function testThePolicyDocumentIsEmittedAsMarkup(): void
    {
        $html = new PrivacyView('<h2 id="a">Datenschutz</h2>', '<p>text &amp; more</p>')->content()->render();

        self::assertStringContainsString('<h2 id="a">Datenschutz</h2>', $html);
        self::assertStringContainsString('<p>text &amp; more</p>', $html);
        self::assertStringNotContainsString('&lt;h2', $html);
    }

    /**
     * @return void
     */
    public function testTheRealPolicyRendersInsideThePageSection(): void
    {
        $html = new PrivacyView(
            (string) Config::dataFile(DataFile::PrivacyGerman)->read(),
            (string) Config::dataFile(DataFile::PrivacyEnglish)->read(),
        )->content()->render();

        self::assertStringStartsWith('<section class="page-section">', $html);
        self::assertStringContainsString('HiDrive', $html);
    }

    // ───────────────────────── what the pages promise ─────────────────────────

    /**
     * CLAUDE.md's no-JS note names these as unaffected with the script off. They are, because they
     * emit no custom element at all — everything they show is a standard tag the browser lays out
     * whether or not main.js ever loads.
     *
     * @param View $view
     * @return void
     */
    #[DataProvider('staticPageProvider')]
    public function testTheContentPagesNeedNoScriptToRender(View $view): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/<[a-z][a-z0-9]*-[a-z0-9-]+/',
            $view->content()->render(),
        );
    }

    /**
     * @return iterable
     */
    public static function staticPageProvider(): iterable
    {
        yield 'imprint' => [new ImprintView()];
        yield 'privacy' => [new PrivacyView('<p>Datenschutz</p>', '<p>policy</p>')];
    }

    /**
     * The home page used to be on that list and no longer is, which is worth stating rather than
     * quietly dropping: the profile player is a custom element, so with the script off the home
     * page shows an empty reserved box under its heading.
     *
     * What it still promises is the half that matters — the hero is every word the page says about
     * itself, and it is all standard tags. The player is the *only* thing on the page that needs
     * the script, and a no-JS visitor still reaches the profile through the footer's plain link.
     *
     * @return void
     */
    public function testTheHomeHeroNeedsNoScriptAndThePlayerIsTheOnlyThingThatDoes(): void
    {
        $html = new HomeView()->content()->render();
        [$hero] = explode('</section>', $html, 2);

        self::assertDoesNotMatchRegularExpression('/<[a-z][a-z0-9]*-[a-z0-9-]+/', $hero);
        self::assertStringContainsString('electronic music', $hero);
        self::assertStringContainsString('href="/releases"', $hero);

        preg_match_all('/<([a-z][a-z0-9]*-[a-z0-9-]+)/', $html, $custom);
        self::assertSame(['soundcloud-profile'], array_unique($custom[1]));
    }

    /**
     * Six views used to write out `' — neuro.SYS'` between them, which is six chances to use a
     * hyphen where the others use an em dash and never notice.
     *
     * @param View $view
     * @return void
     */
    #[DataProvider('titledViewProvider')]
    public function testEveryPageTitleEndsWithTheSiteName(View $view): void
    {
        self::assertStringEndsWith(Config::NAME, $view->pageTitle());
    }

    /**
     * @return iterable
     */
    public static function titledViewProvider(): iterable
    {
        yield 'home'     => [new HomeView()];
        yield 'imprint'  => [new ImprintView()];
        yield 'privacy'  => [new PrivacyView('', '')];
        yield 'releases' => [new ReleasesView(new ReleaseRepository()->all())];
        yield '404'      => [new NotFoundView('/x')];
    }

    // ─────────────────── which half of a bilingual page comes first ───────────────────

    /**
     * Both halves are always there. Only the order changes.
     *
     * That is the property worth pinning rather than the ordering itself: the German imprint is
     * what discharges § 5 DDG, so a language guess that dropped it would turn a preference into a
     * compliance failure. A wrong guess costs a visitor one scroll.
     *
     * @param Language $language
     * @return void
     */
    #[DataProvider('languageProvider')]
    public function testBothHalvesOfTheImprintAreAlwaysRendered(Language $language): void
    {
        $html = new ImprintView($language)->content()->render();

        self::assertStringContainsString('<h1>Impressum</h1>', $html);
        self::assertStringContainsString('<h1>Imprint</h1>', $html);
        self::assertSame(2, substr_count($html, '<h1>'));
    }

    /**
     * @param Language $language
     * @return void
     */
    #[DataProvider('languageProvider')]
    public function testBothHalvesOfThePolicyAreAlwaysRendered(Language $language): void
    {
        $html = new PrivacyView('<p>de</p>', '<p>en</p>', $language)->content()->render();

        self::assertStringContainsString('<p>de</p>', $html);
        self::assertStringContainsString('<p>en</p>', $html);
    }

    /**
     * @return iterable
     */
    public static function languageProvider(): iterable
    {
        yield 'English' => [Language::English];
        yield 'German'  => [Language::German];
    }

    /**
     * @return void
     */
    public function testTheImprintLeadsWithTheRequestedLanguage(): void
    {
        self::assertLessThan(
            strpos(new ImprintView(Language::German)->content()->render(), '<h1>Imprint</h1>'),
            strpos(new ImprintView(Language::German)->content()->render(), '<h1>Impressum</h1>'),
        );

        self::assertLessThan(
            strpos(new ImprintView(Language::English)->content()->render(), '<h1>Impressum</h1>'),
            strpos(new ImprintView(Language::English)->content()->render(), '<h1>Imprint</h1>'),
        );
    }

    /**
     * @return void
     */
    public function testThePolicyLeadsWithTheRequestedLanguage(): void
    {
        $german = new PrivacyView('<p>de</p>', '<p>en</p>', Language::German)->content()->render();

        self::assertLessThan(strpos($german, '<p>en</p>'), strpos($german, '<p>de</p>'));

        $english = new PrivacyView('<p>de</p>', '<p>en</p>', Language::English)->content()->render();

        self::assertLessThan(strpos($english, '<p>de</p>'), strpos($english, '<p>en</p>'));
    }

    /**
     * Each half says which language it is, so a screen reader changes voice at the boundary.
     *
     * @return void
     */
    public function testEachHalfDeclaresItsOwnLanguage(): void
    {
        $imprint = new ImprintView()->content()->render();

        self::assertStringContainsString('<section lang="de">', $imprint);
        self::assertStringContainsString('<section lang="en">', $imprint);

        $policy = new PrivacyView('', '')->content()->render();

        self::assertStringContainsString('<section lang="de">', $policy);
        self::assertStringContainsString('<section lang="en">', $policy);
    }

    /**
     * The title follows the leading half, in words the document already used.
     *
     * @return void
     */
    public function testTheTitleFollowsTheLeadingHalf(): void
    {
        self::assertSame('Impressum — neuro.SYS', new ImprintView(Language::German)->pageTitle());
        self::assertSame('Imprint — neuro.SYS', new ImprintView(Language::English)->pageTitle());
        self::assertSame(
            'Datenschutzerklärung — neuro.SYS',
            new PrivacyView('', '', Language::German)->pageTitle(),
        );
    }

    /**
     * A page whose body depends on a request header has to say so, or a cache is free to hand one
     * visitor the copy it built for another — which here means the wrong language and nothing else
     * visibly wrong at all. {@link \NeuroSYS\Http\ViewResponse} builds the header from this.
     *
     * @return void
     */
    public function testOnlyTheBilingualPagesVaryOnLanguage(): void
    {
        self::assertSame([RequestHeader::AcceptLanguage], new ImprintView()->varyOn());
        self::assertSame([RequestHeader::AcceptLanguage], new PrivacyView('', '')->varyOn());
        self::assertSame([], new HomeView()->varyOn());
    }

    /**
     * Every other page is English and says nothing about it.
     *
     * @return void
     */
    public function testAPageThatHasNotThoughtAboutLanguageIsEnglish(): void
    {
        self::assertSame(Language::English, new HomeView()->language());
        self::assertSame(Language::German, new ImprintView(Language::German)->language());
    }
}
