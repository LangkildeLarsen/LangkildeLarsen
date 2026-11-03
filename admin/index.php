<?php
require dirname(__DIR__) . '/inc/bootstrap.php';

$auth = load_json('auth');
$needsSetup = empty($auth['hash']);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$error = null;

function flash(string $msg): void
{
    $_SESSION['flash'] = $msg;
}

function client_ip(): string
{
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x');
}

function locked_out(): int
{
    $l = load_json('login');
    $e = $l[client_ip()] ?? null;
    if ($e && $e['count'] >= 5 && time() - $e['last'] < 900) {
        return (int)ceil((900 - (time() - $e['last'])) / 60);
    }
    return 0;
}

function register_fail(): void
{
    $l = load_json('login');
    $k = client_ip();
    $e = $l[$k] ?? ['count' => 0, 'last' => 0];
    if (time() - $e['last'] > 900) {
        $e['count'] = 0;
    }
    $e['count']++;
    $e['last'] = time();
    $l[$k] = $e;
    save_json('login', $l);
}

function clear_fails(): void
{
    $l = load_json('login');
    unset($l[client_ip()]);
    save_json('login', $l);
}

function default_categories(): array
{
    return ['Tech', 'Hjem', 'Mad', 'Økonomi', 'Produktivitet', 'Rejser'];
}

$action = $_POST['action'] ?? '';

