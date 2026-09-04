<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Layout;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Embed\SoundCloudPlayerStyle;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\Link\HiDriveLink;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\View\NotFoundView;
use NeuroSYS\View\ReleasesView;
use NeuroSYS\View\ReleaseView;
use NeuroSYS\View\StatsView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseView::class)]
#[CoversClass(ReleasesView::class)]
#[CoversClass(NotFoundView::class)]
#[CoversClass(StatsView::class)]
#[CoversClass(Layout::class)]
final class ViewTest extends TestCase
{
    /** @param list<Format> $formats */
    private function release(
        string $title = 'ill.',
        ?SoundCloudEmbed $embed = null,
        ?HiDriveLink $cover = null,
        array $formats = [],
    ): Release {
        return new Release(
            title:       $title,
            bpm:         140,
            key:         MusicalKey::DSharpMinor,
            genre:       Genre::Dubstep,
            description: 'second single',
            cover:       $cover,
            formats:     new Collection(Format::class)->add(...$formats),
            embed:       $embed,
        );
    }

    // ───────────────────────── title accent mark ─────────────────────────

    public static function titleProvider(): iterable
    {
        yield 'bang'        => ['hello world!', 'hello world', '!'];
        yield 'full stop'   => ['ill.', 'ill', '.'];
        yield 'question'    => ['why?', 'why', '?'];
        yield 'no mark'     => ['untitled', 'untitled', null];
        yield 'inner mark'  => ['a.b', 'a.b', null];
    }

    #[DataProvider('titleProvider')]
    public function testSplitsATrailingPunctuationMarkIntoTheAccentSpan(
        string $title,
        string $stem,
        ?string $mark,
    ): void {
        $html = new ReleaseView($this->release(title: $title), 'x')->content();

        if ($mark === null) {
            self::assertStringNotContainsString('<span class="bang">', $html);
            self::assertStringContainsString("<h1>$stem</h1>", $html);
        } else {
            self::assertStringContainsString("<h1>$stem<span class=\"bang\">$mark</span></h1>", $html);
        }
    }

    /** substr(-1) is byte-based; a multibyte title must not be cut mid-character. */
    public function testAMultibyteTitleIsNotCorrupted(): void
    {
        $html = new ReleaseView($this->release(title: 'überfall'), 'x')->content();

        self::assertStringContainsString('überfall', $html);
    }

    public function testAMultibyteTitleEndingInAMarkStillSplitsCleanly(): void
    {
        $html = new ReleaseView($this->release(title: 'überfall!'), 'x')->content();

        self::assertStringContainsString('überfall<span class="bang">!</span>', $html);
    }

    // ───────────────────────────── escaping ─────────────────────────────

