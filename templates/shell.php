<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <meta name="description" content="neuro.SYS — electronic music." />
  <link rel="stylesheet" href="/assets/css/style.css" />
</head>
<body>

  <header class="site-header">
    <a class="logo" href="/">neuro<span class="logo-dot">.</span>SYS</a>
    <nav class="site-nav">
      <a href="/releases">releases</a>
    </nav>
  </header>

  <main id="content">
    <?php require __DIR__ . '/' . $template . '.php'; ?>
  </main>

  <footer class="site-footer">
    <p>neuro.SYS &middot; <a href="mailto:neuro.sys@neurosys.gg">neuro.sys@neurosys.gg</a></p>
  </footer>

  <script src="/assets/js/nav.js"></script>
</body>
</html>