if ($action === 'setup' && $needsSetup) {
    verify_csrf();
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($pw) < 10) {
        $error = 'Adgangskoden skal være mindst 10 tegn.';
    } elseif ($pw !== ($_POST['password2'] ?? '')) {
        $error = 'De to adgangskoder er ikke ens.';
    } else {
        save_json('auth', ['hash' => password_hash($pw, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        flash('Velkommen! Din adgangskode er gemt.');
        redirect('index.php');
    }
}

if ($action === 'login' && !$needsSetup) {
    verify_csrf();
    if ($mins = locked_out()) {
        $error = "For mange forsøg. Prøv igen om $mins min.";
    } elseif (password_verify((string)($_POST['password'] ?? ''), $auth['hash'])) {
        clear_fails();
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        redirect('index.php');
    } else {
        register_fail();
        sleep(1);
        $error = 'Forkert adgangskode.';
    }
}

if (!is_logged_in()) {
    ?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050506">
<title>Log ind</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css">
</head>
<body class="auth">
<form method="post" class="auth-box">
  <?= csrf_field() ?>
  <h1><?= $needsSetup ? 'Opret adgangskode' : 'Log ind' ?></h1>
  <?php if ($needsSetup): ?>
    <p class="muted">Første gang: vælg den adgangskode, du vil logge ind med.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>
  <input type="hidden" name="action" value="<?= $needsSetup ? 'setup' : 'login' ?>">
  <label>Adgangskode<input type="password" name="password" required autofocus autocomplete="<?= $needsSetup ? 'new-password' : 'current-password' ?>"></label>
  <?php if ($needsSetup): ?>
    <label>Gentag adgangskode<input type="password" name="password2" required autocomplete="new-password"></label>
  <?php endif; ?>
  <button class="btn"><?= $needsSetup ? 'Gem og fortsæt' : 'Log ind' ?></button>
  <a href="../" class="muted small">← Tilbage til siden</a>
</form>
</body>
</html>
<?php
    exit;
}

$hacks = load_json('lifehacks');
$categories = load_json('categories', default_categories());

if ($action) {
    verify_csrf();

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirect('../');
    }

    if ($action === 'save_hack') {
        $id = $_POST['id'] ?: bin2hex(random_bytes(6));
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            flash('Giv din lifehack en titel.');
            redirect('index.php?view=edit' . ($_POST['id'] ? '&id=' . urlencode($_POST['id']) : ''));
        }
        $cat = trim((string)($_POST['category'] ?? ''));
        if ($cat !== '' && !in_array($cat, $categories, true)) {
            $categories[] = $cat;
            save_json('categories', $categories);
        }
        $tags = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['tags'] ?? '')))));
        $existing = $hacks[$id] ?? null;
        $hacks[$id] = [
            'id' => $id,
            'title' => $title,
            'category' => $cat,
            'content' => trim((string)($_POST['content'] ?? '')),
            'link' => trim((string)($_POST['link'] ?? '')),
            'tags' => $tags,
            'pinned' => !empty($_POST['pinned']),
            'created' => $existing['created'] ?? date('c'),
            'updated' => date('c'),
        ];
        save_json('lifehacks', $hacks);
        flash('Lifehack gemt.');
        redirect('index.php?view=hack&id=' . urlencode($id));
    }

    if ($action === 'delete_hack') {
        unset($hacks[$_POST['id'] ?? '']);
        save_json('lifehacks', $hacks);
        flash('Lifehack slettet.');
        redirect('index.php?view=hacks');
    }

    if ($action === 'toggle_pin' && isset($hacks[$_POST['id'] ?? ''])) {
        $hacks[$_POST['id']]['pinned'] = empty($hacks[$_POST['id']]['pinned']);
        save_json('lifehacks', $hacks);
        redirect($_POST['back'] ?? './');
    }

    if ($action === 'save_categories') {
        $new = array_values(array_unique(array_filter(array_map('trim', explode("\n", (string)($_POST['categories'] ?? ''))))));
        save_json('categories', $new);
        flash('Kategorier gemt.');
        redirect('index.php?view=settings');
    }

    if ($action === 'change_password') {
        if (!password_verify((string)($_POST['current'] ?? ''), $auth['hash'])) {
            flash('Nuværende adgangskode er forkert.');
        } elseif (mb_strlen((string)($_POST['new'] ?? '')) < 10) {
            flash('Ny adgangskode skal være mindst 10 tegn.');
        } elseif ($_POST['new'] !== ($_POST['new2'] ?? '')) {
            flash('De nye adgangskoder er ikke ens.');
        } else {
            save_json('auth', ['hash' => password_hash($_POST['new'], PASSWORD_DEFAULT)]);
            flash('Adgangskode ændret.');
        }
        redirect('index.php?view=settings');
    }

    if ($action === 'save_profile') {
        $p = get_profile();
        foreach (['name', 'title', 'location', 'email', 'linkedin', 'about', 'now'] as $f) {
            $p[$f] = trim((string)($_POST[$f] ?? ''));
        }
        $p['skills'] = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['skills'] ?? '')))));
        $p['languages'] = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['languages'] ?? '')))));
        $p['education'] = [];
        foreach (preg_split('/\R/', (string)($_POST['education'] ?? '')) as $line) {
            $parts = array_map('trim', explode('|', $line));
            if ($parts[0] === '') {
                continue;
            }
            $p['education'][] = ['title' => $parts[0], 'school' => $parts[1] ?? '', 'period' => $parts[2] ?? ''];
        }
        $exp = [];
        foreach ($_POST['exp_role'] ?? [] as $i => $role) {
            $row = [
                'role' => trim((string)$role),
                'company' => trim((string)($_POST['exp_company'][$i] ?? '')),
                'period' => trim((string)($_POST['exp_period'][$i] ?? '')),
                'text' => trim((string)($_POST['exp_text'][$i] ?? '')),
            ];
            if ($row['role'] !== '' || $row['company'] !== '') {
                $exp[] = $row;
            }
        }
        $p['experience'] = $exp;

        $f = $_FILES['photo'] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK) {
            $info = @getimagesize($f['tmp_name']);
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
            if (!$ext || $f['size'] > 8 * 1024 * 1024) {
                flash('Billedet skal være JPG, PNG eller WebP og under 8 MB.');
                redirect('index.php?view=profile');
            }
            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }
            $name = 'photo-' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name);
            if ($p['photo'] && is_file(UPLOAD_DIR . '/' . basename($p['photo']))) {
                unlink(UPLOAD_DIR . '/' . basename($p['photo']));
            }
            $p['photo'] = $name;
        }
        if (!empty($_POST['remove_photo']) && $p['photo']) {
            @unlink(UPLOAD_DIR . '/' . basename($p['photo']));
            $p['photo'] = '';
        }

        save_json('profile', $p);
        flash('Profil gemt – den er live på forsiden.');
        redirect('index.php?view=profile');
    }
}

$view = $_GET['view'] ?? 'dashboard';
$q = trim((string)($_GET['q'] ?? ''));
$cat = $_GET['cat'] ?? '';

uasort($hacks, fn($a, $b) => [$b['pinned'], $b['updated']] <=> [$a['pinned'], $a['updated']]);

$counts = [];
foreach ($hacks as $h) {
    $counts[$h['category']] = ($counts[$h['category']] ?? 0) + 1;
}

function filter_hacks(array $hacks, string $cat, string $q): array
{
    return array_filter($hacks, function ($h) use ($cat, $q) {
        if ($cat !== '' && $h['category'] !== $cat) {
            return false;
        }
        if ($q !== '') {
            $hay = mb_strtolower($h['title'] . ' ' . $h['content'] . ' ' . implode(' ', $h['tags']) . ' ' . $h['category']);
            return str_contains($hay, mb_strtolower($q));
        }
        return true;
    });
}

