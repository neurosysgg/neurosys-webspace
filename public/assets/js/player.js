(function () {
  // The consent gate reserves exactly the height of the player that replaces it, so the
  // page doesn't jump on load. The number comes from Embed::height() via a data attribute
  // rather than an inline style, so the CSP needs no 'unsafe-inline' for our own markup.
  function sizeConsentGates() {
    document.querySelectorAll('.player-consent[data-player-height]').forEach(function (gate) {
      gate.style.setProperty('--player-height', gate.dataset.playerHeight + 'px');
    });
  }

  // Cover art falls back to the placeholder when the file host 404s. This was an inline
  // onerror= attribute; as a listener it survives a strict script-src.
  function wireCoverFallback() {
    document.querySelectorAll('.cover-art img[data-fallback]').forEach(function (img) {
      if (img.dataset.fallbackWired) return;
      img.dataset.fallbackWired = '1';
      img.addEventListener('error', function handle() {
        img.removeEventListener('error', handle);
        img.src = img.dataset.fallback;
      });
      // A broken image may have finished failing before this script ran.
      if (img.complete && img.naturalWidth === 0) img.src = img.dataset.fallback;
    });
  }

  function initPlayer() {
    sizeConsentGates();
    wireCoverFallback();

    document.querySelectorAll('.player-consent-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const consent = btn.closest('.player-consent');
        consent.parentElement.innerHTML = consent.dataset.embed;
      });
    });
  }

  initPlayer();
  document.addEventListener('neurosys:navigate', initPlayer);
}());
