<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/budget.php';
require dirname(__DIR__) . '/inc/calendar.php';

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

function remember_cookie(string $value, int $expires): void
{
    setcookie('ll_remember', $value, [
        'expires' => $expires,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

function remember_issue(array $auth): void
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + 60 * 86400;
    $tokens = array_filter($auth['tokens'] ?? [], fn($t) => $t > time());
    $tokens[hash('sha256', $token)] = $expires;
    $auth['tokens'] = $tokens;
    save_json('auth', $auth);
    remember_cookie($token, $expires);
}

function remember_check(array $auth): bool
{
    $token = (string)($_COOKIE['ll_remember'] ?? '');
    if ($token === '') {
        return false;
    }
    $exp = $auth['tokens'][hash('sha256', $token)] ?? 0;
    if ($exp > time()) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        return true;
    }
    remember_cookie('', time() - 3600);
    return false;
}

function remember_clear(array $auth): void
{
    $token = (string)($_COOKIE['ll_remember'] ?? '');
    if ($token !== '' && isset($auth['tokens'][hash('sha256', $token)])) {
        unset($auth['tokens'][hash('sha256', $token)]);
        save_json('auth', $auth);
    }
    remember_cookie('', time() - 3600);
}

function app_head(): string
{
    return '<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Emil">
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="../assets/icon-180.png">
<link rel="icon" type="image/png" href="../assets/icon-192.png">';
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
        $auth = ['hash' => password_hash($pw, PASSWORD_DEFAULT)];
        save_json('auth', $auth);
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        remember_issue($auth);
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
        if (!empty($_POST['remember'])) {
            remember_issue($auth);
        }
        redirect('index.php');
    } else {
        register_fail();
        sleep(1);
        $error = 'Forkert adgangskode.';
    }
}

if (!is_logged_in() && !$needsSetup) {
    remember_check($auth);
}

if (!is_logged_in()) {
    ?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050506">
<?= app_head() ?>
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
  <?php if (!$needsSetup): ?>
    <label class="check"><input type="checkbox" name="remember" value="1" checked> Forbliv logget ind på denne enhed</label>
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
        remember_clear($auth);
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
            $auth = ['hash' => password_hash($_POST['new'], PASSWORD_DEFAULT)];
            save_json('auth', $auth);
            remember_issue($auth);
            flash('Adgangskode ændret. Andre enheder er logget ud.');
        }
        redirect('index.php?view=settings');
    }

    if ($action === 'save_budget') {
        save_json('budget', budget_from_post($_POST));
        flash('Budget gemt.');
        redirect('index.php?view=budget' . (!empty($_POST['edit']) ? '&edit=1' : ''));
    }

    if ($action === 'save_calendars') {
        $cals = [];
        foreach ($_POST['cal_url'] ?? [] as $i => $url) {
            $url = trim((string)$url);
            if ($url === '') {
                continue;
            }
            $cals[] = ['name' => trim((string)($_POST['cal_name'][$i] ?? '')) ?: 'Kalender', 'url' => $url];
        }
        save_json('calendars', $cals);
        save_json('cal-cache', []);
        $res = cal_events(30, true);
        flash($res['errors'] ? 'Gemt, men disse kunne ikke hentes: ' . implode(', ', $res['errors']) . '. Tjek linket.' : 'Kalendere gemt.');
        redirect('index.php?view=settings');
    }

    if ($action === 'api_token') {
        $token = bin2hex(random_bytes(20));
        save_json('api', ['hash' => hash('sha256', $token), 'created' => date('c')]);
        $_SESSION['new_token'] = $token;
        flash('Ny nøgle lavet. Kopiér den nu – den vises kun én gang.');
        redirect('index.php?view=settings#siri');
    }

    if ($action === 'api_revoke') {
        save_json('api', []);
        flash('Nøglen er slået fra. Dine genveje virker ikke længere.');
        redirect('index.php?view=settings#siri');
    }

    if ($action === 'refresh_calendar') {
        cal_events(30, true);
        redirect('index.php?view=calendar');
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
    'budget' => 'Budget',
    'calendar' => 'Kalender',
];

$budget = load_json('budget', ['income' => [], 'expenses' => []]);
$sum = budget_summary($budget);
$cal = in_array($view, ['dashboard', 'calendar'], true) ? cal_events($view === 'calendar' ? 30 : 7) : ['events' => [], 'errors' => []];
$hasCal = (bool)cal_sources();