function hack_card(array $h, string $back): string
{
    $excerpt = mb_strimwidth(preg_replace('/\s+/', ' ', $h['content']), 0, 140, '…');
    ob_start(); ?>
    <article class="hack-card<?= $h['pinned'] ? ' pinned' : '' ?>">
      <a class="cover" href="./?view=hack&id=<?= e($h['id']) ?>"></a>
      <div class="meta">
        <?php if ($h['category']): ?><span class="tag"><?= e($h['category']) ?></span><?php endif; ?>
        <form method="post" class="pin-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle_pin">
          <input type="hidden" name="id" value="<?= e($h['id']) ?>">
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <button class="pin" title="<?= $h['pinned'] ? 'Fjern fastgørelse' : 'Fastgør' ?>"><?= $h['pinned'] ? '★' : '☆' ?></button>
        </form>
      </div>
      <h3><?= e($h['title']) ?></h3>
      <?php if ($excerpt): ?><p><?= e($excerpt) ?></p><?php endif; ?>
      <time><?= e(dk_date($h['updated'])) ?></time>
    </article>
    <?php return ob_get_clean();
}

$back = $_SERVER['REQUEST_URI'] ?? './';
$total = count($hacks);
$pinned = array_filter($hacks, fn($h) => $h['pinned']);
$profile = get_profile();

