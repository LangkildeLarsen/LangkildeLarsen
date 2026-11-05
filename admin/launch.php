<?php
$lp = launch_load();
$bgFile = (string)($ui['bg'] ?? '');
$hasBg = $bgFile !== '' && is_file(UPLOAD_DIR . '/' . $bgFile);
[$greet] = greeting();
$trD = training_load();
$trS = training_stats($trD);
$nextEv = null;
foreach (cal_events(30)['events'] as $ev) {
    if (($ev['end'] ?? $ev['start']) >= time()) {
        $nextEv = $ev;
        break;
    }
}
$remOpen = array_values(array_filter(rem_load(), fn($r) => !$r['done']));
$wxc = load_json('weather-cache');
$wxp = weather_place();
$wxe = $wxc[$wxp['lat'] . ',' . $wxp['lon']] ?? null;
$wx = $wxe['d'] ?? null;
$editing = !empty($_GET['edit']);
$opt = launch_opts($lp);
$engines = launch_engines();
$eng = $engines[$opt['search']] ?? $engines['google'];
?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050506">
<?= app_head() ?>
<title>Ny fane</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
<link rel="stylesheet" href="../assets/launch.css?v=<?= filemtime(dirname(__DIR__) . '/assets/launch.css') ?>">
<script src="../assets/launch.js?v=<?= filemtime(dirname(__DIR__) . '/assets/launch.js') ?>" defer></script>
</head>
<body class="launch<?= $hasBg ? ' has-bg' : '' ?><?= $editing ? ' editing' : '' ?>"<?= $hasBg ? ' style="--bg-dim: ' . ((int)($ui['bg_dim'] ?? 45) / 100) . '; --glass-blur: ' . (int)($ui['bg_blur'] ?? 24) . 'px; --glass-a: ' . ((int)($ui['bg_card'] ?? 42) / 100) . '; --el-a: ' . ((int)($ui['bg_el'] ?? 7) / 100) . '; --img-blur: ' . (int)($ui['bg_img_blur'] ?? 0) . 'px"' : '' ?>>
<?php if ($hasBg): ?><div class="dash-bg" style="background-image: url('../uploads/<?= e($bgFile) ?>?v=<?= filemtime(UPLOAD_DIR . '/' . $bgFile) ?>')" aria-hidden="true"></div><?php endif; ?>
<div class="lp">
  <header class="lp-top">
    <span class="lp-date" data-lp-date></span>
    <nav>
      <?php if ($editing): ?><a href="./?view=launch" class="lp-pill on"><?= icon('check') ?>Færdig</a><?php endif; ?>
      <a href="./" class="lp-round" aria-label="Åbn Jarvis" title="Jarvis"><?= icon('home') ?></a>
      <a href="./?view=launch<?= $editing ? '' : '&amp;edit=1' ?>" class="lp-round<?= $editing ? ' on' : '' ?>" aria-label="Rediger genveje" title="Rediger genveje"><?= icon('gear') ?></a>
    </nav>
  </header>

  <main class="lp-hero">
    <?php if ($flash): ?><p class="lp-flash"><?= e($flash) ?></p><?php endif; ?>
    <p class="lp-clock" data-lp-clock><?= date('H:i') ?></p>
    <?php if ($opt['greet']): ?><p class="lp-greet"><?= e(str_replace('{n}', first_name($me), $greet)) ?></p><?php endif; ?>
    <form class="lp-search" action="<?= e($eng[1]) ?>" method="get" data-lp-search>
      <?= $opt['search'] === 'google' ? '<svg class="ic lp-g" viewBox="0 0 20 20" aria-hidden="true"><path fill="currentColor" transform="translate(-244 -7239)" d="M263.821537,7247.00386 L254.211298,7247.00386 C254.211298,7248.0033 254.211298,7250.00218 254.205172,7251.00161 L259.774046,7251.00161 C259.560644,7252.00105 258.804036,7253.40026 257.734984,7254.10487 C257.733963,7254.10387 257.732942,7254.11086 257.7309,7254.10986 C256.309581,7255.04834 254.43389,7255.26122 253.041161,7254.98137 C250.85813,7254.54762 249.130492,7252.96451 248.429023,7250.95364 C248.433107,7250.95064 248.43617,7250.92266 248.439233,7250.92066 C248.000176,7249.67336 248.000176,7248.0033 248.439233,7247.00386 L248.438212,7247.00386 C249.003881,7245.1669 250.783592,7243.49084 252.969687,7243.0321 C254.727956,7242.65931 256.71188,7243.06308 258.170978,7244.42831 C258.36498,7244.23842 260.856372,7241.80579 261.043226,7241.6079 C256.0584,7237.09344 248.076756,7238.68155 245.090149,7244.51127 L245.089128,7244.51127 C245.089128,7244.51127 245.090149,7244.51127 245.084023,7244.52226 L245.084023,7244.52226 C243.606545,7247.38565 243.667809,7250.75975 245.094233,7253.48622 C245.090149,7253.48921 245.087086,7253.49121 245.084023,7253.49421 C246.376687,7256.0028 248.729215,7257.92672 251.563684,7258.6593 C254.574796,7259.44886 258.406843,7258.90916 260.973794,7256.58747 C260.974815,7256.58847 260.975836,7256.58947 260.976857,7256.59047 C263.15172,7254.63157 264.505648,7251.29445 263.821537,7247.00386"/></svg>' : icon('search') ?>
      <input name="q" placeholder="Søg på <?= e($eng[0]) ?> eller skriv en adresse" autocomplete="off" autofocus aria-label="Søg">
      <kbd>enter</kbd>
    </form>

    <div class="lp-cards" data-lp-cards>
      <?php foreach ($lp['links'] as $l): $host = launch_host($l['url']); $hue = launch_hue($host); $own = $host === 'langkildelarsen.dk' || str_ends_with($host, '.langkildelarsen.dk'); $fav = $own ? '../assets/brand/fav-96.png' : 'https://www.google.com/s2/favicons?domain=' . rawurlencode($host) . '&sz=128'; $iconUrl = launch_src($l['icon'] ?? ''); $hasIcon = $iconUrl !== ''; if ($hasIcon) { $fav = $iconUrl; } $imgUrl = launch_src($l['img'] ?? ''); ?>
        <div class="sc" data-host="<?= e($host) ?>" data-id="<?= e($l['id']) ?>" draggable="<?= $editing ? 'true' : 'false' ?>" style="--h: <?= $hue ?>">
          <a class="sc-link" href="<?= e($l['url']) ?>" aria-label="<?= e($l['name']) ?>"></a>
          <span class="sc-img<?= $imgUrl ? ' has-img' : '' ?><?= !empty($l['center']) ? ' has-center' : '' ?>">
            <?php if (!empty($l['center'])): ?><img class="sc-center" src="<?= e($fav) ?>" alt="" referrerpolicy="no-referrer" data-fallback><?php endif; ?>
            <?php if ($imgUrl): ?><img class="sc-photo" src="<?= e($imgUrl) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" style="object-position: <?= e(launch_pos($l['pos'] ?? '')) ?>"><?php endif; ?>
          </span>
          <span class="sc-bar">
            <span class="sc-logo"><img src="<?= e($fav) ?>" alt="" data-fallback referrerpolicy="no-referrer"></span>
            <span class="sc-tx"><strong><?= e($l['name']) ?></strong></span>
            <span class="sc-go"><?= icon('arrow') ?></span>
          </span>
          <?php if ($editing): ?>
            <span class="sc-tools">
              <button type="button" class="sc-edit" data-edit="<?= e($l['id']) ?>" data-name="<?= e($l['name']) ?>" data-url="<?= e($l['url']) ?>" data-img="<?= e($imgUrl) ?>" data-icon="<?= $hasIcon ? e($fav) : '' ?>" data-pos="<?= e(launch_pos($l['pos'] ?? '')) ?>" data-center="<?= !empty($l['center']) ? '1' : '' ?>" aria-label="Rediger <?= e($l['name']) ?>"><?= icon('gear') ?></button>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="launch_remove"><input type="hidden" name="id" value="<?= e($l['id']) ?>"><button class="sc-del" aria-label="Fjern <?= e($l['name']) ?>"><?= icon('cross') ?></button></form>
            </span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($editing): ?><button type="button" class="sc add" data-edit="" data-name="" data-url=""><span class="sc-plus"><?= icon('plus') ?></span><small>Tilføj genvej</small></button><?php endif; ?>
    </div>
    <?php if ($editing): ?>
      <p class="lp-hint">Træk i kortene for at ændre rækkefølgen.</p>
      <form method="post" class="lp-opts">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="launch_opts">
        <label>Søgemaskine<select name="search" onchange="this.form.submit()"><?php foreach ($engines as $k => [$n]): ?><option value="<?= e($k) ?>"<?= $opt['search'] === $k ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></label>
        <label class="check"><input type="checkbox" name="greet" value="1"<?= $opt['greet'] ? ' checked' : '' ?> onchange="this.form.submit()"> Vis hilsen</label>
      </form>
    <?php endif; ?>
  </main>

