<?php
$formatMeta = [
    'flac'  => ['label' => 'flac',  'meta' => 'lossless, 24-bit/48kHz'],
    'mp3'   => ['label' => 'mp3',   'meta' => '320 kbps'],
    'stems' => ['label' => 'stems', 'meta' => 'non-commercial — commercial licensing: neuro.sys@neurosys.gg'],
];
?>

<section class="hero">
  <div class="terminal">
    <div class="terminal-bar">
      <span class="dot"></span><span class="dot"></span><span class="dot"></span>
      <span class="terminal-title">release.log</span>
    </div>
    <div class="terminal-body">
      <p><span class="prompt">$</span> ./release --track "<?= htmlspecialchars($release['title']) ?>"</p>
      <p class="out"><span class="key">artist</span>  neuro.SYS</p>
      <p class="out"><span class="key">bpm</span>     <?= (int)$release['bpm'] ?></p>
      <p class="out"><span class="key">key</span>     <?= htmlspecialchars($release['key']) ?></p>
      <p class="out"><span class="key">status</span>  <span class="ok">ready</span></p>
      <p><span class="prompt">$</span> <span class="cursor">_</span></p>
    </div>
  </div>

  <div class="cover-art">
    <img
      src="<?=$release['cover_url']?>"
      onerror="this.src='/assets/img/cover-placeholder.svg';this.onerror=null"
      alt="<?= htmlspecialchars($release['title']) ?> cover art"
    />
  </div>
</section>

<section class="release-info">
  <h1><?= htmlspecialchars($release['title']) ?><span class="bang">!</span></h1>
  <p class="tagline">neuro.SYS &mdash; <?= htmlspecialchars($release['description']) ?></p>

  <?php if (!empty($release['soundcloud_html'])): ?>
  <div class="player">
    <?= $release['soundcloud_html'] ?>
  </div>
  <?php endif; ?>

  <div class="downloads">
    <h2>downloads</h2>

    <?php foreach ($formatMeta as $format => $info):
      if (empty($release['formats'][$format])) continue; ?>
    <a class="dl-card" data-no-spa href="/releases/<?= htmlspecialchars($slug) ?>/<?= $format ?>">
      <span class="dl-label"><?= htmlspecialchars($info['label']) ?></span>
      <span class="dl-meta"><?= htmlspecialchars($info['meta']) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</section>