$titles = [
    'dashboard' => 'Oversigt',
    'hacks' => $cat !== '' ? $cat : 'Alle lifehacks',
    'hack' => 'Lifehack',
    'edit' => isset($_GET['id']) ? 'Rediger lifehack' : 'Ny lifehack',
    'profile' => 'Profil & CV',
    'settings' => 'Indstillinger',
];
$pageTitle = $titles[$view] ?? 'Oversigt';
?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050506">
<title><?= e($pageTitle) ?> · Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<input type="checkbox" id="nav-toggle" class="nav-toggle">
<div class="layout">
  <aside class="sidebar">
    <div class="brand">
      <span class="avatar"><?php if ($profile['photo']): ?><img src="../uploads/<?= e($profile['photo']) ?>" alt=""><?php else: ?><?= e(mb_substr($profile['name'], 0, 1)) ?><?php endif; ?></span>
      <div><strong><?= e(explode(' ', $profile['name'])[0]) ?></strong><small>Admin</small></div>
    </div>

    <a href="./?view=edit" class="btn block">+ Ny lifehack</a>

    <nav>
      <a href="./" class="<?= $view === 'dashboard' ? 'active' : '' ?>">Oversigt</a>
      <a href="./?view=hacks" class="<?= $view === 'hacks' && $cat === '' ? 'active' : '' ?>">Alle lifehacks <span class="count"><?= $total ?></span></a>
    </nav>

    <p class="nav-label">Kategorier</p>
    <nav>
      <?php foreach ($categories as $c): ?>
        <a href="./?view=hacks&cat=<?= urlencode($c) ?>" class="<?= $view === 'hacks' && $cat === $c ? 'active' : '' ?>"><?= e($c) ?> <span class="count"><?= $counts[$c] ?? 0 ?></span></a>
      <?php endforeach; ?>
    </nav>

    <p class="nav-label">Side</p>
    <nav>
      <a href="./?view=profile" class="<?= $view === 'profile' ? 'active' : '' ?>">Profil & CV</a>
      <a href="./?view=settings" class="<?= $view === 'settings' ? 'active' : '' ?>">Indstillinger</a>
      <a href="../" target="_blank">Se siden ↗</a>
    </nav>

    <form method="post" class="logout">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="logout">
      <button class="link">Log ud</button>
    </form>
  </aside>
  <label for="nav-toggle" class="scrim"></label>

  <main class="content">
    <header class="topbar">
      <label for="nav-toggle" class="burger" aria-label="Menu"><span></span></label>
      <h1><?= e($pageTitle) ?></h1>
      <form class="search" action="./">
        <input type="hidden" name="view" value="hacks">
        <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= e($cat) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Søg i lifehacks…">
      </form>
    </header>

    <?php if ($flash): ?><p class="alert"><?= e($flash) ?></p><?php endif; ?>

    <?php if ($view === 'dashboard'): ?>
      <div class="stats">
        <div class="stat"><span><?= $total ?></span>lifehacks</div>
        <div class="stat"><span><?= count($pinned) ?></span>fastgjort</div>
        <div class="stat"><span><?= count(array_filter($counts)) ?></span>kategorier i brug</div>
      </div>

      <?php if ($pinned): ?>
        <h2 class="section-title">Fastgjort</h2>
        <div class="grid"><?php foreach (array_slice($pinned, 0, 6) as $h) echo hack_card($h, $back); ?></div>
      <?php endif; ?>

      <h2 class="section-title">Senest opdateret</h2>
      <?php $recent = $hacks; uasort($recent, fn($a, $b) => $b['updated'] <=> $a['updated']); ?>
      <?php if ($recent): ?>
        <div class="grid"><?php foreach (array_slice($recent, 0, 6) as $h) echo hack_card($h, $back); ?></div>
      <?php else: ?>
        <div class="empty">
          <p>Du har ingen lifehacks endnu.</p>
          <a href="./?view=edit" class="btn">Skriv din første</a>
        </div>
      <?php endif; ?>

    <?php elseif ($view === 'hacks'): ?>
      <?php $list = filter_hacks($hacks, $cat, $q); ?>
      <?php if ($q !== ''): ?><p class="muted"><?= count($list) ?> resultat<?= count($list) === 1 ? '' : 'er' ?> for "<?= e($q) ?>" <a href="./?view=hacks<?= $cat !== '' ? '&cat=' . urlencode($cat) : '' ?>">Ryd</a></p><?php endif; ?>
      <?php if ($list): ?>
        <div class="grid"><?php foreach ($list as $h) echo hack_card($h, $back); ?></div>
      <?php else: ?>
        <div class="empty">
          <p>Ingen lifehacks her endnu.</p>
          <a href="./?view=edit<?= $cat !== '' ? '&cat=' . urlencode($cat) : '' ?>" class="btn">Tilføj en</a>
        </div>
      <?php endif; ?>

    <?php elseif ($view === 'hack' && isset($hacks[$_GET['id'] ?? ''])): ?>
      <?php $h = $hacks[$_GET['id']]; ?>
      <article class="panel read">
        <div class="meta">
          <?php if ($h['category']): ?><a class="tag" href="./?view=hacks&cat=<?= urlencode($h['category']) ?>"><?= e($h['category']) ?></a><?php endif; ?>
          <?php if ($h['pinned']): ?><span class="tag star">★ Fastgjort</span><?php endif; ?>
        </div>
        <h2><?= e($h['title']) ?></h2>
        <div class="body"><?= linkify($h['content']) ?></div>
        <?php if ($h['link']): ?><p><a href="<?= e($h['link']) ?>" target="_blank" rel="noopener">Åbn link ↗</a></p><?php endif; ?>
        <?php if ($h['tags']): ?>
          <ul class="tags"><?php foreach ($h['tags'] as $t): ?><li><a href="./?view=hacks&q=<?= urlencode($t) ?>">#<?= e($t) ?></a></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <p class="muted small">Oprettet <?= e(dk_date($h['created'])) ?> · opdateret <?= e(dk_date($h['updated'])) ?></p>
        <div class="actions">
          <a href="./?view=edit&id=<?= e($h['id']) ?>" class="btn">Rediger</a>
          <details class="confirm">
            <summary class="btn ghost danger">Slet</summary>
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_hack">
              <input type="hidden" name="id" value="<?= e($h['id']) ?>">
              <span>Sikker?</span>
              <button class="btn danger">Ja, slet</button>
            </form>
          </details>
        </div>
      </article>

    <?php elseif ($view === 'edit'): ?>
      <?php $h = $hacks[$_GET['id'] ?? ''] ?? ['id' => '', 'title' => '', 'category' => $_GET['cat'] ?? '', 'content' => '', 'link' => '', 'tags' => [], 'pinned' => false]; ?>
      <form method="post" class="panel form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_hack">
        <input type="hidden" name="id" value="<?= e($h['id']) ?>">
        <label>Titel<input name="title" value="<?= e($h['title']) ?>" required autofocus></label>
        <div class="two">
          <label>Kategori
            <input name="category" value="<?= e($h['category']) ?>" list="cats" placeholder="Vælg eller skriv ny">
            <datalist id="cats"><?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
          </label>
          <label>Tags <small>(adskilt med komma)</small><input name="tags" value="<?= e(implode(', ', $h['tags'])) ?>"></label>
        </div>
        <label>Indhold<textarea name="content" rows="12"><?= e($h['content']) ?></textarea></label>
        <label>Link <small>(valgfrit)</small><input type="url" name="link" value="<?= e($h['link']) ?>" placeholder="https://"></label>
        <label class="check"><input type="checkbox" name="pinned" value="1" <?= $h['pinned'] ? 'checked' : '' ?>> Fastgør på oversigten</label>
        <div class="actions">
          <button class="btn">Gem</button>
          <a href="<?= $h['id'] ? './?view=hack&id=' . e($h['id']) : './' ?>" class="btn ghost">Annuller</a>
        </div>
      </form>

    <?php elseif ($view === 'profile'): ?>
      <form method="post" enctype="multipart/form-data" class="panel form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_profile">
        <div class="photo-row">
          <span class="avatar big"><?php if ($profile['photo']): ?><img src="../uploads/<?= e($profile['photo']) ?>" alt=""><?php else: ?><?= e(mb_substr($profile['name'], 0, 1)) ?><?php endif; ?></span>
          <div>
            <label>Profilbillede<input type="file" name="photo" accept="image/jpeg,image/png,image/webp"></label>
            <?php if ($profile['photo']): ?><label class="check"><input type="checkbox" name="remove_photo" value="1"> Fjern billede</label><?php endif; ?>
          </div>
        </div>
        <div class="two">
          <label>Navn<input name="name" value="<?= e($profile['name']) ?>"></label>
          <label>Titel<input name="title" value="<?= e($profile['title']) ?>"></label>
          <label>By<input name="location" value="<?= e($profile['location']) ?>"></label>
          <label>Mail<input type="email" name="email" value="<?= e($profile['email']) ?>"></label>
        </div>
        <label>LinkedIn-URL<input type="url" name="linkedin" value="<?= e($profile['linkedin']) ?>" placeholder="https://www.linkedin.com/in/…"></label>
        <label>Lige nu<textarea name="now" rows="3"><?= e($profile['now']) ?></textarea></label>
        <label>Om mig<textarea name="about" rows="4"><?= e($profile['about']) ?></textarea></label>
        <label>Kompetencer <small>(adskilt med komma)</small><input name="skills" value="<?= e(implode(', ', $profile['skills'])) ?>"></label>
        <label>Sprog <small>(adskilt med komma)</small><input name="languages" value="<?= e(implode(', ', $profile['languages'])) ?>"></label>
        <label>Uddannelse <small>(én pr. linje: Titel | Sted | Periode)</small><textarea name="education" rows="4"><?= e(implode("\n", array_map(fn($x) => $x['title'] . ' | ' . $x['school'] . ' | ' . $x['period'], $profile['education']))) ?></textarea></label>

        <h2 class="section-title">Erfaring</h2>
        <p class="muted small">Tomme rækker ignoreres. Gem for at få flere tomme rækker.</p>
        <?php foreach (array_merge($profile['experience'], array_fill(0, 2, ['role' => '', 'company' => '', 'period' => '', 'text' => ''])) as $x): ?>
          <fieldset class="exp">
            <div class="three">
              <label>Rolle<input name="exp_role[]" value="<?= e($x['role']) ?>"></label>
              <label>Virksomhed<input name="exp_company[]" value="<?= e($x['company']) ?>"></label>
              <label>Periode<input name="exp_period[]" value="<?= e($x['period']) ?>" placeholder="2020 – nu"></label>
            </div>
            <label>Beskrivelse<textarea name="exp_text[]" rows="2"><?= e($x['text']) ?></textarea></label>
          </fieldset>
        <?php endforeach; ?>
        <div class="actions"><button class="btn">Gem profil</button></div>
      </form>

    <?php elseif ($view === 'settings'): ?>
      <div class="cols">
        <form method="post" class="panel form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_categories">
          <h2 class="section-title">Kategorier</h2>
          <label>Én pr. linje – rækkefølgen styrer menuen<textarea name="categories" rows="8"><?= e(implode("\n", $categories)) ?></textarea></label>
          <div class="actions"><button class="btn">Gem kategorier</button></div>
        </form>
        <form method="post" class="panel form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">
          <h2 class="section-title">Skift adgangskode</h2>
          <label>Nuværende<input type="password" name="current" required autocomplete="current-password"></label>
          <label>Ny<input type="password" name="new" required autocomplete="new-password"></label>
          <label>Gentag ny<input type="password" name="new2" required autocomplete="new-password"></label>
          <div class="actions"><button class="btn">Skift</button></div>
        </form>
      </div>

    <?php else: ?>
      <div class="empty"><p>Siden findes ikke.</p><a href="./" class="btn">Til oversigten</a></div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
