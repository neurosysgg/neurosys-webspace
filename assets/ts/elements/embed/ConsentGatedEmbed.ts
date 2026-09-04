import { EmbedAttribute } from '../../model/EmbedAttribute.js';
import { Platform, displayName } from '../../model/Platform.js';
import { SoundCloudPlayerAttribute } from '../../model/SoundCloudPlayerAttribute.js';

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
export abstract class ConsentGatedEmbed extends HTMLElement {
  private wired = false;

  /** The platform this embed loads from. Mirrors Embed::platform(). */
  protected abstract platform(): Platform;

  /** Builds the provider's own markup. Called only once the visitor has consented. */
  protected abstract buildEmbed(): DocumentFragment;

  connectedCallback(): void {
    // connectedCallback fires again if the element is ever moved in the DOM.
    if (this.wired) return;
    this.wired = true;

    this.reserveHeight();
    this.renderGate();
  }

  /**
   * Reserves exactly the height of the player that replaces the gate, so the page doesn't jump.
   * The number comes from Embed::height() via the attribute rather than an inline style, so the
   * CSP needs no 'unsafe-inline' for our own markup.
   */
  private reserveHeight(): void {
    const height = this.getAttribute(SoundCloudPlayerAttribute.Height);

    // The stylesheet carries its own fallback, so bailing out here is safe — an empty attribute
    // would otherwise set --player-height to "undefinedpx", which CSS drops.
    if (height === null || height === '') return;

    this.style.setProperty('--player-height', `${height}px`);
  }

  private renderGate(): void {
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
  private load(): void {
    this.replaceChildren(this.buildEmbed());
    this.setAttribute(EmbedAttribute.Loaded, '');
  }
}
