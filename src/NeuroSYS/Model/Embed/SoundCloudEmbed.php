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
 * The markup itself is not built here. This renders <soundcloud-player> with the
 * release's facts as attributes, and assets/ts/elements/SoundCloudPlayer.ts builds
 * the widget URL and the attribution from them — SoundCloud's furniture lives with
 * SoundCloud's element. See docs/branding.md for the same stance on brand assets.
 */
final readonly class SoundCloudEmbed implements Embed
{
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

    public function toElement(string $title): string
    {
        $options = implode(' ', array_map(
            static fn (SoundCloudOption $option): string => $option->value,
            $this->options,
        ));

        return '<soundcloud-player'
            . ' track-id="' . $this->trackId . '"'
            . ' permalink="' . htmlspecialchars($this->permalink) . '"'
            . $this->secretTokenAttribute()
            . ' player-style="' . $this->style->value . '"'
            . ' options="' . htmlspecialchars($options) . '"'
            . ' track-title="' . htmlspecialchars($title) . '"'
            . ' height="' . $this->height() . '"'
            . '></soundcloud-player>';
    }

    /** A public track carries no token, so the attribute is left off rather than sent empty. */
    private function secretTokenAttribute(): string
    {
        return $this->secretToken === ''
            ? ''
            : ' secret-token="' . htmlspecialchars($this->secretToken) . '"';
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
