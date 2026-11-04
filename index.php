<?php
require __DIR__ . '/inc/bootstrap.php';
$p = get_profile();
$projects = array_values(array_filter($p['projects'], fn($x) => $x['show'] && $x['image'] !== ''));
$shown = $projects;
$wide = (3 - (count($shown) + 3) % 3) % 3;
$wideAt = $wide ? array_slice([5, count($shown) - 1], 0, $wide) : [];
$photo = $p['photo'] ? 'uploads/' . $p['photo'] : 'assets/brand/avatar-round.png';
$first = explode(' ', $p['name'])[0];
$words = preg_split('/\s+/', trim($p['name']));
$rest = trim(mb_substr($p['name'], mb_strlen($first)));
$logos = [];
foreach (array_merge($p['experience'], $p['education']) as $x) {
    if (!empty($x['logo'])) {
        $logos[$x['logo']] = ['logo' => $x['logo'], 'name' => $x['company'] ?? $x['school'], 'url' => $x['url'] ?? ''];
    }
}
$logos = array_values($logos);
$recent = array_slice($p['experience'], 0, 6);
$older = array_slice($p['experience'], 6);

function exp_item(array $x): string
{
    ob_start(); ?>
    <li class="job">
      <span class="co<?= $x['logo'] ? '' : ' none' ?>"><?php if ($x['logo']): ?><img src="<?= e(img_src($x['logo'])) ?>" alt="" loading="lazy"><?php else: ?><?= e(mb_substr($x['company'], 0, 1)) ?><?php endif; ?></span>
      <div class="job-body">
        <div class="job-head">
          <h3><?= e($x['role']) ?></h3>
          <?php if ($x['period']): ?><time><?= e($x['period']) ?></time><?php endif; ?>
        </div>
        <p class="company"><?php if ($x['url']): ?><a href="<?= e($x['url']) ?>" target="_blank" rel="noopener"><?= e($x['company']) ?></a><?php else: ?><?= e($x['company']) ?><?php endif; ?></p>
        <?php if ($x['text']): ?><p class="desc"><?= nl2br(e($x['text'])) ?></p><?php endif; ?>
      </div>
    </li>
    <?php return ob_get_clean();
}
?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#050506">
<title><?= e($p['name']) ?> · <?= e($p['title']) ?></title>
<meta name="description" content="<?= e($p['title']) ?> – <?= e($p['location']) ?>">
<meta property="og:title" content="<?= e($p['name']) ?>">
<meta property="og:description" content="<?= e($p['title']) ?>">
<meta property="og:image" content="<?= e($photo) ?>">
<link rel="icon" type="image/png" sizes="32x32" href="assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
<script>document.documentElement.classList.add('js')</script>
<script src="assets/site.js?v=<?= filemtime(__DIR__ . '/assets/site.js') ?>" defer></script>
</head>
<body>
<div class="orb" aria-hidden="true"></div>
<div class="cursor-light" aria-hidden="true"></div>

<header class="nav">
  <a href="#top" class="nav-brand"><img src="assets/brand/avatar-round.png" alt=""><span><?= e($first) ?></span></a>
  <nav>
    <?php if ($shown): ?><a href="#projekter">Projekter</a><?php endif; ?>
    <a href="#erfaring">Erfaring</a>
    <a href="#om">Om mig</a>
  </nav>
  <a href="mailto:<?= e($p['email']) ?>" class="btn small">Kontakt</a>
</header>

