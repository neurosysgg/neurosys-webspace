<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\View\HomeView;
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
     */
    public function testTheWordmarkRendersOnOneLineWithNoSpaceAroundTheDot(): void
    {
        $html = new HomeView()->content()->render();

        self::assertStringContainsString('neuro<span class="logo-dot">.</span>SYS', $html);
    }

    public function testTheWordmarkIsBuiltFromTheConfiguredNameRatherThanSpeltOut(): void
    {
        $rendered = array_map(
            static fn(mixed $node): string => is_string($node) ? $node : $node->render(),
            Wordmark::nodes(),
        );

        self::assertSame(Config::NAME, strip_tags(implode('', $rendered)));
    }

    /** explode(..., 2): a second dot belongs to the tail, it does not start a third piece. */
    public function testOnlyTheFirstDotIsTheAccentedOne(): void
    {
        self::assertCount(3, Wordmark::nodes());
    }

    // ───────────────────────────── the home page ─────────────────────────────

    /** The home page is the site, so its title is the site's name and nothing else. */
    public function testTheHomePageTitleIsTheBareSiteName(): void
    {
        self::assertSame(Config::NAME, new HomeView()->pageTitle());
        self::assertStringNotContainsString('—', new HomeView()->pageTitle());
    }

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
     */
    public function testTheCallToActionCarriesARealArrowRatherThanAnEntity(): void
    {
        $html = new HomeView()->content()->render();

        self::assertStringContainsString('releases →', $html);
        self::assertStringNotContainsString('&amp;rarr;', $html);
    }

    public function testTheCallToActionPointsAtTheCatalogue(): void
    {
        self::assertStringContainsString('href="/releases"', new HomeView()->content()->render());
    }

    // ───────────────────────────── the imprint ─────────────────────────────

    public function testTheImprintIsTitledInEnglish(): void
    {
        self::assertSame('Imprint — neuro.SYS', new ImprintView()->pageTitle());
    }

    /**
     * The address is built once and used from all four places that state it — twice per language,
     * under § 5 DDG and again under § 18 Abs. 2 MStV. A legal document with two copies of an
     * address is a legal document with one wrong address, eventually, so assert the occurrences
     * are byte-identical rather than merely present.
     */
    public function testEveryCopyOfTheAddressIsTheSameAddress(): void
    {
        preg_match_all('#<p>Niclas Ahl.*?</p>#s', new ImprintView()->content()->render(), $m);

        self::assertCount(4, $m[0]);
        self::assertCount(1, array_unique($m[0]));
    }

    /** One `<br>` between lines, so five lines carry four separators and no trailing one. */
    public function testTheAddressLinesAreSeparatedRatherThanTerminated(): void
    {
        preg_match('#<p>Niclas Ahl.*?</p>#s', new ImprintView()->content()->render(), $m);

        self::assertSame(4, substr_count($m[0], '<br>'));
        self::assertStringEndsWith('Germany</p>', $m[0]);
    }

    /** Both halves have to reach the same inbox, and it is the one the footer uses. */
    public function testTheContactAddressIsTheConfiguredOneInBothHalves(): void
    {
        $html = new ImprintView()->content()->render();

        self::assertSame(2, substr_count($html, 'href="mailto:' . Config::EMAIL . '">' . Config::EMAIL . '</a>'));
    }

    public function testBothLanguagesGetTheirOwnHeading(): void
    {
        $html = new ImprintView()->content()->render();

        self::assertStringContainsString('<h1>Impressum</h1>', $html);
        self::assertStringContainsString('<h1>Imprint</h1>', $html);
    }

    /** The German text is full of characters htmlspecialchars leaves alone but a bad encode would not. */
    public function testTheGermanTextSurvivesAsUtf8RatherThanAsEntities(): void
    {
        $html = new ImprintView()->content()->render();

        self::assertStringContainsString('gemäß § 5 DDG', $html);
        self::assertStringContainsString('48157 Münster', $html);
    }

    // ───────────────────────────── the privacy policy ─────────────────────────────

    public function testThePrivacyPolicyIsTitled(): void
    {
        self::assertSame('Privacy Policy — neuro.SYS', new PrivacyView('')->pageTitle());
    }

    /**
     * The one view that holds {@link \NeuroSYS\View\Html\RawHtml}, and the reason that class
     * exists: the policy is a hand-authored document, so its markup has to arrive as markup
     * rather than as escaped text. Nothing about a request can reach this — the document is read
     * from a file next to the code — which is what makes the verbatim pass-through safe.
     */
    public function testThePolicyDocumentIsEmittedVerbatim(): void
    {
        $html = new PrivacyView('<h2 id="a">Datenschutz</h2><p>text &amp; more</p>')->content()->render();

        self::assertStringContainsString('<h2 id="a">Datenschutz</h2>', $html);
        self::assertStringContainsString('<p>text &amp; more</p>', $html);
        self::assertStringNotContainsString('&lt;h2', $html);
    }

    public function testTheRealPolicyRendersInsideThePageSection(): void
    {
        $html = new PrivacyView(
            (string) file_get_contents(Config::dataPath('privacy.html')),
        )->content()->render();

        self::assertStringStartsWith('<section class="page-section">', $html);
        self::assertStringContainsString('HiDrive', $html);
    }

    // ───────────────────────── what the pages promise ─────────────────────────

    /**
     * CLAUDE.md's no-JS note names these as unaffected with the script off. They are, because they
     * emit no custom element at all — everything they show is a standard tag the browser lays out
     * whether or not main.js ever loads.
     */
    #[DataProvider('staticPageProvider')]
    public function testTheContentPagesNeedNoScriptToRender(View $view): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/<[a-z][a-z0-9]*-[a-z0-9-]+/',
            $view->content()->render(),
        );
    }

    public static function staticPageProvider(): iterable
    {
        yield 'imprint' => [new ImprintView()];
        yield 'privacy' => [new PrivacyView('<p>policy</p>')];
    }

    /**
     * The home page used to be on that list and no longer is, which is worth stating rather than
     * quietly dropping: the profile player is a custom element, so with the script off the home
     * page shows an empty reserved box under its heading.
     *
     * What it still promises is the half that matters — the hero is every word the page says about
     * itself, and it is all standard tags. The player is the *only* thing on the page that needs
     * the script, and a no-JS visitor still reaches the profile through the footer's plain link.
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
     */
    #[DataProvider('titledViewProvider')]
    public function testEveryPageTitleEndsWithTheSiteName(View $view): void
    {
        self::assertStringEndsWith(Config::NAME, $view->pageTitle());
    }

    public static function titledViewProvider(): iterable
    {
        yield 'home'     => [new HomeView()];
        yield 'imprint'  => [new ImprintView()];
        yield 'privacy'  => [new PrivacyView('')];
        yield 'releases' => [new ReleasesView(new \NeuroSYS\Service\ReleaseRepository()->all())];
        yield '404'      => [new NotFoundView('/x')];
    }
}
