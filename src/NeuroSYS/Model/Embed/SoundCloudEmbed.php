<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Platform;

/**
 * The SoundCloudEmbed class. A SoundCloud player declared as typed parameters.
 *
 * Replaces the raw HTML that used to be pasted out of SoundCloud's Share → Embed
 * dialog into `data/releases.php`. A release now names the track and this class
 * builds the markup, so the two can no longer drift apart.
 *
 * The generated markup is deliberately identical to what SoundCloud's own dialog
 * produces — same query parameters, same attribution block, same inline styles.
 * Both are their furniture, not ours; see docs/branding.md for the same stance on
 * brand assets.
 */
final readonly class SoundCloudEmbed implements Embed
{
    /**
     * The player accent, as SoundCloud's `color` parameter wants it.
     *
     * Intentionally *not* the site's --accent (#6a00ff), which reads as near-black
     * against the player's own dark chrome. This is a lighter purple picked to sit
     * in the same family while staying legible on SoundCloud's background.
     */
    private const string ACCENT = '#9e55e6';

    /** The artist profile the attribution block credits and links to. */
    private const string ARTIST_HANDLE = 'neurosysgg';
    private const string ARTIST_NAME   = 'neuro.SYS';

    /** SoundCloud's own attribution styling, reproduced verbatim. */
    private const string ATTRIBUTION_STYLE = 'font-size: 10px; color: #cccccc;line-break: anywhere;'
        . 'word-break: normal;overflow: hidden;white-space: nowrap;text-overflow: ellipsis; '
        . 'font-family: Interstate,Lucida Grande,Lucida Sans Unicode,Lucida Sans,Garuda,Verdana,'
        . 'Tahoma,sans-serif;font-weight: 100;';
    private const string ATTRIBUTION_LINK_STYLE = 'color: #cccccc; text-decoration: none;';

    /**
     * The player configuration every release uses unless it says otherwise.
     *
     * Autoplay is deliberate: the iframe only exists once the visitor has clicked
     * through the consent gate, so loading it *is* the request to play.
     *
     * @var list<SoundCloudOption>
     */
    public const array DEFAULT_OPTIONS = [
        SoundCloudOption::AutoPlay,
        SoundCloudOption::ShowComments,
        SoundCloudOption::ShowUser,
        SoundCloudOption::ShowTeaser,
    ];

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int                   $trackId     Numeric SoundCloud track id (must be > 0).
     * @param string                $permalink   The track's URL slug on SoundCloud, e.g. 'hello-world'.
     * @param string                $secretToken Share token for a private or scheduled track
     *                                           ('s-…'), or empty for a plain public track.
     * @param SoundCloudPlayerStyle $style       Player layout; also fixes the iframe height.
     * @param list<SoundCloudOption> $options    The toggles to enable. Every case not listed
     *                                           is emitted as false.
     *
     * @throws ReleaseVerificationException if constructed with invalid data.
     */
    public function __construct(
        public int                   $trackId,
        public string                $permalink,
        public string                $secretToken = '',
        public SoundCloudPlayerStyle $style       = SoundCloudPlayerStyle::Visual,
        public array                 $options     = self::DEFAULT_OPTIONS,
    ) {
        $this->verify();
    }

    public function platform(): Platform
    {
        return Platform::SoundCloud;
    }

    public function height(): int
    {
        return $this->style->height();
    }

    public function toHtml(string $title): string
    {
        return $this->iframeHtml($title) . $this->attributionHtml($title);
    }

    /** Returns true if the given option is enabled on this embed. */
    private function has(SoundCloudOption $option): bool
    {
        return in_array($option, $this->options, true);
    }

    /**
     * Builds the player iframe.
     *
     * `scrolling` and `frameborder` are deprecated HTML attributes, but they are what
     * SoundCloud ships and what is verified working, so they stay.
     *
     * @noinspection HtmlDeprecatedAttribute
     */
    private function iframeHtml(string $title): string
    {
        $src    = htmlspecialchars($this->playerUrl());
        $height = $this->height();
        $name   = htmlspecialchars($title . ' on ' . $this->platform()->displayName());

        return '<iframe width="100%" height="' . $height . '" scrolling="no" frameborder="no"'
            . ' allow="autoplay; encrypted-media" title="' . $name . '" src="' . $src . '"></iframe>';
    }

    /**
     * Builds the artist · track credit line SoundCloud's embed carries.
     *
     * SoundCloud asks that embeds keep this attribution, so it renders whether or not
     * {@link SoundCloudOption::ShowUser} is on — that toggle governs the player chrome,
     * not the credit.
     */
    private function attributionHtml(string $title): string
    {
        $artist = $this->link(
            'https://soundcloud.com/' . self::ARTIST_HANDLE,
            self::ARTIST_NAME,
        );
        $track = $this->link($this->trackPermalink(), $title);

        return '<div style="' . self::ATTRIBUTION_STYLE . '">' . $artist . ' · ' . $track . '</div>';
    }

    /** Builds one attribution link, styled the way SoundCloud styles it. */
    private function link(string $href, string $text): string
    {
        $href = htmlspecialchars($href);
        $text = htmlspecialchars($text);

        return '<a href="' . $href . '" title="' . $text . '" target="_blank"'
            . ' style="' . self::ATTRIBUTION_LINK_STYLE . '">' . $text . '</a>';
    }

    /** Builds the widget URL the iframe loads, with every option resolved to true/false. */
    private function playerUrl(): string
    {
        $params = ['url' => $this->trackUrl(), 'color' => self::ACCENT];

        foreach (SoundCloudOption::cases() as $option) {
            $params[$option->value] = $this->has($option) ? 'true' : 'false';
        }

        $params['visual'] = $this->style->isVisual() ? 'true' : 'false';

        return 'https://w.soundcloud.com/player/?' . http_build_query($params);
    }

    /**
     * Returns the API track reference the player resolves.
     *
     * SoundCloud's dialog emits the `soundcloud:tracks:<id>` URN form rather than a
     * bare id — unusual, but it is what the live embeds use, so it is reproduced as-is.
     */
    private function trackUrl(): string
    {
        $url = 'https://api.soundcloud.com/tracks/soundcloud:tracks:' . $this->trackId;

        return $this->secretToken === ''
            ? $url
            : $url . '?secret_token=' . $this->secretToken;
    }

    /** Returns the public track page the attribution links to. */
    private function trackPermalink(): string
    {
        $url = 'https://soundcloud.com/' . self::ARTIST_HANDLE . '/' . $this->permalink;

        return $this->secretToken === ''
            ? $url
            : $url . '/' . $this->secretToken;
    }

    /**
     * @throws ReleaseVerificationException
     */
    private function verify(): void
    {
        if ($this->trackId <= 0) {
            throw new ReleaseVerificationException(
                'SoundCloudEmbed::trackId must be greater than 0.'
            );
        }
        if ($this->permalink === '') {
            throw new ReleaseVerificationException(
                'SoundCloudEmbed::permalink must not be empty.'
            );
        }
        foreach ($this->options as $option) {
            if (!$option instanceof SoundCloudOption) {
                throw new ReleaseVerificationException(sprintf(
                    'SoundCloudEmbed::options must contain only %s, got %s.',
                    SoundCloudOption::class,
                    get_debug_type($option),
                ));
            }
        }
    }
}
