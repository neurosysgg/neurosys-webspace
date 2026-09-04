/**
 * <player-consent provider="SoundCloud" height="300" embed="&lt;iframe …&gt;"></player-consent>
 *
 * The click-to-load gate in front of a third-party player, and then the player itself. Everything
 * it shows it builds: ReleaseView emits the tag and its attributes and nothing else.
 *
 * The embed markup is escaped into the `embed` attribute and only reaches the page once the visitor
 * clicks, so nothing is requested from the provider before then — docs/branding.md for why that
 * matters. Building the gate here rather than server-side does not weaken that: the transfer can
 * only be triggered by a click, a click needs this script, and this script writes the notice.
 *
 * With no JS the element stays empty. The CSS still reserves the player's height, so the box holds
 * its place rather than the page reflowing when the script lands.
 */
export class PlayerConsent extends HTMLElement {
  private wired = false;

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
    const height = this.getAttribute('height');

    // An empty attribute would set --player-height to "undefinedpx", which CSS drops — collapsing
    // the gate and bringing back the jump this whole mechanism exists to avoid. The stylesheet's
    // own fallback covers this case, so bailing out is safe.
    if (height === null || height === '') return;

    this.style.setProperty('--player-height', `${height}px`);
  }

  private renderGate(): void {
    const provider = this.getAttribute('provider') ?? 'the provider';

    const label = document.createElement('p');
    label.textContent = `${provider} player`;

    const button = document.createElement('button');
    button.className = 'btn-primary';
    button.textContent = 'Load player';
    button.addEventListener('click', () => { this.loadEmbed(); }, { once: true });

    const hint = document.createElement('small');
    hint.textContent = `Third-party content — clicking connects you to ${provider}’s servers.`;

    this.replaceChildren(label, button, hint);
  }

  /** Swaps the gate for the real player, in place. The `loaded` attribute restyles the box. */
  private loadEmbed(): void {
    const embed = this.getAttribute('embed');

    // Nothing to swap in: leave the gate standing rather than blanking the player area.
    if (embed === null || embed === '') return;

    this.innerHTML = embed;
    this.setAttribute('loaded', '');
  }
}

customElements.define('player-consent', PlayerConsent);
