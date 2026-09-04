<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

use NeuroSYS\Model\Platform;

/**
 * The Embed interface. A third-party media player attached to a release.
 *
 * Implementations own their provider's markup so that {@link \NeuroSYS\Model\Release}
 * can declare a player as typed parameters rather than pasted HTML.
 *
 * Every embed loads from someone else's servers, so the rendered markup is never
 * emitted directly into the page — {@link \NeuroSYS\View\ReleaseView} puts it behind
 * a click-to-load consent gate, worded from {@link self::platform()}.
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
     * Renders the provider's embed markup.
     *
     * @param string $title The release title, used for attribution and the player's
     *                      accessible name. Taken from the release so it can't drift.
     */
    public function toHtml(string $title): string;
}
