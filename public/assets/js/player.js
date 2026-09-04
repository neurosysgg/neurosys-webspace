(function () {
  function initPlayer() {
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
