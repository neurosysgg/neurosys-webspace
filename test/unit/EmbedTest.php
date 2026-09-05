<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Config;
use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Embed\SoundCloudOption;
use NeuroSYS\Model\Embed\SoundCloudPlayerStyle;
use NeuroSYS\Model\Embed\SoundCloudProfileEmbed;
use NeuroSYS\Model\Platform;
use NeuroSYS\Support\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

#[CoversClass(SoundCloudEmbed::class)]
#[CoversClass(SoundCloudProfileEmbed::class)]
#[CoversClass(SoundCloudOption::class)]
#[CoversClass(SoundCloudPlayerStyle::class)]
final class EmbedTest extends TestCase
{
    private function embed(mixed ...$args): SoundCloudEmbed
    {
        return new SoundCloudEmbed(...['trackId' => 2394077313, 'permalink' => 'ill', ...$args]);
    }

    /** @return Collection<SoundCloudOption> */
    private function options(SoundCloudOption ...$options): Collection
    {
        return new Collection(SoundCloudOption::class)->with(...$options);
    }

    public function testReportsItsPlatform(): void
    {
        self::assertSame(Platform::SoundCloud, $this->embed()->platform());
    }

    public function testRendersTheElementForTheGivenTrack(): void
    {
        $html = $this->embed()->toElement('ill.')->render();

        self::assertStringContainsString('<soundcloud-player', $html);
        self::assertStringContainsString('track-id="2394077313"', $html);
        self::assertStringContainsString('permalink="ill"', $html);
    }

    public function testTheSecretTokenIsPassedToTheElement(): void
    {
        self::assertStringContainsString(
            'secret-token="s-dIMAqki109G"',
            $this->embed(secretToken: 's-dIMAqki109G')->toElement('ill.')->render(),
        );
    }

    /** A public track carries no token, so the attribute is left off rather than sent empty. */
    public function testAPublicTrackSendsNoSecretTokenAttribute(): void
    {
        self::assertStringNotContainsString('secret-token', $this->embed()->toElement('ill.')->render());
    }

    /**
     * The whole reason the markup moved client-side is that none of it may exist before a click.
     * The server's output is the element and its attributes — no iframe, and no SoundCloud URL for
     * a browser to preconnect, prefetch or otherwise act on.
     */
    public function testTheServerEmitsNoSoundCloudUrlAtAll(): void
    {
        $html = $this->embed(secretToken: 's-dIMAqki109G')->toElement('ill.')->render();

        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('soundcloud.com', $html);
        self::assertStringNotContainsString('https://', $html);
    }

    // ───────────────────────────── options ─────────────────────────────

    /**
     * The element resolves every case to true or false; what crosses the boundary is the list of
     * the ones that are on. EmbedTest used to assert the query string here — that assertion lives
     * in test/js/soundcloud-player.test.mjs now, where the query string is actually built.
     */
    public function testTheEnabledOptionsAreListedInTheAttribute(): void
    {
        self::assertStringContainsString(
            'options="show_comments"',
            $this->embed(options: $this->options(SoundCloudOption::ShowComments))->toElement('t')->render(),
        );
    }

    public function testTheDefaultOptionSetIsTheOneSoundCloudsDialogProduces(): void
    {
        self::assertStringContainsString(
            'options="auto_play show_comments show_user show_teaser"',
            $this->embed()->toElement('t')->render(),
        );
    }

    public function testAnEmptyOptionListSendsAnEmptyAttribute(): void
    {
        self::assertStringContainsString(
            'options=""',
            $this->embed(options: $this->options())->toElement('t')->render(),
        );
    }

    /** The collection refuses it before the embed ever sees it, which is the point of holding one. */
    public function testRejectsSomethingThatIsNotASoundCloudOption(): void
    {
        $this->expectException(TypeError::class);
        (void) $this->options()->with('show_user');
    }

    /**
     * A generic's element type is the one thing PHP cannot enforce, so it is the one thing left to
     * check by hand — a Collection of the wrong class is still a Collection to the signature.
     */
    public function testRejectsACollectionOfSomethingElse(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->embed(options: new Collection(SoundCloudPlayerStyle::class));
    }

    // ───────────────────────────── validation ─────────────────────────────

    public function testRejectsANonPositiveTrackId(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->embed(trackId: 0);
    }

    public function testRejectsAnEmptyPermalink(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->embed(permalink: '');
    }

    // ───────────────────────────── style / height ─────────────────────────────

    public static function styleProvider(): iterable
    {
        yield [SoundCloudPlayerStyle::Visual, 300];
        yield [SoundCloudPlayerStyle::Classic, 166];
    }

    #[DataProvider('styleProvider')]
    public function testStyleFixesTheHeightAndIsNamedInTheAttribute(
        SoundCloudPlayerStyle $style,
        int $height,
    ): void {
        $embed = $this->embed(style: $style);
        $html  = $embed->toElement('t')->render();

        self::assertSame($height, $embed->height());
        self::assertStringContainsString('player-style="' . $style->value . '"', $html);
        self::assertStringContainsString('height="' . $height . '"', $html);
    }

