<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\Exception\MarkupException;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Layout;
use NeuroSYS\AssetManifest;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Platform;
use NeuroSYS\Model\Embed\SoundCloudPlayerStyle;
use NeuroSYS\Model\Format;
use NeuroSYS\Model\Genre;
use NeuroSYS\Model\Link\HiDriveLink;
use NeuroSYS\Model\MusicalKey;
use NeuroSYS\Model\Release;
use NeuroSYS\Model\ReleaseFormat;
use NeuroSYS\Support\Collection;
use NeuroSYS\Support\SearchableCollection;
use NeuroSYS\View\HomeView;
use NeuroSYS\View\NotFoundView;
use NeuroSYS\View\ReleasesView;
use NeuroSYS\View\ReleaseView;
use NeuroSYS\View\Html\Tag;
use NeuroSYS\View\StatsView;
use NeuroSYS\View\Terminal\Terminal;
use NeuroSYS\View\Terminal\TerminalCommand;
use NeuroSYS\View\Terminal\TerminalField;
use NeuroSYS\View\Terminal\TerminalTone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseView::class)]
#[CoversClass(ReleasesView::class)]
#[CoversClass(NotFoundView::class)]
#[CoversClass(StatsView::class)]
#[CoversClass(Layout::class)]
#[CoversClass(Terminal::class)]
#[CoversClass(TerminalField::class)]
final class ViewTest extends TestCase
{
    /** A one-entry catalogue, for the cases that render the list rather than a single release. */
    private function catalogue(): SearchableCollection
    {
        return new SearchableCollection(Release::class)->with('ill', $this->release());
    }

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
            formats:     new Collection(Format::class)->with(...$formats),
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
        $html = new ReleaseView($this->release(title: $title), 'x')->content()->render();

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
        $html = new ReleaseView($this->release(title: 'überfall'), 'x')->content()->render();

