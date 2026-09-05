<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

use NeuroSYS\Exception\ReleaseVerificationException;
use NeuroSYS\Model\Platform;
use NeuroSYS\Support\Collection;
use NeuroSYS\View\Html\Element;
use NeuroSYS\View\Html\Tag;

/**
 * The SoundCloudProfileEmbed class. The whole account's latest tracks, rather than one track.
 *
 * SoundCloud's widget resolves a profile URL the same way it resolves a track's, so this is the
 * same player pointed at a different resource — the thing the WordPress plugin does.
 *
 * **Deliberately not an {@link Embed}.** That interface is what a {@link \NeuroSYS\Model\Release}
 * holds, and `Release::$embed` is typed for it; a profile player assignable to a release would be
 * nonsense. A profile embed is a different *resource*, not a different *provider* — the axis Embed
 * exists for is the other one. So this sits beside {@link SoundCloudEmbed} with the same three
 * methods and no interface, and Embed stays honest about what it is for. The client side does share
 * a base class, because there the overlap is real: see assets/ts/elements/embed/SoundCloudWidget.ts.
 *
 * Note what {@link self::toElement()} does *not* send: no id, no handle, no title. There is no
 * release to take them from, and the artist is {@link \NeuroSYS\Config::HANDLE}, which the element
 * already mirrors. So the served page still names SoundCloud nowhere — the same guarantee the track
 * player has, and the reason the consent gate is worth anything.
 */
final readonly class SoundCloudProfileEmbed
{
    /**
     * How tall the player stands, in px, when nothing says otherwise.
     *
     * SoundCloud's own profile embed uses this for the list layout. It is a number about *this*
     * player rather than about the layout, which is why it is not on {@link SoundCloudPlayerStyle}
     * — that enum's 300 and 166 are single-track heights and are correct for a single track.
     */
    public const int DEFAULT_HEIGHT = 450;

    /**
     * The toggles this player enables. Every case not listed is emitted as false.
     *
     * Not promoted, for the reason {@link SoundCloudEmbed::$options} is not: the default is a method
     * call, and a parameter default has to be a constant expression.
     *
     * @var Collection<SoundCloudOption>
     */
    public Collection $options;

    /**
     * Constructs an instance of {@link self}.
     *
     * @param SoundCloudPlayerStyle $style  Player layout. Classic by default rather than Visual,
     *                                      which is the opposite of a track's: a profile embed is
     *                                      worth having because several tracks read at once, and
     *                                      the visual layout shows one at a time.
     * @param int                   $height The reserved and rendered height in px — in effect, how
     *                                      many rows show. Not {@link SoundCloudPlayerStyle::height()},
     *                                      which sizes one track.
     * @param Collection<SoundCloudOption>|null $options The toggles to enable, or null for
     *                                      {@link self::defaultOptions()}.
     *
     * @throws ReleaseVerificationException if constructed with invalid data.
     */
    public function __construct(
        public SoundCloudPlayerStyle $style   = SoundCloudPlayerStyle::Classic,
        public int                   $height  = self::DEFAULT_HEIGHT,
        ?Collection                  $options = null,
    ) {
        $this->options = $options ?? self::defaultOptions();
        $this->verify();
    }

    /**
     * The player configuration the profile embed uses unless it says otherwise.
     *
     * The same set a track gets, autoplay included, and for the same reason: the iframe only exists
     * once the visitor has clicked through the consent gate, so loading it *is* the request to play.
     *
     * @return Collection<SoundCloudOption>
     */
    public static function defaultOptions(): Collection
    {
        return SoundCloudEmbed::defaultOptions();
    }

    /**
     * Returns the platform this embed loads from.
     *
     * @return Platform
     */
    public function platform(): Platform
    {
        return Platform::SoundCloud;
    }

    /**
     * Returns the rendered height of the player in px.
     *
     * The consent gate reserves exactly this much space, so swapping the placeholder for the real
     * player doesn't shift the page.
     *
     * @return int
     */
    public function height(): int
    {
        return $this->height;
    }

    /**
     * Renders the custom element that builds the profile player client-side.
     *
     * @return Element
     */
    public function toElement(): Element
    {
        $options = implode(' ', array_map(
            static fn (SoundCloudOption $option): string => $option->value,
            $this->options->all(),
        ));

        return new Element(Tag::SoundCloudProfile)
            ->attr(SoundCloudPlayerAttribute::PlayerStyle, $this->style)
            ->attr(SoundCloudPlayerAttribute::Options, $options)
            // EmbedAttribute, not SoundCloudPlayerAttribute: the height is what the gate reserves,
            // and the gate is every provider's.
            ->attr(EmbedAttribute::Height, $this->height());
    }

    /**
     *
     * @return void
     * @throws ReleaseVerificationException
     */
    private function verify(): void
    {
        if ($this->height <= 0) {
            throw new ReleaseVerificationException(
                'SoundCloudProfileEmbed::height must be greater than 0.'
            );
        }
        // Collection::with() rejects the wrong item; only its element type is left to check, which
        // is the one thing a PHP generic cannot say. Same guard as SoundCloudEmbed::verify().
        if ($this->options->type !== SoundCloudOption::class) {
            throw new ReleaseVerificationException(
                'SoundCloudProfileEmbed::options must be a Collection of \SoundCloudOption.'
            );
        }
    }
}
