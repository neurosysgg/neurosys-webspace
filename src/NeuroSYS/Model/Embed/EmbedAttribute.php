<?php

declare(strict_types=1);

namespace NeuroSYS\Model\Embed;

use NeuroSYS\View\Html\AttributeName;

/**
 * The EmbedAttribute enum. What every consent-gated embed carries, whichever provider it is for.
 *
 * The counterpart to {@link SoundCloudPlayerAttribute}, split from it along the same line the
 * classes are split along: an {@link Embed} implementation sends its provider's own facts, and this
 * is what the gate around them needs regardless of who is being embedded. `assets/ts/model/` mirrors
 * it, and `ConsentGatedEmbed.ts` — the provider-agnostic base class — reads only these.
 *
 * That split is the whole reason the enum exists. {@link Embed::height()} is on the interface, not
 * on {@link SoundCloudEmbed}, so the height is an embed's fact rather than SoundCloud's; but it used
 * to travel under a {@link SoundCloudPlayerAttribute} case, which meant the abstract gate imported
 * one provider's enum to find out how much space to reserve. A second provider would have had to
 * emit an attribute named after the first one.
 */
enum EmbedAttribute: string implements AttributeName
{
    /**
     * The player's rendered height in px, which the gate reserves so the page does not jump.
     *
     * Written by {@link Embed::toElement()} and read twice on the client: by `ConsentGatedEmbed` to
     * size the placeholder, and by the provider's own element to size the thing that replaces it.
     */
    case Height = 'height';

    /**
     * Set on the embed once the visitor has consented, so the stylesheet can stop drawing a gate.
     *
     * The one case here that names an attribute the **server never writes** — the same arrangement
     * as {@link \NeuroSYS\Http\ResponseHeader::PoweredBy}, and for the same reason: it is a real
     * name with a real reader, and a name is worth having in one place even when only one side of
     * the wire writes it. `ConsentGatedEmbed.load()` sets it and `embed.css` selects on it.
     *
     * **A view must never emit this.** Doing so would style the box as a loaded player while the
     * gate is still the only thing in it. It is named here so the stylesheet's selector has
     * something on the other end of it, not so that a view can reach for it.
     */
    case Loaded = 'loaded';

    public function attribute(): string
    {
        return $this->value;
    }

    /**
     * Neither. A height is a number and `loaded` has no value at all — no address crosses on this
     * enum, which is the same answer {@link SoundCloudPlayerAttribute} gives and for the same reason:
     * the element builds its provider's URLs from its own constant host.
     */
    public function isUrl(): bool
    {
        return false;
    }
}
