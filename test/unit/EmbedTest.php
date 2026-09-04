<?php

declare(strict_types=1);

namespace NeuroSYS\Test\Unit;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Embed\SoundCloudEmbed;
use NeuroSYS\Model\Embed\SoundCloudOption;
use NeuroSYS\Model\Embed\SoundCloudPlayerStyle;
use NeuroSYS\Model\Platform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SoundCloudEmbed::class)]
#[CoversClass(SoundCloudOption::class)]
#[CoversClass(SoundCloudPlayerStyle::class)]
final class EmbedTest extends TestCase
{
    private function embed(mixed ...$args): SoundCloudEmbed
    {
        return new SoundCloudEmbed(...['trackId' => 2394077313, 'permalink' => 'ill', ...$args]);
    }

    public function testReportsItsPlatform(): void
    {
        self::assertSame(Platform::SoundCloud, $this->embed()->platform());
    }

    public function testRendersTheElementForTheGivenTrack(): void
    {
        $html = $this->embed()->toElement('ill.');

        self::assertStringContainsString('<soundcloud-player', $html);
        self::assertStringContainsString('track-id="2394077313"', $html);
        self::assertStringContainsString('permalink="ill"', $html);
    }

    public function testTheSecretTokenIsPassedToTheElement(): void
    {
        self::assertStringContainsString(
            'secret-token="s-dIMAqki109G"',
            $this->embed(secretToken: 's-dIMAqki109G')->toElement('ill.'),
        );
    }

    /** A public track carries no token, so the attribute is left off rather than sent empty. */
    public function testAPublicTrackSendsNoSecretTokenAttribute(): void
    {
        self::assertStringNotContainsString('secret-token', $this->embed()->toElement('ill.'));
    }

    /**
     * The whole reason the markup moved client-side is that none of it may exist before a click.
     * The server's output is the element and its attributes — no iframe, and no SoundCloud URL for
     * a browser to preconnect, prefetch or otherwise act on.
     */
    public function testTheServerEmitsNoSoundCloudUrlAtAll(): void
    {
        $html = $this->embed(secretToken: 's-dIMAqki109G')->toElement('ill.');

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
            $this->embed(options: [SoundCloudOption::ShowComments])->toElement('t'),
        );
    }

    public function testTheDefaultOptionSetIsTheOneSoundCloudsDialogProduces(): void
    {
        self::assertStringContainsString(
            'options="auto_play show_comments show_user show_teaser"',
            $this->embed()->toElement('t'),
        );
    }

    public function testAnEmptyOptionListSendsAnEmptyAttribute(): void
    {
        self::assertStringContainsString('options=""', $this->embed(options: [])->toElement('t'));
    }

    public function testRejectsSomethingThatIsNotASoundCloudOption(): void
    {
        $this->expectException(ReleaseVerificationException::class);
        $this->embed(options: ['show_user']);
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
        $html  = $embed->toElement('t');

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
                $embed->toElement('t'),
            );
        }
    }

    // ───────────────────────────── escaping ─────────────────────────────

    public function testTheTitleIsCarriedIntoTheElementEscaped(): void
    {
        $html = $this->embed()->toElement('rock & <roll>');

        self::assertStringContainsString('track-title="rock &amp; &lt;roll&gt;"', $html);
        self::assertStringNotContainsString('<roll>', $html);
    }

    public function testATitleWithQuotesCannotBreakOutOfTheAttribute(): void
    {
        $html = $this->embed()->toElement('a "quoted" title');

        self::assertStringNotContainsString('track-title="a "quoted" title', $html);
        self::assertStringContainsString('&quot;quoted&quot;', $html);
    }

    public function testThePermalinkIsEscapedToo(): void
    {
        $html = new SoundCloudEmbed(trackId: 1, permalink: 'a"b')->toElement('t');

        self::assertStringContainsString('permalink="a&quot;b"', $html);
    }
}
