<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Platform;
use NeuroSYS\Support\Collection;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Tag;

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
     * The toggles this player enables. Every case not listed is emitted as false.
     *
     * Not promoted, because the default is a method call and a parameter default has to be a
     * constant expression — {@link self::defaultOptions()} builds it instead.
     *
     * @var Collection<SoundCloudOption>
     */
    public Collection $options;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param int                   $trackId     Numeric SoundCloud track id (must be > 0).
     * @param string                $permalink   The track's URL slug on SoundCloud, e.g. 'hello-world'.
     * @param string                $secretToken Share token for a private or scheduled track
     *                                           ('s-…'), or empty for a plain public track.
     * @param SoundCloudPlayerStyle $style       Player layout; also fixes the iframe height.
     * @param Collection<SoundCloudOption>|null $options The toggles to enable, or null for
     *                                           {@link self::defaultOptions()}.
     *
     * @throws ReleaseVerificationException if constructed with invalid data.
     */
    public function __construct(
        public int                   $trackId,
        public string                $permalink,
        public string                $secretToken = '',
        public SoundCloudPlayerStyle $style       = SoundCloudPlayerStyle::Visual,
        ?Collection                  $options     = null,
    ) {
        $this->options = $options ?? self::defaultOptions();
        $this->verify();
    }

    /**
     * The player configuration every release uses unless it says otherwise.
     *
     * Autoplay is deliberate: the iframe only exists once the visitor has clicked
     * through the consent gate, so loading it *is* the request to play.
     *
     * @return Collection<SoundCloudOption>
     */
    public static function defaultOptions(): Collection
    {
        return new Collection(SoundCloudOption::class)->with(
            SoundCloudOption::AutoPlay,
            SoundCloudOption::ShowComments,
            SoundCloudOption::ShowUser,
            SoundCloudOption::ShowTeaser,
        );
    }

    public function platform(): Platform
    {
        return Platform::SoundCloud;
    }

    public function height(): int
    {
        return $this->style->height();
    }

    public function toElement(string $title): Element
    {
        $options = implode(' ', array_map(
            static fn (SoundCloudOption $option): string => $option->value,
            $this->options->all(),
        ));

        return new Element(Tag::SoundCloudPlayer)
            ->attr(SoundCloudPlayerAttribute::TrackId, $this->trackId)
            ->attr(SoundCloudPlayerAttribute::Permalink, $this->permalink)
            // ?: so a public track sends no attribute at all rather than an empty one — null is
            // absent, '' is a real empty value, and the client reads those differently.
            ->attr(SoundCloudPlayerAttribute::SecretToken, $this->secretToken ?: null)
            ->attr(SoundCloudPlayerAttribute::PlayerStyle, $this->style)
            ->attr(SoundCloudPlayerAttribute::Options, $options)
            ->attr(SoundCloudPlayerAttribute::TrackTitle, $title)
            // EmbedAttribute, not SoundCloudPlayerAttribute: the height is what the gate reserves,
            // and the gate is every provider's. See Embed::height().
            ->attr(EmbedAttribute::Height, $this->height());
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
        // Collection::with() rejects the wrong item; only its element type is left to check, which
        // is the one thing a PHP generic cannot say. Same guard as Release::verify().
        if ($this->options->type !== SoundCloudOption::class) {
            throw new ReleaseVerificationException(
                'SoundCloudEmbed::options must be a Collection of \SoundCloudOption.'
            );
        }
    }
}
