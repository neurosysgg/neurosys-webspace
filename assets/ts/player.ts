/**
 * The SoundCloud consent gate and the cover-art fallback.
 *
 * Both re-run after every SPA navigation, because #content is replaced wholesale.
 */

import { datasetValue, queryAll } from './dom.js';

// The consent gate reserves exactly the height of the player that replaces it, so the
// page doesn't jump on load. The number comes from Embed::height() via a data attribute
// rather than an inline style, so the CSP needs no 'unsafe-inline' for our own markup.
function sizeConsentGates(): void {
  for (const gate of queryAll<HTMLElement>('.player-consent[data-player-height]')) {
    const height = datasetValue(gate, 'playerHeight');

    // An empty attribute would set --player-height to "undefinedpx", which CSS drops — collapsing
    // the gate and bringing back the jump this whole mechanism exists to avoid.
    if (height === null) continue;

    gate.style.setProperty('--player-height', `${height}px`);
  }
}

// Cover art falls back to the placeholder when the file host 404s. This was an inline
// onerror= attribute; as a listener it survives a strict script-src.
function wireCoverFallback(): void {
  for (const img of queryAll<HTMLImageElement>('.cover-art img[data-fallback]')) {
    if (img.dataset.fallbackWired) continue;

    // An empty data-fallback would set src="undefined" and 404 a second time.
    const fallback = datasetValue(img, 'fallback');

    if (fallback === null) continue;

    img.dataset.fallbackWired = '1';
    img.addEventListener('error', function handle() {
      img.removeEventListener('error', handle);
      img.src = fallback;
    });

    // A broken image may have finished failing before this script ran.
    if (img.complete && img.naturalWidth === 0) img.src = fallback;
  }
}

function wireConsentButtons(): void {
  for (const btn of queryAll<HTMLElement>('.player-consent-btn')) {
    btn.addEventListener('click', () => {
      const consent = btn.closest<HTMLElement>('.player-consent');

      if (consent === null) return;

      const embed = datasetValue(consent, 'embed');
      const slot  = consent.parentElement;

      // Missing either one used to mean a TypeError, or replacing the player with the literal
      // text "undefined". Leaving the gate standing is the honest failure.
      if (embed === null || slot === null) return;

      slot.innerHTML = embed;
    });
  }
}

export function initPlayer(): void {
  sizeConsentGates();
  wireCoverFallback();
  wireConsentButtons();
}