    /** The gate reserves height() worth of space, so the attribute has to carry exactly that. */
    public function testHeightMatchesWhatTheElementIsToldToReserve(): void
    {
        foreach (SoundCloudPlayerStyle::cases() as $style) {
            $embed = $this->embed(style: $style);
            self::assertStringContainsString(
                'height="' . $embed->height() . '"',
                $embed->toElement('t')->render(),
            );
        }
    }

    // ───────────────────────────── the profile embed ─────────────────────────────

    /**
     * SoundCloudProfileEmbed is the same player pointed at the whole account rather than one track.
     * It deliberately does not implement Embed — a profile player is a different *resource*, not a
     * different *provider*, and Release::$embed is typed for the other axis. These cases are here
     * rather than in a file of their own because they are the same provider's, and because what is
     * worth asserting is mostly how the two differ.
     */
    public function testTheProfileEmbedReportsItsPlatform(): void
    {
        self::assertSame(Platform::SoundCloud, new SoundCloudProfileEmbed()->platform());
    }

    /**
     * A profile lists, a track shows. The default layout is the opposite of a track's on purpose:
     * the embed is worth having because several tracks read at once.
     */
    public function testTheProfileEmbedListsByDefault(): void
    {
        $html = new SoundCloudProfileEmbed()->toElement()->render();

        self::assertStringContainsString('<soundcloud-profile', $html);
        self::assertStringContainsString('player-style="classic"', $html);
    }

    /**
     * The height is the profile embed's own fact, not SoundCloudPlayerStyle's — that enum's 300 and
     * 166 size a single track, and how tall a list stands is how many rows show.
     */
    public function testTheProfileEmbedReservesItsOwnHeightRatherThanTheStylesOne(): void
    {
        $embed = new SoundCloudProfileEmbed();

        self::assertSame(SoundCloudProfileEmbed::DEFAULT_HEIGHT, $embed->height());
        self::assertNotSame($embed->style->height(), $embed->height());
        self::assertStringContainsString('height="450"', $embed->toElement()->render());
    }

    public function testTheProfileEmbedTakesTheHeightItIsGiven(): void
    {
        $embed = new SoundCloudProfileEmbed(height: 620);

        self::assertSame(620, $embed->height());
        self::assertStringContainsString('height="620"', $embed->toElement()->render());
    }

    public function testTheProfileEmbedRejectsANonPositiveHeight(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        new SoundCloudProfileEmbed(height: 0);
    }

    public function testTheProfileEmbedRejectsACollectionOfSomethingElse(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        new SoundCloudProfileEmbed(options: new Collection(SoundCloudPlayerStyle::class));
    }

    public function testTheProfileEmbedCarriesTheSameDefaultToggles(): void
    {
        self::assertStringContainsString(
            'options="auto_play show_comments show_user show_teaser"',
            new SoundCloudProfileEmbed()->toElement()->render(),
        );

        self::assertEquals(
            SoundCloudEmbed::defaultOptions(),
            SoundCloudProfileEmbed::defaultOptions(),
        );
    }

    public function testTheProfileEmbedTakesTheTogglesItIsGiven(): void
    {
        self::assertStringContainsString(
            'options="show_user"',
            new SoundCloudProfileEmbed(
                options: $this->options(SoundCloudOption::ShowUser),
            )->toElement()->render(),
        );
    }

    /**
     * The same guarantee the track player has, and the reason the consent gate is worth anything.
     * Stronger here, in fact: the profile embed is sent no identity at all — no id, no handle, no
     * title — because the element already mirrors the handle. There is nothing to leak.
     */
    public function testTheProfileEmbedNamesNoSoundCloudAddressAndNoArtist(): void
    {
        $html = new SoundCloudProfileEmbed()->toElement()->render();

        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('soundcloud.com', $html);
        self::assertStringNotContainsString('https://', $html);
        self::assertStringNotContainsString(Config::HANDLE, $html);
    }

    // ───────────────────────────── escaping ─────────────────────────────

    public function testTheTitleIsCarriedIntoTheElementEscaped(): void
    {
        $html = $this->embed()->toElement('rock & <roll>')->render();

        self::assertStringContainsString('track-title="rock &amp; &lt;roll&gt;"', $html);
        self::assertStringNotContainsString('<roll>', $html);
    }

    public function testATitleWithQuotesCannotBreakOutOfTheAttribute(): void
    {
        $html = $this->embed()->toElement('a "quoted" title')->render();

        self::assertStringNotContainsString('track-title="a "quoted" title', $html);
        self::assertStringContainsString('&quot;quoted&quot;', $html);
    }

    public function testThePermalinkIsEscapedToo(): void
    {
        $html = new SoundCloudEmbed(trackId: 1, permalink: 'a"b')->toElement('t')->render();

        self::assertStringContainsString('permalink="a&quot;b"', $html);
    }

    /**
     * The `visual` query flag SoundCloud's widget URL carries. It is a property of the layout
     * rather than a second field, so the two cannot disagree about which player is being asked for.
     */
    #[DataProvider('visualProvider')]
    public function testOnlyTheVisualLayoutSetsTheVisualFlag(
        SoundCloudPlayerStyle $style,
        bool $visual,
    ): void {
        self::assertSame($visual, $style->isVisual());
    }

    public static function visualProvider(): iterable
    {
        yield 'visual'  => [SoundCloudPlayerStyle::Visual, true];
        yield 'classic' => [SoundCloudPlayerStyle::Classic, false];
    }
}
