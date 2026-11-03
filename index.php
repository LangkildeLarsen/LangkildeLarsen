<?php
require __DIR__ . '/inc/bootstrap.php';
$p = get_profile();
$projects = array_values(array_filter($p['projects'], fn($x) => $x['show'] && $x['image'] !== ''));
$initials = implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(explode(' ', $p['name']), 0, 2)));
?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#050506">
<title><?= e($p['name']) ?></title>
<meta name="description" content="<?= e($p['title']) ?> – <?= e($p['location']) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="orb" aria-hidden="true"></div>
<main class="page">
  <header class="hero glass">
    <div class="photo">
      <?php if ($p['photo']): ?>
        <img src="uploads/<?= e($p['photo']) ?>" alt="<?= e($p['name']) ?>">
      <?php else: ?>
        <span><?= e($initials) ?></span>
      <?php endif; ?>
    </div>
    <div>
      <p class="eyebrow"><?= e($p['location']) ?></p>
      <h1><?= e($p['name']) ?></h1>
      <p class="title"><?= e($p['title']) ?></p>
      <div class="links">
        <a class="btn" href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a>
        <?php if ($p['linkedin']): ?>
          <a class="btn ghost" href="<?= e($p['linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a>
        <?php endif; ?>
        <?php if ($p['dribbble']): ?>
          <a class="btn ghost" href="<?= e($p['dribbble']) ?>" target="_blank" rel="noopener">Dribbble</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <?php if ($p['now']): ?>
  <section class="glass now">
    <h2>Lige nu</h2>
    <span class="pulse" aria-hidden="true"></span>
    <p><?= nl2br(e($p['now'])) ?></p>
  </section>
  <?php endif; ?>

  <?php if ($p['about']): ?>
  <section class="glass">
    <h2>Om mig</h2>
    <p class="lead"><?= nl2br(e($p['about'])) ?></p>
  </section>
  <?php endif; ?>

  <?php if ($projects): ?>
  <section class="glass work">
    <div class="work-head">
      <h2>Udvalgte projekter</h2>
      <?php if ($p['dribbble']): ?><a href="<?= e($p['dribbble']) ?>" target="_blank" rel="noopener" class="quiet">Alle på Dribbble ↗</a><?php endif; ?>
    </div>
    <div class="shots">
      <?php $shown = array_slice($projects, 0, 9); $odd = count($shown) % 2 === 1; ?>
      <?php foreach ($shown as $i => $x): ?>
        <a class="shot<?= $i === 0 && $odd ? ' lead-shot' : '' ?>" href="<?= e($x['url']) ?>" target="_blank" rel="noopener">
          <img src="<?= e(img_src($x['image'])) ?>" alt="<?= e($x['title']) ?>" loading="lazy">
          <span><?= e($x['title']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($p['experience']): ?>
  <section class="glass">
    <h2>Erfaring</h2>
    <ol class="timeline">
      <?php foreach ($p['experience'] as $x): ?>
      <li>
        <div class="row">
          <?php if ($x['logo']): ?><span class="co-logo"><img src="uploads/<?= e($x['logo']) ?>" alt="<?= e($x['company']) ?>" loading="lazy"></span><?php endif; ?>
          <h3><?= e($x['role']) ?> <span>· <?php if ($x['url']): ?><a href="<?= e($x['url']) ?>" target="_blank" rel="noopener"><?= e($x['company']) ?></a><?php else: ?><?= e($x['company']) ?><?php endif; ?></span></h3>
          <?php if ($x['period']): ?><time><?= e($x['period']) ?></time><?php endif; ?>
        </div>
        <?php if ($x['text']): ?><p><?= nl2br(e($x['text'])) ?></p><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </section>
  <?php endif; ?>

  <?php if ($p['education']): ?>
  <section class="glass">
    <h2>Uddannelse</h2>
    <ul class="edu">
      <?php foreach ($p['education'] as $x): ?>
      <li>
        <div>
          <h3><?= e($x['title']) ?></h3>
          <span><?= e($x['school']) ?></span>
        </div>
        <?php if ($x['period']): ?><time><?= e($x['period']) ?></time><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if ($p['skills']): ?>
  <section class="glass">
    <h2>Kompetencer</h2>
    <ul class="chips">
      <?php foreach ($p['skills'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?>
    </ul>
    <?php if ($p['languages']): ?>
    <h2 class="sub">Sprog</h2>
    <ul class="chips">
      <?php foreach ($p['languages'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <footer>
    <span>© <?= date('Y') ?> <?= e($p['name']) ?></span>
    <a href="admin/" class="quiet">Log ind</a>
  </footer>
</main>
</body>
</html>
