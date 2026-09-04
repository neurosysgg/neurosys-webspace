/**
 * <player-consent height="300" embed="&lt;iframe …&gt;">
 *
 * The click-to-load gate in front of a third-party player. The embed markup is escaped into the
 * `embed` attribute by ReleaseView and only reaches the page once the visitor clicks, so nothing is
 * requested from the provider before then — see docs/branding.md for why that matters.
 *
 * The gate's own content is server-rendered, so it reads correctly with no JS; all this class adds
 * is the height reservation and the click. Being an element rather than a `querySelectorAll` sweep
 * means the browser upgrades it on its own when nav.ts swaps #content — there is nothing to re-run.
 */
export class PlayerConsent extends HTMLElement {
  private wired = false;

  connectedCallback(): void {
    // connectedCallback fires again if the element is ever moved in the DOM.
    if (this.wired) return;
    this.wired = true;

    this.reserveHeight();

    const button = this.querySelector('button');

    if (button === null) return;

    button.addEventListener('click', () => { this.loadEmbed(); }, { once: true });
  }

  /**
   * Reserves exactly the height of the player that replaces this gate, so the page doesn't jump.
   * The number comes from Embed::height() via the attribute rather than an inline style, so the
   * CSP needs no 'unsafe-inline' for our own markup.
   */
  private reserveHeight(): void {
    const height = this.getAttribute('height');

    // An empty attribute would set --player-height to "undefinedpx", which CSS drops — collapsing
    // the gate and bringing back the jump this whole mechanism exists to avoid.
    if (height === null || height === '') return;

    this.style.setProperty('--player-height', `${height}px`);
  }

  /** Swaps the gate for the real player. outerHTML, so nothing else in the .player wrapper moves. */
  private loadEmbed(): void {
    const embed = this.getAttribute('embed');

    // Nothing to swap in: leave the gate standing rather than replacing the player with nothing.
    if (embed === null || embed === '') return;

    this.outerHTML = embed;
  }
}

customElements.define('player-consent', PlayerConsent);