<main class="page" id="top">
  <section class="hero">
    <div class="hero-text">
      <h1 aria-label="<?= e($p['name']) ?>"><?php foreach ($words as $i => $w): ?><span class="w<?= $i ? ' dim' : '' ?>" style="--i: <?= $i ?>" aria-hidden="true"><?= e($w) ?></span> <?php endforeach; ?></h1>
      <p class="title"><?= e($p['title']) ?></p>
      <div class="links">
        <a class="btn" href="mailto:<?= e($p['email']) ?>">Skriv til mig</a>
        <?php if ($p['linkedin']): ?><a class="btn ghost" href="<?= e($p['linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
        <?php if ($p['dribbble']): ?><a class="btn ghost" href="<?= e($p['dribbble']) ?>" target="_blank" rel="noopener">Dribbble</a><?php endif; ?>
      </div>
    </div>
    <div class="hero-photo">
      <div class="tilt">
        <div class="frame glass"><img src="<?= e($photo) ?>" alt="<?= e($p['name']) ?>"><span class="glare" aria-hidden="true"></span></div>
      </div>
      <?php if (!empty($p['highlights'][1])): ?><div class="chip glass c1"><strong><?= e($p['highlights'][1]['value']) ?></strong><span><?= e($p['highlights'][1]['label']) ?></span></div><?php endif; ?>
      <div class="chip glass c2"><span><?= e($p['location']) ?></span></div>
    </div>
  </section>

  <?php if ($logos): ?>
  <section class="logos glass" aria-label="Virksomheder jeg har arbejdet med">
    <p>Har arbejdet med</p>
    <div class="marquee">
      <div class="track">
        <?php for ($k = 0; $k < 2; $k++): foreach ($logos as $l): ?>
          <span class="mark" <?= $k ? 'aria-hidden="true"' : '' ?>><span class="co"><img src="<?= e(img_src($l['logo'])) ?>" alt="" loading="lazy"></span><?= e($l['name']) ?></span>
        <?php endforeach; endfor; ?>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <?php if ($p['highlights']): ?>
  <section class="numbers">
    <?php foreach ($p['highlights'] as $h): ?>
      <div class="num glass reveal"><strong data-count="<?= e($h['value']) ?>"><?= e($h['value']) ?></strong><span><?= e($h['label']) ?></span></div>
    <?php endforeach; ?>
  </section>
  <?php endif; ?>

  <?php if ($shown): ?>
  <section class="block" id="projekter">
    <div class="block-head">
      <h2>Udvalgte projekter</h2>
      <?php if ($p['dribbble']): ?><a href="<?= e($p['dribbble']) ?>" target="_blank" rel="noopener" class="more">Se alle på Dribbble <?= icon('arrow') ?></a><?php endif; ?>
    </div>
    <div class="bento">
      <?php foreach ($shown as $i => $x): ?>
        <a class="shot reveal<?= $i === 0 ? ' big' : (in_array($i, $wideAt, true) ? ' wide' : '') ?>" style="--d: <?= $i % 3 ?>" href="<?= e($x['url']) ?>" target="_blank" rel="noopener">
          <img src="<?= e(img_src($x['image'])) ?>" alt="<?= e($x['title']) ?>" loading="<?= $i < 3 ? 'eager' : 'lazy' ?>">
          <span class="label"><?= e($x['title']) ?> <i><?= icon('arrow') ?></i></span>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <section class="block split" id="om">
    <div class="glass card about reveal">
      <h2>Om mig</h2>
      <?php foreach (preg_split('/\R\s*\R/', trim($p['about'])) as $para): ?>
        <p><?= nl2br(e($para)) ?></p>
      <?php endforeach; ?>
    </div>
    <div class="glass card reveal">
      <h2>Kompetencer</h2>
      <ul class="chips"><?php foreach ($p['skills'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul>
      <?php if ($p['languages']): ?>
        <h2 class="sub">Sprog</h2>
        <ul class="chips"><?php foreach ($p['languages'] as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  </section>

  <section class="block split wide-left" id="erfaring">
    <div class="glass card reveal">
      <h2>Erfaring</h2>
      <ol class="jobs">
        <?php foreach ($recent as $x) echo exp_item($x); ?>
      </ol>
      <?php if ($older): ?>
        <details class="older">
          <summary><span class="open-label">Vis <?= count($older) ?> tidligere</span><span class="close-label">Skjul tidligere</span></summary>
          <ol class="jobs"><?php foreach ($older as $x) echo exp_item($x); ?></ol>
        </details>
      <?php endif; ?>
    </div>
    <?php if ($p['education']): ?>
    <div class="glass card reveal edu-card">
      <h2>Uddannelse</h2>
      <ul class="edu">
        <?php foreach ($p['education'] as $x): ?>
          <li>
            <span class="co<?= $x['logo'] ? '' : ' none' ?>"><?php if ($x['logo']): ?><img src="<?= e(img_src($x['logo'])) ?>" alt="" loading="lazy"><?php else: ?><?= e(mb_substr($x['school'], 0, 1)) ?><?php endif; ?></span>
            <div><h3><?= e($x['title']) ?></h3><p><?= e($x['school']) ?><?= $x['period'] ? ' · ' . e($x['period']) : '' ?></p></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </section>

  <section class="cta glass reveal">
    <h2>Lad os lave noget godt sammen.</h2>
    <p>Har du en webshop, et brand eller en idé, der skal se skarp ud og sælge? Skriv, så tager vi en snak.</p>
    <a class="btn big" href="mailto:<?= e($p['email']) ?>"><?= e($p['email']) ?></a>
  </section>

  <footer>
    <span>© <?= date('Y') ?> <?= e($p['name']) ?></span>
    <span class="foot-links">
      <?php if ($p['linkedin']): ?><a href="<?= e($p['linkedin']) ?>" target="_blank" rel="noopener">LinkedIn</a><?php endif; ?>
      <?php if ($p['dribbble']): ?><a href="<?= e($p['dribbble']) ?>" target="_blank" rel="noopener">Dribbble</a><?php endif; ?>
      <a href="admin/">Log ind</a>
    </span>
  </footer>
</main>
</body>
</html>