</div>

<dialog class="lp-dialog" data-lp-dialog>
  <form method="post" enctype="multipart/form-data" class="lp-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="launch_save">
    <input type="hidden" name="id" value="">
    <h2 data-lp-dtitle>Ny genvej</h2>
    <label>Navn<input name="name" required maxlength="40" placeholder="Fx EFUTURE"></label>
    <label>Adresse<input name="url" required placeholder="efuture.dk"></label>
    <div class="lp-drops">
      <div class="lp-drop" data-drop="img" tabindex="0" role="button" aria-label="Billede: klik, træk eller sæt ind">
        <span class="lp-drop-prev" data-prev></span>
        <span class="lp-drop-tx"><strong>Billede</strong><small>Klik, træk ind eller Ctrl+V</small></span>
        <button type="button" class="lp-drop-x" data-clear aria-label="Fjern billede">×</button>
        <input type="file" name="img" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
        <input type="checkbox" name="remove_img" value="1" hidden>
      </div>
      <div class="lp-drop lp-drop-icon" data-drop="icon" tabindex="0" role="button" aria-label="Ikon: klik, træk eller sæt ind">
        <span class="lp-drop-prev" data-prev></span>
        <span class="lp-drop-tx"><strong>Ikon</strong><small>PNG, SVG eller WebP</small></span>
        <button type="button" class="lp-drop-x" data-clear aria-label="Fjern ikon">×</button>
        <input type="file" name="icon" accept="image/png,image/svg+xml,image/webp,image/jpeg" hidden>
        <input type="checkbox" name="remove_icon" value="1" hidden>
      </div>
    </div>
    <div class="lp-pos" data-pos-box hidden>
      <span class="lp-pos-h"><strong>Position af billede</strong><small>Klik eller træk i billedet</small></span>
      <div class="lp-pos-frame" data-pos-frame><img alt="" data-pos-img referrerpolicy="no-referrer"><i data-pos-dot></i></div>
      <input type="hidden" name="pos" value="50% 50%" data-pos-in>
    </div>
    <label>Eller link til billede<input name="img_url" placeholder="https://…/billede.jpg" inputmode="url"></label>
    <label>Eller link til ikon<input name="icon_url" placeholder="https://…/ikon.png" inputmode="url"></label>
    <label class="check"><input type="checkbox" name="center" value="1"> Vis ikonet midt på billedet</label>
    <label class="check"><input type="checkbox" name="auto" value="1" checked> Hent sidens billede automatisk, hvis jeg ikke vælger et</label>
    <div class="actions">
      <button class="btn">Gem</button>
      <button type="button" class="btn ghost" data-lp-close>Annuller</button>
    </div>
  </form>
</dialog>
<form method="post" data-lp-order hidden><?= csrf_field() ?><input type="hidden" name="action" value="launch_order"><input type="hidden" name="order" value=""></form>
</body>
</html>
