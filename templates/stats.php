<?php
if (!is_file($logFile)) {
    echo '<section class="page-section"><p class="muted">No downloads logged yet.</p></section>';
    return;
}

$byFormat = [];
$byDay    = [];
$total    = 0;

foreach (file($logFile) as $rawLine) {
    $entry = json_decode(trim($rawLine), true);
    if (!is_array($entry)) continue;
    $total++;
    $key = ($entry['slug'] ?? '?') . '/' . ($entry['format'] ?? '?');
    $byFormat[$key] = ($byFormat[$key] ?? 0) + 1;
    $day = substr($entry['time'] ?? '', 0, 10) ?: '?';
    $byDay[$day] = ($byDay[$day] ?? 0) + 1;
}
?>

<section class="page-section">
  <h2 class="page-heading">stats</h2>
  <p class="muted">total downloads: <strong><?= $total ?></strong></p>

  <h3 class="stats-sub">by format</h3>
  <table class="stats-table">
    <?php foreach ($byFormat as $key => $count): ?>
    <tr>
      <td><?= htmlspecialchars($key) ?></td>
      <td class="stats-count"><?= $count ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <h3 class="stats-sub">by day</h3>
  <table class="stats-table">
    <?php ksort($byDay); foreach ($byDay as $day => $count): ?>
    <tr>
      <td><?= htmlspecialchars($day) ?></td>
      <td class="stats-count"><?= $count ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</section>
