<section class="page-section">
  <h2 class="page-heading">releases</h2>

  <?php foreach ($releases as $slug => $r): ?>
  <a class="release-card" href="/releases/<?= htmlspecialchars($slug) ?>/">
    <span class="release-title"><?= htmlspecialchars($r['title']) ?></span>
    <span class="release-meta"><?= (int)$r['bpm'] ?> bpm &middot; <?= htmlspecialchars($r['key']) ?> &middot; <?= htmlspecialchars($r['description']) ?></span>
  </a>
  <?php endforeach; ?>
</section>
