<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

use NeuroSYS\Model\Platform;

/**
 * The Embed interface. A third-party media player attached to a release.
 *
 * Implementations own their provider's parameters so that {@link \NeuroSYS\Model\Release}
 * can declare a player as typed values rather than pasted HTML.
 *
 * The provider's own markup is not built here. An implementation renders the custom
 * element that builds it client-side, which is also what gates it: every embed loads
 * from someone else's servers, and nothing is requested from them until the visitor
 * clicks through the consent gate the element puts up first.
 */
interface Embed
{
    /** Returns the platform this embed loads from. */
    public function platform(): Platform;

    /**
     * Returns the rendered height of the player in px.
     *
     * The consent gate reserves exactly this much space, so swapping the placeholder
     * for the real player doesn't shift the page. Without it the gate would have to
     * hardcode one provider's height in CSS.
     */
    public function height(): int;

    /**
     * Renders the custom element that builds this provider's player.
     *
     * The element receives the release's facts as typed attributes and builds the
     * provider's markup itself — see assets/ts/elements/ for the counterpart. Adding
     * a provider means an implementation here and an element there, and nothing else.
     *
     * @param string $title The release title, used for attribution and the player's
     *                      accessible name. Taken from the release so it can't drift.
     */
    public function toElement(string $title): string;
}