        self::assertStringContainsString('überfall', $html);
    }

    public function testAMultibyteTitleEndingInAMarkStillSplitsCleanly(): void
    {
        $html = new ReleaseView($this->release(title: 'überfall!'), 'x')->content()->render();

        self::assertStringContainsString('überfall<span class="bang">!</span>', $html);
    }

    // ───────────────────────────── escaping ─────────────────────────────

    public function testTheReleaseTitleIsEscapedEverywhereItAppears(): void
    {
        $html = new ReleaseView($this->release(title: '<script>alert(1)</script>'), 'x')->content()->render();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTheSlugIsEscapedIntoDownloadHrefs(): void
    {
        $html = new ReleaseView(
            $this->release(formats: [new Format(ReleaseFormat::FLAC)]),
            '"><script>alert(1)</script>',
        )->content()->render();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    public function testTheNotFoundPathIsEscaped(): void
    {
        $html = new NotFoundView('/<img src=x onerror=alert(1)>')->content()->render();

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    public function testTheReleaseCardMetadataIsEscaped(): void
    {
        $releases = new SearchableCollection(Release::class)
            ->with('x', $this->release(title: 'a & b'));

        self::assertStringContainsString('a &amp; b', new ReleasesView($releases)->content()->render());
    }

    // ───────────────────────────── cover art ─────────────────────────────

    public function testFallsBackToThePlaceholderWhenThereIsNoCover(): void
    {
        $html = new ReleaseView($this->release(), 'x')->content()->render();

        self::assertStringContainsString('src="/assets/img/cover-placeholder.svg"', $html);
        self::assertStringNotContainsString('src=""', $html);
    }

    public function testUsesTheConfiguredCoverWhenThereIsOne(): void
    {
        $html = new ReleaseView($this->release(cover: new HiDriveLink('J2FXbB70A')), 'x')->content()->render();

        self::assertStringContainsString('id=J2FXbB70A', $html);
    }

    // ─────────────────────────── the consent gate ───────────────────────────

    /**
     * The whole point of the gate: nothing may reach the page as a live element. Since the widget
     * URL is built by <soundcloud-player>, the server's output carries no SoundCloud address at
     * all — nothing for a browser to preconnect or prefetch before the visitor has agreed to it.
     */
    public function testNothingReachesThePageThatCouldLoadFromSoundCloud(): void
    {
        $html = new ReleaseView(
            $this->release(embed: new SoundCloudEmbed(trackId: 1, permalink: 'x')),
            'x',
        )->content()->render();

        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('soundcloud.com', $html);
        self::assertStringContainsString('<soundcloud-player', $html);
    }

    /**
     * The provider is the element, not an attribute on a generic one: <soundcloud-player> knows it
     * is SoundCloud and words its own notice from that. Getting it wrong would mean a consent
     * notice naming the wrong company, so the wording is asserted where it is written —
     * test/js/soundcloud-player.test.mjs. What belongs here is that the view picks the right tag.
     */
    public function testTheViewEmitsTheProvidersOwnElement(): void
    {
        $embed = new SoundCloudEmbed(trackId: 1, permalink: 'x');
        $html  = new ReleaseView($this->release(embed: $embed), 'x')->content()->render();

        self::assertSame(Platform::SoundCloud, $embed->platform());
        self::assertStringContainsString('<soundcloud-player', $html);
    }

    public function testThereIsNoPlayerAtAllWithoutAnEmbed(): void
    {
        $html = new ReleaseView($this->release(), 'x')->content()->render();

        self::assertStringNotContainsString('soundcloud-player', $html);
    }

    /**
     * The gate reserves the player's own height so the page doesn't jump on load. Carried as an
     * attribute rather than an inline style, so the CSP needs no 'unsafe-inline' for our own
     * markup — ConsentGatedEmbed turns it into --player-height.
     */
    #[DataProvider('playerHeightProvider')]
    public function testTheGateReservesThePlayersHeight(SoundCloudPlayerStyle $style, int $height): void
    {
        $embed = new SoundCloudEmbed(trackId: 1, permalink: 'x', style: $style);
        $html  = new ReleaseView($this->release(embed: $embed), 'x')->content()->render();

        self::assertStringContainsString('height="' . $height . '"', $html);

        // Matched with a leading space on purpose: player-style="visual" contains style=" as a
        // substring, and a plain contains-check reads that as an inline style attribute.
        self::assertDoesNotMatchRegularExpression('/\sstyle="/', $html);
    }

    public static function playerHeightProvider(): iterable
    {
        yield [SoundCloudPlayerStyle::Visual, 300];
        yield [SoundCloudPlayerStyle::Classic, 166];
    }

    // ───────────────────────────── Terminal ─────────────────────────────

    /**
     * A generic's element type is the one thing PHP cannot enforce, so it is the one thing left to
     * check by hand — a Collection of the wrong class is still a Collection to the signature. The
     * items themselves are Collection::with()'s problem, and it throws a TypeError for them.
     */
    public function testATerminalRejectsACollectionOfSomethingElse(): void
    {
        $this->expectException(ReleaseVerificationException::class);

        new Terminal('release.log', new TerminalCommand('./x'), new Collection(Format::class));
    }

    /**
     * The command line quotes what it interpolates, which the two concatenations it replaced could
     * not: a quote written into a literal is just a character.
     */
    #[DataProvider('commandProvider')]
    public function testACommandLineQuotesItsValuesAndLeavesItsFlagsAlone(
        string $expected,
        string $program,
        string ...$arguments,
    ): void {
        self::assertSame($expected, new TerminalCommand($program, ...$arguments)->render());
    }

    /** @return iterable<string, array<int, string>> */
    public static function commandProvider(): iterable
    {
        yield 'a program on its own'   => ['./release', './release'];
        yield 'a flag stays bare'      => ['./release --track "ill."', './release', '--track', 'ill.'];
        yield 'a short flag too'       => ['ls -l "/tmp"', 'ls', '-l', '/tmp'];
        yield 'a value is always quoted, space or not'
                                       => ['find "/nope"', 'find', '/nope'];
        yield 'a space is contained'   => ['find "/some odd path"', 'find', '/some odd path'];
        yield 'an embedded quote is escaped' => [
            './release --track "rock \"n\" roll"',
            './release',
            '--track',
            'rock "n" roll',
        ];
        yield 'and so is a backslash'  => ['find "C:\\\\x"', 'find', 'C:\\x'];
        yield 'an empty value is still a value'
                                       => ['find ""', 'find', ''];
    }

    /**
     * The 404's command line is built from the request path, which is the one string on this site a
     * visitor writes in full. Quoting is what keeps it a legible line rather than a smeared one —
     * the escaping that keeps it *safe* is Text's, and is asserted separately.
     */
    public function testTheNotFoundCommandContainsThePathItWasGiven(): void
    {
        $html = new NotFoundView('/some odd path')->content()->render();

        self::assertStringContainsString('find &quot;/some odd path&quot;', $html);
    }

    /**
     * The rows cross as JSON, and `JSON_THROW_ON_ERROR` is what makes a row that will not encode
     * loud rather than a silent `false`. The JsonException that raises is translated rather than
     * propagated: a terminal whose rows cannot be serialised is a page that cannot be built, which
     * is what MarkupException already means and what every other failure in this layer throws.
     * Propagating the core exception would make every view declaring a terminal owe an @throws for
     * a condition none of them can act on.
     */
    public function testARowThatCannotBeEncodedFailsAsAMarkupProblem(): void
    {
        $this->expectException(MarkupException::class);
        $this->expectExceptionMessageIsOrContains('could not be encoded');

        new Terminal(
            label:   'release.log',
            command: new TerminalCommand('./x'),
            fields:  new Collection(TerminalField::class)
                ->with(new TerminalField('title', "\xB1\x31")),
        )->toElement();
    }

    public function testATerminalWithNoRowsRendersAnEmptyFieldList(): void
    {
        self::assertStringContainsString(
            'fields="[]"',
            new Terminal('error.log', new TerminalCommand('find', '/x'))->toElement()->render(),
        );
    }

    /** The rows cross as JSON in an attribute, so quotes in one must not end the attribute. */
    public function testATerminalRowCannotBreakOutOfTheFieldsAttribute(): void
    {
        $html = new Terminal(
            label:   'release.log',
            command: new TerminalCommand('./x'),
            fields:  new Collection(TerminalField::class)
                ->with(new TerminalField('title', '" onload="alert(1)', TerminalTone::Ok)),
        )->toElement()->render();

        // JSON escapes the quote, htmlspecialchars then escapes that — belt and braces, in that order.
        self::assertStringContainsString('\\&quot; onload=\\&quot;alert(1)', $html);
        // The escaped text still reads as ` onload=`; what matters is that no raw quote closes the
        // attribute around it, so the payload never becomes one.
        self::assertStringNotContainsString('" onload="', $html);
    }

    /**
     * A custom element the browser has never heard of renders as an inert inline box with no
     * error anywhere, so a typo in a tag name is invisible. This pins the set: adding an element
     * means adding it here, and misspelling one fails.
     *
     * Note what is *not* in this list. <terminal-command>, <terminal-field>, <terminal-key>,
     * <terminal-value> and <terminal-cursor> are all real, registered elements — the server just
     * never writes one. <terminal-window> builds its whole subtree, so a view declares a Terminal
     * and emits one tag. The verify script checks that every tag reaching the browser is registered.
     */
    public function testTheViewsEmitOnlyKnownCustomElements(): void
    {
        $html = new ReleaseView(
            $this->release(
                embed:   new SoundCloudEmbed(trackId: 1, permalink: 'x'),
                formats: [new Format(ReleaseFormat::FLAC, new HiDriveLink('BXRsy9S7d'))],
            ),
            'ill',
        )->content()->render()
            . new ReleasesView($this->catalogue())->content()->render()
            . new NotFoundView('/x')->content()->render()
            . new HomeView()->content()->render();

        preg_match_all('/<([a-z][a-z0-9]*-[a-z0-9-]+)/', $html, $m);

        $tags = array_values(array_unique($m[1]));
        sort($tags);

        $known = array_map(static fn(Tag $tag): string => $tag->value, Tag::cases());

        self::assertNotEmpty($tags);
        self::assertSame([], array_values(array_diff($tags, $known)));
        self::assertSame(
            [
                'cover-art', 'download-card', 'download-label', 'download-list', 'download-meta',
                'release-card', 'release-list', 'release-meta', 'release-title',
                'soundcloud-player', 'soundcloud-profile', 'terminal-window',
            ],
            $tags,
        );
    }

    /**
     * The other direction, and the one that catches a rename: a case added to {@link Tag} that no
     * view emits and no element builds is a tag nothing has. The five the terminal builds on the
     * client are the expected exceptions, and naming them here is the point — the list says which
     * tags exist only after the script runs, which is the same list CLAUDE.md's no-JS note is about.
     */
    public function testEveryTagIsEitherServedOrBuiltByAnElement(): void
    {
        $html = new ReleaseView(
            $this->release(
                embed:   new SoundCloudEmbed(trackId: 1, permalink: 'x'),
                formats: [new Format(ReleaseFormat::FLAC, new HiDriveLink('BXRsy9S7d'))],
            ),
            'ill',
        )->content()->render()
            . new ReleasesView($this->catalogue())->content()->render()
            . new NotFoundView('/x')->content()->render()
            . new HomeView()->content()->render();

        $unserved = array_values(array_filter(
            Tag::cases(),
            static fn(Tag $tag): bool => !str_contains($html, '<' . $tag->value),
        ));

        self::assertSame(
            [
                Tag::TerminalCommand, Tag::TerminalField, Tag::TerminalKey,
                Tag::TerminalValue, Tag::TerminalCursor,
            ],
            $unserved,
        );
    }

    // ───────────────────────────── downloads ─────────────────────────────

    /**
     * Without data-no-spa the 303 is swallowed by Navigation's fetch and downloads
     * silently stop working.
     */
    public function testEveryDownloadCardBypassesTheSpaRouter(): void
    {
        $html = new ReleaseView($this->release(
            formats: [new Format(ReleaseFormat::FLAC, new HiDriveLink('BXRsy9S7d'))],
        ), 'ill')->content()->render();

        preg_match_all('/<download-card[^>]*>\s*<a([^>]*)>/', $html, $m);

        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $attrs) {
            self::assertStringContainsString('data-no-spa', $attrs);
        }
    }

    public function testDownloadCardsPointAtTheRouteNotTheFileHost(): void
    {
        $html = new ReleaseView($this->release(
            formats: [new Format(ReleaseFormat::FLAC, new HiDriveLink('BXRsy9S7d'))],
        ), 'ill')->content()->render();

        self::assertStringContainsString('href="/releases/ill/flac"', $html);
        self::assertStringNotContainsString('hidrive', $html);
    }

    public function testAFormatWithNoLinkStillRendersItsCard(): void
    {
        $html = new ReleaseView($this->release(formats: [new Format(ReleaseFormat::WAV)]), 'ill')->content()->render();

        self::assertStringContainsString('href="/releases/ill/wav"', $html);
    }

    public function testLosslessFormatsShareOneDescription(): void
    {
        $html = new ReleaseView($this->release(formats: [
            new Format(ReleaseFormat::FLAC),
            new Format(ReleaseFormat::WAV),
            new Format(ReleaseFormat::MP3),
        ]), 'ill')->content()->render();

        self::assertSame(2, substr_count($html, 'lossless, 24-bit/48kHz'));
        self::assertStringContainsString('320 kbps', $html);
    }

    // ───────────────────────────── stats ─────────────────────────────

    public function testStatsSaysLoggingIsOffRatherThanShowingAnEmptyTable(): void
    {
        $html = new StatsView(0, [], [], false)->content()->render();

        self::assertStringContainsString('switched off', $html);
        self::assertStringNotContainsString('<table', $html);
    }

    public function testStatsDistinguishesOffFromOnButEmpty(): void
    {
        self::assertStringContainsString(
            'No downloads logged yet',
            new StatsView(0, [], [], true)->content()->render(),
        );
    }

    public function testStatsEscapesLogDerivedKeys(): void
    {
        $html = new StatsView(1, ['<script>x</script>' => 1], [], true)->content()->render();

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    // ───────────────────────────── layout ─────────────────────────────

    public function testTheLayoutWrapsContentInADocumentWithTheViewsTitle(): void
    {
        $html = Layout::wrap(new NotFoundView('/nope'))->render();

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<title>404 — neuro.SYS</title>', $html);
        self::assertStringContainsString('<main id="content">', $html);
    }

    public function testProfileLinksOpenSafelyInANewTab(): void
    {
        $html = Layout::wrap(new NotFoundView('/nope'))->render();

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
        $html = Layout::wrap(new NotFoundView('/x'))->render();

        preg_match_all('/\\bsrc="([^"]+)"/', $html, $src);
        preg_match_all('/<link[^>]+href="([^"]+)"/', $html, $link);

        $loaded = [...$src[1], ...$link[1]];

        self::assertNotEmpty($loaded);
        foreach ($loaded as $url) {
            self::assertStringStartsWith('/', $url, "Layout fetches $url from a remote host on load");
        }
    }

    // ──────────────────────── the asset manifest ────────────────────────

    /**
     * The point of the list is that it is complete.
     *
     * An ES module graph is discovered a wave at a time, and this one is five deep — so a module
     * left out is not a smaller hint, it is the whole waterfall back for everything downstream of
     * it. Nothing observable says so: the page works, just later.
     */
    public function testEveryModuleInTheGraphIsPreloaded(): void
    {
        $html = Layout::wrap(new NotFoundView('/x'))->render();

        self::assertNotEmpty(AssetManifest::MODULES, 'the generated manifest is empty');
        foreach (AssetManifest::MODULES as $module) {
            self::assertStringContainsString(
                sprintf('<link rel="modulepreload" href="%s">', $module),
                $html,
                "$module is in the graph but never preloaded",
            );
        }
    }

    /**
     * The entry point is the `<script src>` already being fetched, so hinting it as well is a
     * second instruction to fetch the file the browser is on its way to fetch.
     */
    public function testTheEntryPointIsNotAlsoPreloaded(): void
    {
        foreach (AssetManifest::MODULES as $module) {
            self::assertNotSame(Config::SCRIPT, self::withoutVersion($module));
        }

        self::assertStringNotContainsString(
            '<link rel="modulepreload" href="' . Config::SCRIPT,
            Layout::wrap(new NotFoundView('/x'))->render(),
        );
    }

    /**
     * A preload href is a graph path under a URL base written by hand in the build tool, so the
     * list can be exactly in step with the module graph and still name nothing. The verify script
     * asks a real server the same question; this asks the filesystem, so it fails in the fast suite
     * and without one running.
     */
    public function testEveryPreloadedModuleIsAFileThatExists(): void
    {
        $public = dirname(__DIR__, 2) . '/public';

        foreach (AssetManifest::MODULES as $module) {
            self::assertFileExists(
                $public . self::withoutVersion($module),
                "$module is preloaded but no such file is served",
            );
        }
    }

    /**
     * The version is the whole reason `public/.htaccess` may mark these `immutable` for a year.
     * An unversioned one slipping into the list would be cached for a year under a URL that can
     * later mean something else — the one failure a long max-age turns from a slow page into a
     * wrong one.
     */
    public function testEveryAssetUrlCarriesTheBuildStamp(): void
    {
        foreach (self::everyAssetUrl() as $url) {
            self::assertMatchesRegularExpression(
                '#^/assets/(?:js|css)/v-[0-9a-f]{8}/#',
                $url,
                "$url carries no build stamp, so it cannot be cached immutably",
            );
        }
    }

    /**
     * One stamp per build, not one per file. A second stamp in the manifest would mean two
     * versions of the same graph were being served at once — and since a relative specifier
     * inherits the segment it was loaded from, the modules under the older one would quietly go on
     * importing each other.
     */
    public function testEveryAssetUrlCarriesTheSameStamp(): void
    {
        $stamps = [];

        foreach (self::everyAssetUrl() as $url) {
            preg_match('#^/assets/(?:js|css)/(v-[0-9a-f]{8})/#', $url, $m);
            $stamps[$m[1]] = true;
        }

        self::assertCount(1, $stamps, 'the manifest mixes build stamps: ' . implode(', ', array_keys($stamps)));
    }

    /** @return list<string> The stylesheet, the entry script and every preloaded module. */
    private static function everyAssetUrl(): array
    {
        return [AssetManifest::STYLESHEET, AssetManifest::SCRIPT, ...AssetManifest::MODULES];
    }

    /**
     * The manifest is generated by a Node tool that writes `/assets/js` and `/assets/css` into its
     * output, and {@link Config} declares the same two paths in PHP — a mirror, and so pinned like
     * every other one here. Config owns *where the asset lives*; the manifest owns *which copy*.
     */
    public function testTheManifestAgreesWithConfigOnWhereTheAssetsLive(): void
    {
        self::assertSame(Config::STYLESHEET, self::withoutVersion(AssetManifest::STYLESHEET));
        self::assertSame(Config::SCRIPT, self::withoutVersion(AssetManifest::SCRIPT));
    }

    /** A versioned URL with the build-stamp segment taken back out — the path of the real file. */
    private static function withoutVersion(string $url): string
    {
        return preg_replace('#^/assets/(js|css)/v-[0-9a-f]{8}/#', '/assets/$1/', $url) ?? $url;
    }

    /** Outbound profile links are expected to be remote — that is what they are for. */
    public function testProfileLinksPointAtTheirPlatforms(): void
    {
        preg_match_all(
            '/<a class="profile-link" href="([^"]+)"/',
            Layout::wrap(new NotFoundView('/x'))->render(),
            $m,
        );

        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $url) {
            self::assertStringStartsWith('https://', $url);
        }
    }

    // ───────────────────────────── page titles ─────────────────────────────

    /**
     * The title is what the tab says and what Navigation writes into document.title after a swap.
     * Six views used to spell out `' — neuro.SYS'` between them, which is six chances to use a
     * hyphen where the others use an em dash and never notice.
     */
    public function testAReleasePageIsTitledForItsRelease(): void
    {
        self::assertSame(
            'ill. — ' . Config::NAME,
            new ReleaseView($this->release(), 'ill')->pageTitle(),
        );
    }

    public function testTheReleaseTitleIsNotEscapedTwiceIntoTheTitle(): void
    {
        self::assertSame(
            'a & b — ' . Config::NAME,
            new ReleaseView($this->release(title: 'a & b'), 'x')->pageTitle(),
        );
    }

    public function testTheCataloguePageIsTitledForTheSection(): void
    {
        self::assertSame('releases — ' . Config::NAME, new ReleasesView($this->catalogue())->pageTitle());
    }

    public function testTheStatsPageIsTitledForTheSection(): void
    {
        self::assertSame('stats — ' . Config::NAME, new StatsView(0, [], [], false)->pageTitle());
    }
}
