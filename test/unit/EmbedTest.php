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

    public function testRendersAnIframeForTheGivenTrack(): void
    {
        $html = $this->embed()->toHtml('ill.');

        self::assertStringContainsString('<iframe', $html);
        self::assertStringContainsString('soundcloud%3Atracks%3A2394077313', $html);
    }

    public function testTheSecretTokenIsCarriedIntoThePlayerUrl(): void
    {
        $html = $this->embed(secretToken: 's-dIMAqki109G')->toHtml('ill.');

        self::assertStringContainsString('secret_token', $html);
        self::assertStringContainsString('s-dIMAqki109G', $html);
    }

    public function testAPublicTrackHasNoSecretToken(): void
    {
        self::assertStringNotContainsString('secret_token', $this->embed()->toHtml('ill.'));
    }

    // ───────────────────────────── options ─────────────────────────────

    /** Listed means true, unlisted means an explicit false — never omitted. */
    public function testEveryOptionIsEmittedExplicitly(): void
    {
        $html = $this->embed(options: [SoundCloudOption::ShowComments])->toHtml('t');

        foreach (SoundCloudOption::cases() as $option) {
            $expected = $option === SoundCloudOption::ShowComments ? 'true' : 'false';
            self::assertStringContainsString("{$option->value}={$expected}", $html);
        }
    }

    public function testTheDefaultOptionSetIsTheOneSoundCloudsDialogProduces(): void
    {
        $html = $this->embed()->toHtml('t');

        $on = [
            SoundCloudOption::AutoPlay,
            SoundCloudOption::ShowComments,
            SoundCloudOption::ShowUser,
            SoundCloudOption::ShowTeaser,
        ];

        foreach ($on as $option) {
            self::assertStringContainsString("{$option->value}=true", $html);
        }
        foreach ([SoundCloudOption::HideRelated, SoundCloudOption::ShowReposts] as $off) {
            self::assertStringContainsString("{$off->value}=false", $html);
        }
    }

    public function testAnEmptyOptionListTurnsEverythingOff(): void
    {
        $html = $this->embed(options: [])->toHtml('t');

        foreach (SoundCloudOption::cases() as $option) {
            self::assertStringContainsString("{$option->value}=false", $html);
        }
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
        yield [SoundCloudPlayerStyle::Visual, 300, 'true'];
        yield [SoundCloudPlayerStyle::Classic, 166, 'false'];
    }

    #[DataProvider('styleProvider')]
    public function testStyleFixesTheHeightAndTheVisualFlag(
        SoundCloudPlayerStyle $style,
        int $height,
        string $visual,
    ): void {
        $embed = $this->embed(style: $style);

        self::assertSame($height, $embed->height());
        self::assertStringContainsString('height="' . $height . '"', $embed->toHtml('t'));
        self::assertStringContainsString('visual=' . $visual, $embed->toHtml('t'));
    }

    /** The consent gate reserves height() worth of space, so it has to match the iframe. */
    public function testHeightMatchesTheRenderedIframeHeight(): void
    {
        foreach (SoundCloudPlayerStyle::cases() as $style) {
            $embed = $this->embed(style: $style);
            self::assertStringContainsString('height="' . $embed->height() . '"', $embed->toHtml('t'));
        }
    }

    // ───────────────────────────── attribution ─────────────────────────────

    public function testCreditsTheTitleItIsGiven(): void
    {
        self::assertStringContainsString('>my track!</a>', $this->embed()->toHtml('my track!'));
    }

    public function testTheAttributionEscapesTheTitle(): void
    {
        $html = $this->embed()->toHtml('rock & <roll>');

        self::assertStringContainsString('rock &amp; &lt;roll&gt;', $html);
        self::assertStringNotContainsString('<roll>', $html);
    }

    public function testTheIframeTitleEscapesTheReleaseTitle(): void
    {
        $html = $this->embed()->toHtml('a "quoted" title');

        self::assertStringNotContainsString('title="a "quoted" title', $html);
        self::assertStringContainsString('&quot;quoted&quot;', $html);
    }

    public function testAttributionLinksToTheArtistAndTheTrack(): void
    {
        $html = $this->embed()->toHtml('ill.');

        self::assertStringContainsString('https://soundcloud.com/neurosysgg"', $html);
        self::assertStringContainsString('https://soundcloud.com/neurosysgg/ill', $html);
    }

    public function testAPrivateTrackPermalinkCarriesTheToken(): void
    {
        $html = $this->embed(secretToken: 's-dIMAqki109G')->toHtml('ill.');

        self::assertStringContainsString('https://soundcloud.com/neurosysgg/ill/s-dIMAqki109G', $html);
    }

    /** Nothing may point at a SoundCloud asset that would load without a click. */
    public function testTheMarkupOnlyEverReferencesSoundCloudHosts(): void
    {
        preg_match_all('/(?:src|href)="([^"]+)"/', $this->embed()->toHtml('t'), $m);

        self::assertNotEmpty($m[1]);
        foreach ($m[1] as $url) {
            self::assertMatchesRegularExpression(
                '#^https://(w\.soundcloud\.com|soundcloud\.com)/#',
                html_entity_decode($url),
            );
        }
    }
}