function upcoming_payments(array $budget, int $days): array
{
    $out = [];
    $today = strtotime('today');
    foreach ($budget['expenses'] ?? [] as $x) {
        if (empty($x['day']) || ($x['freq'] ?? 'month') !== 'month') {
            continue;
        }
        for ($m = 0; $m <= 1; $m++) {
            $base = strtotime(date('Y-m-01', strtotime("+$m month", strtotime(date('Y-m-01', $today)))));
            $dim = (int)date('t', $base);
            $ts = $base + (min($x['day'], $dim) - 1) * 86400;
            if ($ts >= $today && $ts < $today + $days * 86400) {
                $out[] = ['ts' => $ts, 'name' => $x['name'], 'amount' => $x['amount']];
            }
        }
    }
    usort($out, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $out;
}
$pageTitle = $titles[$view] ?? 'Oversigt';
?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050506">
<?= app_head() ?>
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

    <p class="nav-label">Værktøjer</p>
    <nav>
      <a href="./?view=budget" class="<?= $view === 'budget' ? 'active' : '' ?>">Budget <span class="count"><?= $sum['income'] > 0 ? e(number_format($sum['left'], 0, ',', '.')) : '' ?></span></a>
      <a href="./?view=calendar" class="<?= $view === 'calendar' ? 'active' : '' ?>">Kalender</a>
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
      <?php $pay = upcoming_payments($budget, 7); ?>
      <div class="stats">
        <a class="stat" href="./?view=hacks"><span><?= $total ?></span>lifehacks</a>
        <?php if ($sum['income'] > 0): ?>
          <a class="stat" href="./?view=budget"><span class="<?= $sum['left'] < 0 ? 'neg' : '' ?>"><?= e(number_format($sum['left'], 0, ',', '.')) ?></span>kr. tilbage pr. md.</a>
        <?php else: ?>
          <a class="stat" href="./?view=budget"><span>–</span>opsæt budget</a>
        <?php endif; ?>
        <a class="stat" href="./?view=calendar"><span><?= count($cal['events']) ?></span>aftaler næste 7 dage</a>
      </div>

      <?php if ($cal['events'] || $pay): ?>
        <h2 class="section-title">Kommende</h2>
        <div class="agenda panel compact">
          <?php $last = null; $items = []; ?>
          <?php foreach ($cal['events'] as $ev) $items[] = ['ts' => $ev['start'], 'kind' => 'ev', 'ev' => $ev]; ?>
          <?php foreach ($pay as $pp) $items[] = ['ts' => $pp['ts'], 'kind' => 'pay', 'pay' => $pp]; ?>
          <?php usort($items, fn($a, $b) => $a['ts'] <=> $b['ts']); ?>
          <?php foreach (array_slice($items, 0, 8) as $it): ?>
            <?php $d = date('Y-m-d', $it['ts']); if ($d !== $last): $last = $d; ?><p class="day"><?= e(day_label($it['ts'])) ?></p><?php endif; ?>
            <?php if ($it['kind'] === 'ev'): ?>
              <div class="ev c<?= $it['ev']['color'] ?>"><time><?= e(event_time($it['ev'])) ?></time><span><?= e($it['ev']['title']) ?><?php if ($it['ev']['location']): ?><small><?= e($it['ev']['location']) ?></small><?php endif; ?></span></div>
            <?php else: ?>
              <div class="ev pay"><time>Betaling</time><span><?= e($it['pay']['name']) ?><small><?= e(kr($it['pay']['amount'])) ?></small></span></div>
            <?php endif; ?>
          <?php endforeach; ?>
          <a href="./?view=calendar" class="more">Se kalender →</a>
        </div>
      <?php elseif (!$hasCal): ?>
        <div class="hint panel compact"><p>Forbind din iPhone-kalender for at se dine aftaler her.</p><a href="./?view=settings#kalender" class="btn ghost">Forbind kalender</a></div>
      <?php endif; ?>

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

      <?php $cals = load_json('calendars'); ?>
      <form method="post" class="panel form" id="kalender">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_calendars">
        <h2 class="section-title">iPhone-kalender</h2>
        <ol class="steps muted small">
          <li>Åbn Kalender-appen på din iPhone og tryk på <strong>Kalendere</strong> nederst.</li>
          <li>Tryk på <strong>ⓘ</strong> ud for den kalender, du vil vise, og slå <strong>Offentlig kalender</strong> til.</li>
          <li>Tryk <strong>Del link…</strong> → <strong>Kopiér</strong>, og sæt linket ind herunder. Gentag for flere kalendere.</li>
        </ol>
        <p class="muted small">Linket virker som en hemmelig adresse: alle der kender det kan se kalenderen, så del det ikke andre steder. Det gemmes kun på din server.</p>
        <?php foreach (array_merge($cals, array_fill(0, 2, ['name' => '', 'url' => ''])) as $c): ?>
          <div class="row cal">
            <input name="cal_name[]" value="<?= e($c['name']) ?>" placeholder="Navn, fx Privat" aria-label="Navn">
            <input name="cal_url[]" value="<?= e($c['url']) ?>" placeholder="webcal://p…-caldav.icloud.com/published/…" aria-label="Link">
          </div>
        <?php endforeach; ?>
        <div class="actions"><button class="btn">Gem kalendere</button></div>
      </form>

      <?php $api = load_json('api'); $newToken = $_SESSION['new_token'] ?? null; unset($_SESSION['new_token']); ?>
      <?php $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'langkildelarsen.dk') . '/api/'; ?>
      <div class="panel siri" id="siri">
        <h2 class="section-title">Siri & Genveje</h2>
        <?php if ($newToken): ?>
          <label>Din nøgle – kopiér den til dine genveje nu<input value="<?= e($newToken) ?>" readonly onfocus="this.select()"></label>
        <?php endif; ?>
        <div class="actions">
          <?php if (!empty($api['hash'])): ?>
            <span class="muted small">Nøgle aktiv siden <?= e(dk_date($api['created'])) ?>.</span>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="api_token"><button class="btn ghost">Lav ny nøgle</button></form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="api_revoke"><button class="btn ghost danger">Slå fra</button></form>
          <?php else: ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="api_token"><button class="btn">Lav nøgle til Siri</button></form>
          <?php endif; ?>
        </div>

        <h3>"Hey Siri, ny lifehack"</h3>
        <ol class="steps muted small">
          <li>Åbn <strong>Genveje</strong> på iPhone → <strong>+</strong> → giv den navnet <strong>Ny lifehack</strong>.</li>
          <li>Tilføj handlingen <strong>Diktér tekst</strong> (sprog: Dansk).</li>
          <li>Tilføj <strong>Hent indhold af URL</strong> med adressen <code><?= e($base) ?></code>. Tryk på pilen: Metode <strong>POST</strong>, Anmodningstekst <strong>Formular</strong>, og tilføj tre felter: <code>token</code> = din nøgle, <code>action</code> = <code>lifehack</code>, <code>text</code> = <strong>Dikteret tekst</strong>.</li>
          <li>Tilføj <strong>Vis resultat</strong>, så Siri bekræfter, hvad der blev gemt.</li>
        </ol>
        <p class="muted small">Start med et kategorinavn for at sortere den, fx "Tech, tryk to gange på mellemrum for at…". Ellers lander den uden kategori med tagget #siri.</p>

        <h3>"Hey Siri, min dag" og "Hey Siri, mit budget"</h3>
        <ol class="steps muted small">
          <li>Lav en ny genvej med navnet <strong>Min dag</strong>.</li>
          <li>Tilføj <strong>Hent indhold af URL</strong> som ovenfor, men kun med felterne <code>token</code> og <code>action</code> = <code>agenda</code>.</li>
          <li>Tilføj <strong>Læs tekst op</strong>. Lav én magen til med navnet <strong>Mit budget</strong> og <code>action</code> = <code>budget</code>.</li>
        </ol>
      </div>

      <div class="panel">
        <h2 class="section-title">Admin som app på iPhone</h2>
        <ol class="steps muted small">
          <li>Åbn <strong>langkildelarsen.dk/admin</strong> i Safari på din iPhone og log ind.</li>
          <li>Tryk på <strong>Del</strong>-ikonet (firkant med pil op).</li>
          <li>Vælg <strong>Føj til hjemmeskærm</strong> → <strong>Tilføj</strong>.</li>
        </ol>
        <p class="muted small">Så åbner dashboardet i fuld skærm som en app, og du forbliver logget ind i 60 dage.</p>
      </div>

    <?php elseif ($view === 'budget'): ?>
      <?php $editing = !empty($_GET['edit']) || (!$budget['income'] && !$budget['expenses']); ?>
      <?php if (!$editing): ?>
        <div class="stats four">
          <div class="stat"><span><?= e(number_format($sum['income'], 0, ',', '.')) ?></span>indtægt pr. md.</div>
          <div class="stat"><span><?= e(number_format($sum['expenses'], 0, ',', '.')) ?></span>udgifter pr. md.</div>
          <div class="stat"><span class="<?= $sum['left'] < 0 ? 'neg' : '' ?>"><?= e(number_format($sum['left'], 0, ',', '.')) ?></span>tilbage pr. md.</div>
          <div class="stat"><span><?= e(number_format($sum['saving_rate'], 0, ',', '.')) ?> %</span>opsparing</div>
        </div>

        <div class="cols wide">
          <section class="panel">
            <h2 class="section-title">Fordeling · 50/30/20</h2>
            <?php $targets = ['need' => 50, 'want' => 30, 'saving' => 20]; ?>
            <?php foreach ($targets as $t => $goal): ?>
              <?php $pct = $sum['income'] > 0 ? $sum['by_type'][$t] / $sum['income'] * 100 : 0; ?>
              <div class="bar-row">
                <div class="bar-head"><span><?= e(BUDGET_TYPES[$t]) ?></span><span><?= e(number_format($pct, 0, ',', '.')) ?> % <small>mål <?= $goal ?> %</small></span></div>
                <div class="bar"><i style="width: <?= min(100, round($pct, 1)) ?>%"></i><b style="left: <?= $goal ?>%"></b></div>
                <small class="muted"><?= e(kr($sum['by_type'][$t])) ?></small>
              </div>
            <?php endforeach; ?>
            <?php if ($sum['left'] > 0): ?>
              <p class="muted small">Ufordelt: <?= e(kr($sum['left'])) ?> pr. måned.</p>
            <?php endif; ?>
          </section>

          <section class="panel">
            <h2 class="section-title">Udgifter pr. kategori</h2>
            <?php $max = max($sum['by_cat'] ?: [1]); ?>
            <?php foreach ($sum['by_cat'] as $c => $v): ?>
              <div class="bar-row">
                <div class="bar-head"><span><?= e($c) ?></span><span><?= e(kr($v)) ?></span></div>
                <div class="bar thin"><i style="width: <?= round($v / $max * 100, 1) ?>%"></i></div>
              </div>
            <?php endforeach; ?>
          </section>
        </div>

        <section class="panel table-panel">
          <div class="panel-head">
            <h2 class="section-title">Poster</h2>
            <a href="./?view=budget&edit=1" class="btn">Rediger budget</a>
          </div>
          <table class="ledger">
            <thead><tr><th>Post</th><th>Kategori</th><th>Type</th><th class="num">Beløb</th><th class="num">Pr. md.</th></tr></thead>
            <tbody>
              <?php foreach ($budget['income'] as $x): ?>
                <tr class="in"><td><?= e($x['name']) ?></td><td>Indtægt</td><td>–</td><td class="num"><?= e(kr($x['amount'])) ?> <small><?= e(mb_strtolower(BUDGET_FREQ[$x['freq']][0])) ?></small></td><td class="num">+<?= e(kr(monthly($x))) ?></td></tr>
              <?php endforeach; ?>
              <?php foreach ($budget['expenses'] as $x): ?>
                <tr><td><?= e($x['name']) ?><?php if (!empty($x['day'])): ?> <small>d. <?= (int)$x['day'] ?>.</small><?php endif; ?></td><td><?= e($x['category'] ?: 'Andet') ?></td><td><?= e(BUDGET_TYPES[$x['type']]) ?></td><td class="num"><?= e(kr($x['amount'])) ?> <small><?= e(mb_strtolower(BUDGET_FREQ[$x['freq']][0])) ?></small></td><td class="num">−<?= e(kr(monthly($x))) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </section>
      <?php else: ?>
        <form method="post" class="panel form budget-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_budget">
          <p class="muted small">Skriv beløb som de trækkes – fx en årlig forsikring som årligt beløb – så regner siden det om til pr. måned. Tomme rækker ignoreres; gem for at få flere.</p>

          <h2 class="section-title">Indtægter</h2>
          <div class="rows">
            <div class="row head in"><span>Navn</span><span>Beløb</span><span>Hvor ofte</span></div>
            <?php foreach (array_merge($budget['income'], array_fill(0, 2, ['name' => '', 'amount' => 0, 'freq' => 'month'])) as $x): ?>
              <div class="row in">
                <input name="in_name[]" value="<?= e($x['name']) ?>" placeholder="Løn efter skat" aria-label="Navn">
                <input name="in_amount[]" value="<?= e(amount_value($x['amount'])) ?>" inputmode="decimal" placeholder="0" aria-label="Beløb">
                <select name="in_freq[]" aria-label="Hvor ofte"><?php foreach (BUDGET_FREQ as $k => $f): ?><option value="<?= $k ?>" <?= $x['freq'] === $k ? 'selected' : '' ?>><?= e($f[0]) ?></option><?php endforeach; ?></select>
              </div>
            <?php endforeach; ?>
          </div>

          <h2 class="section-title">Udgifter</h2>
          <datalist id="bcats"><?php foreach (BUDGET_CATEGORIES as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
          <div class="rows">
            <div class="row head ex"><span>Navn</span><span>Kategori</span><span>Beløb</span><span>Hvor ofte</span><span>Type</span><span>Dag</span></div>
            <?php foreach (array_merge($budget['expenses'], array_fill(0, 4, ['name' => '', 'category' => '', 'amount' => 0, 'freq' => 'month', 'type' => 'need', 'day' => 0])) as $x): ?>
              <div class="row ex">
                <input name="ex_name[]" value="<?= e($x['name']) ?>" placeholder="Husleje" aria-label="Navn">
                <input name="ex_cat[]" value="<?= e($x['category']) ?>" list="bcats" placeholder="Kategori" aria-label="Kategori">
                <input name="ex_amount[]" value="<?= e(amount_value($x['amount'])) ?>" inputmode="decimal" placeholder="0" aria-label="Beløb">
                <select name="ex_freq[]" aria-label="Hvor ofte"><?php foreach (BUDGET_FREQ as $k => $f): ?><option value="<?= $k ?>" <?= $x['freq'] === $k ? 'selected' : '' ?>><?= e($f[0]) ?></option><?php endforeach; ?></select>
                <select name="ex_type[]" aria-label="Type"><?php foreach (BUDGET_TYPES as $k => $t): ?><option value="<?= $k ?>" <?= $x['type'] === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
                <input name="ex_day[]" value="<?= $x['day'] ?: '' ?>" inputmode="numeric" placeholder="–" aria-label="Betalingsdag">
              </div>
            <?php endforeach; ?>
          </div>
          <p class="muted small">Dag = den dag i måneden beløbet trækkes. Så vises betalingen under "Kommende" på oversigten.</p>
          <div class="actions">
            <button class="btn">Gem budget</button>
            <button class="btn ghost" name="edit" value="1">Gem og fortsæt</button>
            <?php if ($budget['income'] || $budget['expenses']): ?><a href="./?view=budget" class="btn ghost">Annuller</a><?php endif; ?>
          </div>
        </form>
      <?php endif; ?>

    <?php elseif ($view === 'calendar'): ?>
      <?php if (!$hasCal): ?>
        <div class="empty">
          <p>Ingen kalender forbundet endnu.</p>
          <a href="./?view=settings#kalender" class="btn">Forbind din iPhone-kalender</a>
        </div>
      <?php else: ?>
        <?php if ($cal['errors']): ?><p class="alert error">Kunne ikke hente: <?= e(implode(', ', $cal['errors'])) ?></p><?php endif; ?>
        <div class="panel-head">
          <p class="muted small">Næste 30 dage · opdateres hvert kvarter</p>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh_calendar"><button class="btn ghost">Opdater nu</button></form>
        </div>
        <?php if (!$cal['events']): ?>
          <div class="empty"><p>Ingen aftaler de næste 30 dage.</p></div>
        <?php else: ?>
          <div class="agenda panel">
            <?php $last = null; foreach ($cal['events'] as $ev): ?>
              <?php $d = date('Y-m-d', $ev['start']); if ($d !== $last): $last = $d; ?><p class="day"><?= e(day_label($ev['start'])) ?></p><?php endif; ?>
              <div class="ev c<?= $ev['color'] ?>"><time><?= e(event_time($ev)) ?></time><span><?= e($ev['title']) ?><small><?= e(trim($ev['location'] . ($ev['location'] && count(cal_sources()) > 1 ? ' · ' : '') . (count(cal_sources()) > 1 ? $ev['calendar'] : ''))) ?></small></span></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>

    <?php else: ?>
      <div class="empty"><p>Siden findes ikke.</p><a href="./" class="btn">Til oversigten</a></div>
    <?php endif; ?>
  </main>
</div>
</body>
</html>
