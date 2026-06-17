<section class="page-section">
  <div class="terminal" style="max-width:480px">
    <div class="terminal-bar">
      <span class="dot"></span><span class="dot"></span><span class="dot"></span>
      <span class="terminal-title">error.log</span>
    </div>
    <div class="terminal-body">
      <p><span class="prompt">$</span> find <?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/') ?></p>
      <p class="out"><span class="accent-2">error</span>  404 — not found</p>
      <p><span class="prompt">$</span> <span class="cursor">_</span></p>
    </div>
  </div>
  <p style="margin-top:1.5rem"><a href="/">← home</a></p>
</section>