    public function testTheReleaseTitleIsEscapedEverywhereItAppears(): void
    {
        $html = new ReleaseView($this->release(title: '<script>alert(1)</script>'), 'x')->content();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTheSlugIsEscapedIntoDownloadHrefs(): void
    {
        $html = new ReleaseView(
            $this->release(formats: [new Format(ReleaseFormat::FLAC)]),
            '"><script>alert(1)</script>',
        )->content();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testTheNotFoundPathIsEscaped(): void
    {
        $html = new NotFoundView('/<img src=x onerror=alert(1)>')->content();

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    public function testTheReleaseCardMetadataIsEscaped(): void
    {
        $releases = new SearchableCollection(Release::class)
            ->add('x', $this->release(title: 'a & b'));

        self::assertStringContainsString('a &amp; b', new ReleasesView($releases)->content());
    }

    // ───────────────────────────── cover art ─────────────────────────────

    public function testFallsBackToThePlaceholderWhenThereIsNoCover(): void
    {
        $html = new ReleaseView($this->release(), 'x')->content();

        self::assertStringContainsString('src="/assets/img/cover-placeholder.svg"', $html);
        self::assertStringNotContainsString('src=""', $html);
    }

    public function testUsesTheConfiguredCoverWhenThereIsOne(): void
    {
        $html = new ReleaseView($this->release(cover: new HiDriveLink('J2FXbB70A')), 'x')->content();

        self::assertStringContainsString('id=J2FXbB70A', $html);
    }

    // ─────────────────────────── the consent gate ───────────────────────────

    /**
     * The whole point of the gate: nothing may reach the page as a live element. The markup
     * exists only escaped inside data-embed until the visitor clicks.
     */
    public function testNoIframeReachesThePageBeforeConsent(): void
    {
        $html = new ReleaseView(
            $this->release(embed: new SoundCloudEmbed(trackId: 1, permalink: 'x')),
            'x',
        )->content();

        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringContainsString('data-embed="', $html);
        self::assertStringContainsString('player-consent', $html);
    }

    public function testTheGateNamesTheProvider(): void
    {
        $html = new ReleaseView(
            $this->release(embed: new SoundCloudEmbed(trackId: 1, permalink: 'x')),
            'x',
        )->content();

        self::assertStringContainsString('SoundCloud player', $html);
        self::assertStringContainsString('connects you to SoundCloud', $html);
    }

    public function testTheEscapedMarkupDecodesBackToTheRealPlayer(): void
    {
        $embed = new SoundCloudEmbed(trackId: 1, permalink: 'x');
        $html  = new ReleaseView($this->release(embed: $embed), 'x')->content();

        preg_match('/data-embed="([^"]*)"/', $html, $m);

        self::assertSame($embed->toHtml('ill.'), html_entity_decode($m[1], ENT_QUOTES));
    }

    public function testThereIsNoPlayerAtAllWithoutAnEmbed(): void
    {
        $html = new ReleaseView($this->release(), 'x')->content();

        self::assertStringNotContainsString('player-consent', $html);
    }

    /**
     * The gate reserves the player's own height so the page doesn't jump on load. Carried as
     * a data attribute rather than an inline style, so the CSP needs no 'unsafe-inline' for
     * our own markup — player.ts turns it into --player-height.
     */
    #[DataProvider('playerHeightProvider')]
    public function testTheGateReservesThePlayersHeight(SoundCloudPlayerStyle $style, int $height): void
    {
        $embed = new SoundCloudEmbed(trackId: 1, permalink: 'x', style: $style);
        $html  = new ReleaseView($this->release(embed: $embed), 'x')->content();

        self::assertStringContainsString('data-player-height="' . $height . '"', $html);
        self::assertStringNotContainsString('style="', $html);
    }

    public static function playerHeightProvider(): iterable
    {
        yield [SoundCloudPlayerStyle::Visual, 300];
        yield [SoundCloudPlayerStyle::Classic, 166];
    }

    // ───────────────────────────── downloads ─────────────────────────────

    /**
     * Without data-no-spa the 303 is swallowed by nav.ts's fetch and downloads
     * silently stop working.
     */
    public function testEveryDownloadCardBypassesTheSpaRouter(): void
    {
        $html = new ReleaseView($this->release(
            formats: [new Format(ReleaseFormat::FLAC, new HiDriveLink('BXRsy9S7d'))],
        ), 'ill')->content();

        preg_match_all('/<a class="dl-card"([^>]*)>/', $html, $m);

        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $attrs) {
            self::assertStringContainsString('data-no-spa', $attrs);
        }
    }

    public function testDownloadCardsPointAtTheRouteNotTheFileHost(): void
    {
        $html = new ReleaseView($this->release(
            formats: [new Format(ReleaseFormat::FLAC, new HiDriveLink('BXRsy9S7d'))],
        ), 'ill')->content();

        self::assertStringContainsString('href="/releases/ill/flac"', $html);
        self::assertStringNotContainsString('hidrive', $html);
    }

    public function testAFormatWithNoLinkStillRendersItsCard(): void
    {
        $html = new ReleaseView($this->release(formats: [new Format(ReleaseFormat::WAV)]), 'ill')->content();

        self::assertStringContainsString('href="/releases/ill/wav"', $html);
    }

    public function testLosslessFormatsShareOneDescription(): void
    {
        $html = new ReleaseView($this->release(formats: [
            new Format(ReleaseFormat::FLAC),
            new Format(ReleaseFormat::WAV),
            new Format(ReleaseFormat::MP3),
        ]), 'ill')->content();

        self::assertSame(2, substr_count($html, 'lossless, 24-bit/48kHz'));
        self::assertStringContainsString('320 kbps', $html);
    }

    // ───────────────────────────── stats ─────────────────────────────

    public function testStatsSaysLoggingIsOffRatherThanShowingAnEmptyTable(): void
    {
        $html = new StatsView(0, [], [], false)->content();

        self::assertStringContainsString('switched off', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function testStatsDistinguishesOffFromOnButEmpty(): void
    {
        self::assertStringContainsString('No downloads logged yet', new StatsView(0, [], [], true)->content());
    }

    public function testStatsEscapesLogDerivedKeys(): void
    {
        $html = new StatsView(1, ['<script>x</script>' => 1], [], true)->content();

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // ───────────────────────────── layout ─────────────────────────────

    public function testTheLayoutWrapsContentInADocumentWithTheViewsTitle(): void
    {
        $html = Layout::wrap(new NotFoundView('/nope'));

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<title>404 — neuro.SYS</title>', $html);
        self::assertStringContainsString('<main id="content">', $html);
    }

    public function testProfileLinksOpenSafelyInANewTab(): void
    {
        $html = Layout::wrap(new NotFoundView('/nope'));

        preg_match_all('/<a class="profile-link"[^>]*>/', $html, $m);

        self::assertNotEmpty($m[0], 'no profile links rendered');
        foreach ($m[0] as $tag) {
            self::assertStringContainsString('rel="noopener noreferrer external"', $tag);
            self::assertStringContainsString('target="_blank"', $tag);
        }
    }

    /**
     * Vendored icons only. An <a href> to a platform is fine — nothing is fetched until the
     * visitor clicks. Anything the browser loads *on page load* (src, stylesheet href) must be
     * same-origin, or we become a joint controller for the transfer (CJEU C-40/17).
     */
    public function testNothingInTheLayoutIsFetchedFromARemoteHostOnPageLoad(): void
    {
        $html = Layout::wrap(new NotFoundView('/x'));

        preg_match_all('/\\bsrc="([^"]+)"/', $html, $src);
        preg_match_all('/<link[^>]+href="([^"]+)"/', $html, $link);

        $loaded = [...$src[1], ...$link[1]];

        self::assertNotEmpty($loaded);
        foreach ($loaded as $url) {
            self::assertStringStartsWith('/', $url, "Layout fetches $url from a remote host on load");
        }
    }

    /** Outbound profile links are expected to be remote — that is what they are for. */
    public function testProfileLinksPointAtTheirPlatforms(): void
    {
        preg_match_all(
            '/<a class="profile-link" href="([^"]+)"/',
            Layout::wrap(new NotFoundView('/x')),
            $m,
        );

        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $url) {
            self::assertStringStartsWith('https://', $url);
        }
    }
}
