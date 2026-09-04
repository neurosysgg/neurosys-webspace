import { Platform, displayName } from '../../model/Platform.js';
/**
 * Base for a player that loads from someone else's servers.
 *
 * Mirrors the Embed interface on the PHP side: a provider names its platform and builds its own
 * markup, and everything about the consent gate — the wording, the reserved height, the click,
 * the swap — belongs here so no provider has to reimplement it. Adding a provider is a subclass
 * and a customElements.define, the same way it is a new Embed implementation server-side.
 *
 * Nothing is requested from the provider until the visitor clicks: buildEmbed() is called from the
 * click handler and nowhere else, so the iframe does not exist before then. That is the whole point
 * of the gate — see docs/branding.md for why the transfer matters (CJEU C-40/17).
 */
export class ConsentGatedEmbed extends HTMLElement {
    wired = false;
    connectedCallback() {
        // connectedCallback fires again if the element is ever moved in the DOM.
        if (this.wired)
            return;
        this.wired = true;
        this.reserveHeight();
        this.renderGate();
    }
    /**
     * Reserves exactly the height of the player that replaces the gate, so the page doesn't jump.
     * The number comes from Embed::height() via the attribute rather than an inline style, so the
     * CSP needs no 'unsafe-inline' for our own markup.
     */
    reserveHeight() {
        const height = this.getAttribute('height');
        // The stylesheet carries its own fallback, so bailing out here is safe — an empty attribute
        // would otherwise set --player-height to "undefinedpx", which CSS drops.
        if (height === null || height === '')
            return;
        this.style.setProperty('--player-height', `${height}px`);
    }
    renderGate() {
        const provider = displayName(this.platform());
        const label = document.createElement('p');
        label.textContent = `${provider} player`;
        const button = document.createElement('button');
        button.className = 'btn-primary';
        button.textContent = 'Load player';
        button.addEventListener('click', () => { this.load(); }, { once: true });
        const hint = document.createElement('small');
        hint.textContent = `Third-party content — clicking connects you to ${provider}’s servers.`;
        this.replaceChildren(label, button, hint);
    }
    /** Swaps the gate for the real player, in place. The `loaded` attribute restyles the box. */
    load() {
        this.replaceChildren(this.buildEmbed());
        this.setAttribute('loaded', '');
    }
}
//# sourceMappingURL=ConsentGatedEmbed.js.map