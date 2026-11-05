<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/budget.php';
require dirname(__DIR__) . '/inc/calendar.php';
require dirname(__DIR__) . '/inc/media.php';
require dirname(__DIR__) . '/inc/messages.php';
require dirname(__DIR__) . '/inc/claude.php';
require dirname(__DIR__) . '/inc/weather.php';
require dirname(__DIR__) . '/inc/training.php';
require dirname(__DIR__) . '/inc/reminders.php';
require dirname(__DIR__) . '/inc/watch.php';
require dirname(__DIR__) . '/inc/launch.php';

users_migrate();
if (!empty($_SESSION['admin']) && empty($_SESSION['uid'])) {
    foreach (users_all() as $u) {
        if ($u['role'] === 'owner') {
            $_SESSION['uid'] = $u['email'];
        }
    }
    unset($_SESSION['admin']);
}
$needsSetup = !users_all();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$error = null;
$invite = null;

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

function remember_issue(string $uid): void
{
    $users = users_all();
    if (!isset($users[$uid])) {
        return;
    }
    $token = bin2hex(random_bytes(32));
    $expires = time() + 60 * 86400;
    $tokens = array_filter($users[$uid]['tokens'] ?? [], fn($t) => $t > time());
    $tokens[hash('sha256', $token)] = $expires;
    $users[$uid]['tokens'] = $tokens;
    users_save($users);
    remember_cookie($uid . ':' . $token, $expires);
}

function remember_check(): bool
{
    $raw = (string)($_COOKIE['ll_remember'] ?? '');
    if ($raw === '') {
        return false;
    }
    $users = users_all();
    if (str_contains($raw, ':')) {
        [$uid, $token] = explode(':', $raw, 2);
    } else {
        $owner = array_values(array_filter($users, fn($u) => $u['role'] === 'owner'))[0] ?? null;
        [$uid, $token] = [$owner['email'] ?? '', $raw];
    }
    $exp = $users[$uid]['tokens'][hash('sha256', $token)] ?? 0;
    if ($exp > time()) {
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        return true;
    }
    remember_cookie('', time() - 3600);
    return false;
}

function remember_clear(): void
{
    $raw = (string)($_COOKIE['ll_remember'] ?? '');
    if ($raw !== '' && str_contains($raw, ':')) {
        [$uid, $token] = explode(':', $raw, 2);
        $users = users_all();
        if (isset($users[$uid]['tokens'][hash('sha256', $token)])) {
            unset($users[$uid]['tokens'][hash('sha256', $token)]);
            users_save($users);
        }
    }
    remember_cookie('', time() - 3600);
}

function app_head(): string
{
    return '<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Jarvis">
<link rel="manifest" href="manifest.webmanifest?v=ball2">
<link rel="apple-touch-icon" href="../assets/brand/dash-180.png?v=ball2">
<link rel="icon" type="image/png" sizes="96x96" href="../assets/brand/fav-96.png?v=ball2">
<link rel="icon" type="image/png" sizes="32x32" href="../assets/brand/fav-32.png?v=ball2">';
}

function default_categories(): array
{
    return ['Tech', 'Hjem', 'Mad', 'Økonomi', 'Produktivitet', 'Rejser'];
}

function greeting(): array
{
    $h = (int)date('G');
    $d = (int)date('N');
    $pools = [
        'nat' => ['Natteravn-mode aktiveret, {n}', 'Stadig vågen, {n}?', 'Sover hamstrene også ikke, {n}?', 'Kun os to og serveren nu, {n}', 'Månen er oppe. Det er du også, {n}', 'Sengen savner dig, {n}'],
        'morgen' => ['Godmorgen, {n}. Kaffen er (næsten) klar', 'Op med humøret, {n}', 'Systemer online. Er du, {n}?', 'Ny dag, nye bugs, {n}', 'Morgenmad først, {n}?', 'Rise and shine, {n}'],
        'formiddag' => ['Godt i gang, {n}?', 'Formiddagsform på max, {n}', 'Klar til at erobre verden, {n}?', 'Hvad skal vi knuse i dag, {n}?', 'Produktivitetsmodul indlæst, {n}'],
        'eftermiddag' => ['Hvad så, {n}?', 'Eftermiddagsdip? Ikke på min vagt, {n}', 'Halvvejs der, {n}', 'Tid til en kage, {n}?', 'Jarvis til tjeneste, {n}', 'Hej igen, {n}'],
        'aften' => ['God aften, {n}', 'Fyraften, {n}?', 'Sofa-mode klar, {n}', 'Velkommen hjem, {n}', 'Aftenvagten er bemandet, {n}', 'Netflix eller kode, {n}?'],
    ];
    $slot = $h < 5 ? 'nat' : ($h < 10 ? 'morgen' : ($h < 12 ? 'formiddag' : ($h < 18 ? 'eftermiddag' : ($h < 23 ? 'aften' : 'nat'))));
    $list = $pools[$slot];
    if ($d === 1 && $h >= 5 && $h < 12) {
        $list[] = 'Mandag igen, {n}. Vi klarer den';
    }
    if ($d === 5 && $h >= 12) {
        $list[] = 'Fredag, {n}! Weekenden kalder';
    }
    if ($d >= 6 && $h >= 5 && $h < 18) {
        $list[] = 'Weekend-mode, {n}';
    }
    return [$list[array_rand($list)], ''];
}



$action = $_POST['action'] ?? '';

$inviteToken = (string)($_GET['invite'] ?? $_POST['invite'] ?? '');
if ($inviteToken !== '') {
    foreach (users_all() as $u) {
        if (!empty($u['invite']) && hash_equals($u['invite']['hash'], hash('sha256', $inviteToken)) && $u['invite']['expires'] > time()) {
            $invite = $u;
        }
    }
    if (!$invite) {
        $error = 'Invitationslinket er ugyldigt eller udløbet. Bed om et nyt.';
    }
}

if ($action === 'accept_invite' && $invite) {
    verify_csrf();
    $pw = (string)($_POST['password'] ?? '');
    if (mb_strlen($pw) < 10) {
        $error = 'Adgangskoden skal være mindst 10 tegn.';
    } elseif ($pw !== ($_POST['password2'] ?? '')) {
        $error = 'De to adgangskoder er ikke ens.';
    } else {
        $users = users_all();
        $users[$invite['email']]['hash'] = password_hash($pw, PASSWORD_DEFAULT);
        unset($users[$invite['email']]['invite']);
        users_save($users);
        session_regenerate_id(true);
        $_SESSION['uid'] = $invite['email'];
        remember_issue($invite['email']);
        flash('Velkommen, ' . first_name($invite) . '! Dit dashboard er klar.');
        redirect('index.php');
    }
}

if ($action === 'setup' && $needsSetup) {
    verify_csrf();
    $pw = (string)($_POST['password'] ?? '');
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Skriv en gyldig mail.';
    } elseif (mb_strlen($pw) < 10) {
        $error = 'Adgangskoden skal være mindst 10 tegn.';
    } elseif ($pw !== ($_POST['password2'] ?? '')) {
        $error = 'De to adgangskoder er ikke ens.';
    } else {
        users_save([$email => ['email' => $email, 'name' => get_profile()['name'], 'role' => 'owner', 'hash' => password_hash($pw, PASSWORD_DEFAULT), 'tokens' => [], 'created' => date('c')]]);
        session_regenerate_id(true);
        $_SESSION['uid'] = $email;
        remember_issue($email);
        flash('Velkommen! Din adgangskode er gemt.');
        redirect('index.php');
    }
}

if ($action === 'login' && !$needsSetup) {
    verify_csrf();
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $user = users_all()[$email] ?? null;
    if ($mins = locked_out()) {
        $error = "For mange forsøg. Prøv igen om $mins min.";
    } elseif ($user && !empty($user['hash']) && password_verify((string)($_POST['password'] ?? ''), $user['hash'])) {
        clear_fails();
        session_regenerate_id(true);
        $_SESSION['uid'] = $email;
        if (!empty($_POST['remember'])) {
            remember_issue($email);
        }
        redirect('index.php');
    } else {
        register_fail();
        sleep(1);
        $error = 'Forkert mail eller adgangskode.';
    }
}

if (is_logged_in() && !current_user()) {
    unset($_SESSION['uid']);
}

if (!is_logged_in() && !$needsSetup && !$invite) {
    remember_check();
}

if (!is_logged_in()) {
    $mode = $invite ? 'invite' : ($needsSetup ? 'setup' : 'login');
    ?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050505">
<?= app_head() ?>
<title>Jarvis</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
</head>
<body class="auth">
<form method="post" class="auth-box" action="index.php">
  <?= csrf_field() ?>
  <img src="../assets/brand/dash-192.png?v=ball2" alt="" class="auth-logo">
  <h1><?= $mode === 'invite' ? 'Velkommen, ' . e(first_name($invite)) : ($mode === 'setup' ? 'Opret adgang' : 'Log ind') ?></h1>
  <?php if ($mode === 'invite'): ?>
    <p class="muted">Vælg en adgangskode til dit dashboard. Du logger ind med <?= e($invite['email']) ?>.</p>
    <input type="hidden" name="invite" value="<?= e($inviteToken) ?>">
  <?php elseif ($mode === 'setup'): ?>
    <p class="muted">Første gang: vælg den mail og adgangskode, du vil logge ind med.</p>
  <?php endif; ?>
  <?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>
  <input type="hidden" name="action" value="<?= $mode === 'invite' ? 'accept_invite' : $mode ?>">
  <?php if ($mode !== 'invite'): ?>
    <label>Mail<input type="email" name="email" required autofocus autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></label>
  <?php endif; ?>
  <label>Adgangskode<input type="password" name="password" required <?= $mode === 'invite' ? 'autofocus' : '' ?> autocomplete="<?= $mode === 'login' ? 'current-password' : 'new-password' ?>"></label>
  <?php if ($mode !== 'login'): ?>
    <label>Gentag adgangskode<input type="password" name="password2" required autocomplete="new-password"></label>
  <?php else: ?>
    <label class="check"><input type="checkbox" name="remember" value="1" checked> Forbliv logget ind på denne enhed</label>
  <?php endif; ?>
  <button class="btn"><?= $mode === 'login' ? 'Log ind' : 'Gem og fortsæt' ?></button>
  <a href="../" class="muted small back"><?= icon('left') ?> Tilbage til siden</a>
</form>
</body>
</html>
<?php
    exit;
}

$me = current_user();
$isOwner = is_owner($me);
$ui = load_json(ukey('ui'));

$hacks = load_json(ukey('lifehacks'));
$categories = load_json(ukey('categories'), default_categories());

if ($action) {
    verify_csrf();

    if ($action === 'logout') {
        remember_clear();
        $_SESSION = [];
        session_destroy();
        redirect('../');
    }

    if ($action === 'quick_hack') {
        $text = trim(preg_replace('/\s+/u', ' ', (string)($_POST['text'] ?? '')));
        if ($text === '') {
            redirect('index.php');
        }
        $text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
        $first = preg_split('/(?<=[.!?])\s+/u', $text)[0];
        $id = bin2hex(random_bytes(6));
        $hacks[$id] = [
            'id' => $id,
            'title' => mb_strlen($first) > 60 ? rtrim(mb_substr($first, 0, 57)) . '…' : rtrim($first, '.'),
            'category' => '',
            'content' => $text,
            'link' => '',
            'tags' => [],
            'pinned' => false,
            'created' => date('c'),
            'updated' => date('c'),
        ];
        save_json(ukey('lifehacks'), $hacks);
        flash('Lifehack gemt. Giv den en kategori, når du har tid.');
        redirect('index.php');
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
            save_json(ukey('categories'), $categories);
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
        save_json(ukey('lifehacks'), $hacks);
        flash('Lifehack gemt.');
        redirect('index.php?view=hack&id=' . urlencode($id));
    }

    if ($action === 'delete_hack') {
        unset($hacks[$_POST['id'] ?? '']);
        save_json(ukey('lifehacks'), $hacks);
        flash('Lifehack slettet.');
        redirect('index.php?view=hacks');
    }

    if ($action === 'toggle_pin' && isset($hacks[$_POST['id'] ?? ''])) {
        $hacks[$_POST['id']]['pinned'] = empty($hacks[$_POST['id']]['pinned']);
        save_json(ukey('lifehacks'), $hacks);
        redirect($_POST['back'] ?? './');
    }

    if ($action === 'save_categories') {
        $new = array_values(array_unique(array_filter(array_map('trim', explode("\n", (string)($_POST['categories'] ?? ''))))));
        save_json(ukey('categories'), $new);
        flash('Kategorier gemt.');
        redirect('index.php?view=settings');
    }

    if ($action === 'change_password') {
        if (!password_verify((string)($_POST['current'] ?? ''), $me['hash'] ?? '')) {
            flash('Nuværende adgangskode er forkert.');
        } elseif (mb_strlen((string)($_POST['new'] ?? '')) < 10) {
            flash('Ny adgangskode skal være mindst 10 tegn.');
        } elseif ($_POST['new'] !== ($_POST['new2'] ?? '')) {
            flash('De nye adgangskoder er ikke ens.');
        } else {
            $users = users_all();
            $users[$me['email']]['hash'] = password_hash($_POST['new'], PASSWORD_DEFAULT);
            $users[$me['email']]['tokens'] = [];
            users_save($users);
            remember_issue($me['email']);
            flash('Adgangskode ændret. Andre enheder er logget ud.');
        }
        redirect('index.php?view=settings');
    }

    $ownerOnly = ['save_profile', 'save_projects', 'fetch_logos', 'fetch_projects', 'add_user', 'user_invite', 'remove_user'];
    if (in_array($action, $ownerOnly, true) && !$isOwner) {
        flash('Det har du ikke adgang til.');
        redirect('index.php');
    }

    if ($action === 'add_user' || $action === 'user_invite') {
        $users = users_all();
        $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
        if ($action === 'add_user') {
            $name = trim((string)($_POST['name'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') {
                flash('Skriv navn og en gyldig mail.');
                redirect('index.php?view=settings#brugere');
            }
            if (isset($users[$email])) {
                flash('Der findes allerede en bruger med den mail.');
                redirect('index.php?view=settings#brugere');
            }
            $users[$email] = ['email' => $email, 'name' => $name, 'role' => 'user', 'hash' => '', 'tokens' => [], 'created' => date('c')];
        }
        if (!isset($users[$email]) || $users[$email]['role'] === 'owner') {
            redirect('index.php?view=settings#brugere');
        }
        $token = bin2hex(random_bytes(24));
        $users[$email]['invite'] = ['hash' => hash('sha256', $token), 'expires' => time() + 7 * 86400];
        users_save($users);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
        $_SESSION['invite_link'] = ['email' => $email, 'url' => $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'langkildelarsen.dk') . '/admin/?invite=' . $token];
        flash('Invitationslink lavet til ' . $users[$email]['name'] . '. Det virker i 7 dage.');
        redirect('index.php?view=settings#brugere');
    }

    if ($action === 'remove_user') {
        $users = users_all();
        $email = (string)($_POST['email'] ?? '');
        if (isset($users[$email]) && $users[$email]['role'] !== 'owner') {
            unset($users[$email]);
            users_save($users);
            flash('Brugeren er fjernet. Deres data ligger stadig på serveren.');
        }
        redirect('index.php?view=settings#brugere');
    }

    if ($action === 'save_music') {
        $raw = trim((string)($_POST['url'] ?? ''));
        $v = '';
        $list = '';
        if (preg_match('~(?:youtu\.be/|[?&]v=|/embed/|/shorts/|/live/)([A-Za-z0-9_-]{11})~', $raw, $m)) {
            $v = $m[1];
        } elseif (preg_match('~^[A-Za-z0-9_-]{11}$~', $raw)) {
            $v = $raw;
        }
        if (preg_match('~[?&]list=([A-Za-z0-9_-]+)~', $raw, $m)) {
            $list = $m[1];
        }
        header('Content-Type: application/json; charset=utf-8');
        if ($v === '' && $list === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Det ligner ikke et YouTube-link.']);
            exit;
        }
        $ui = load_json(ukey('ui'));
        $ui['music'] = ['v' => $v, 'list' => $list, 'url' => $raw];
        save_json(ukey('ui'), $ui);
        echo json_encode(['v' => $v, 'list' => $list, 'url' => $raw]);
        exit;
    }

    if (in_array($action, ['push_subscribe', 'push_unsubscribe', 'push_test'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        $sub = json_decode((string)($_POST['sub'] ?? ''), true);
        if ($action === 'push_subscribe') {
            echo json_encode(['ok' => is_array($sub) && push_subscribe($me, $sub)]);
        } elseif ($action === 'push_unsubscribe') {
            push_unsubscribe($me, (string)($sub['endpoint'] ?? ''));
            echo json_encode(['ok' => true]);
        } else {
            $n = push_send($me, ['title' => 'Jarvis', 'body' => 'Notifikationer virker.', 'url' => '/admin/?view=messages', 'tag' => 'test']);
            echo json_encode(['ok' => $n > 0, 'sent' => $n]);
        }
        exit;
    }

    if ($action === 'save_startup') {
        $ui['splash'] = !empty($_POST['splash']);
        save_json(ukey('ui'), $ui);
        $path = sound_path();
        if (!empty($_POST['remove_sound'])) {
            @unlink($path);
            flash('Startlyden er fjernet.');
        } elseif (!empty($_FILES['sound']['tmp_name']) && is_uploaded_file($_FILES['sound']['tmp_name'])) {
            $f = $_FILES['sound'];
            $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 4);
            $isMp3 = str_starts_with($head, 'ID3') || (strlen($head) > 1 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0);
            if ($f['size'] > 4 * 1024 * 1024) {
                flash('Filen er for stor. Maks 4 MB.');
            } elseif (!$isMp3) {
                flash('Det skal være en mp3-fil.');
            } else {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0755, true);
                }
                move_uploaded_file($f['tmp_name'], $path);
                flash('Startlyden er gemt.');
            }
        } else {
            flash('Indstillingerne er gemt.');
        }
        redirect('index.php?view=settings#opstart');
    }

    if ($action === 'save_weather') {
        $q = trim((string)($_POST['city'] ?? ''));
        $place = $q !== '' ? weather_geocode($q) : null;
        if ($place) {
            $ui['weather'] = $place;
            save_json(ukey('ui'), $ui);
            flash('Vejret viser nu ' . $place['name'] . '.');
        } else {
            flash('Kunne ikke finde byen "' . $q . '". Prøv en anden stavemåde.');
        }
        redirect('index.php?view=settings#vejr');
    }

    if ($action === 'add_event') {
        ev_add((string)($_POST['title'] ?? ''), (string)($_POST['date'] ?? ''), ($_POST['time'] ?? '') !== '' ? (string)$_POST['time'] : null);
        $cmBack = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['cm'] ?? '')) ? '?cm=' . $_POST['cm'] : '';
        redirect('index.php' . $cmBack);
    }

    if ($action === 'remove_event') {
        ev_remove((string)($_POST['id'] ?? ''));
        $cmBack = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['cm'] ?? '')) ? '?cm=' . $_POST['cm'] : '';
        redirect('index.php' . $cmBack);
    }

    if ($action === 'launch_opts') {
        $lp = launch_load();
        $lp['opt'] = [
            'search' => (string)($_POST['search'] ?? 'google'),
            'greet' => !empty($_POST['greet']),
        ];
        launch_save($lp);
        redirect('index.php?view=launch&edit=1');
    }

    if (in_array($action, ['launch_save', 'launch_remove', 'launch_order'], true)) {
        $lp = launch_load();
        $back = 'index.php?view=launch&edit=1';
        if ($action === 'launch_remove') {
            foreach ($lp['links'] as $l) {
                if ($l['id'] === ($_POST['id'] ?? '')) {
                    foreach (['img', 'icon'] as $k) {
                        launch_drop_file($l[$k] ?? '');
                    }
                }
            }
            $lp['links'] = array_values(array_filter($lp['links'], fn($l) => $l['id'] !== ($_POST['id'] ?? '')));
        } elseif ($action === 'launch_order') {
            $order = array_flip(array_filter(explode(',', (string)($_POST['order'] ?? ''))));
            usort($lp['links'], fn($a, $b) => ($order[$a['id']] ?? 999) <=> ($order[$b['id']] ?? 999));
            launch_save($lp);
            header('Content-Type: application/json');
            echo '{"ok":true}';
            exit;
        } else {
            $url = launch_norm_url((string)($_POST['url'] ?? ''));
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 40);
            if ($url === '' || $name === '') {
                flash('Skriv både navn og adresse.');
                redirect($back);
            }
            $id = (string)($_POST['id'] ?? '');
            $idx = null;
            foreach ($lp['links'] as $i => $l) {
                if ($l['id'] === $id) {
                    $idx = $i;
                }
            }
            if ($idx === null) {
                $lp['links'][] = ['id' => bin2hex(random_bytes(4)), 'name' => $name, 'url' => $url, 'img' => ''];
                $idx = count($lp['links']) - 1;
            }
            $row = &$lp['links'][$idx];
            $row['name'] = $name;
            $row['pos'] = launch_pos((string)($_POST['pos'] ?? ''));
            $urlChanged = $row['url'] !== $url;
            $row['url'] = $url;
            if (!empty($_POST['remove_img']) && $row['img']) {
                launch_drop_file($row['img']);
                $row['img'] = '';
            }
            if (!empty($_POST['remove_icon']) && !empty($row['icon'])) {
                launch_drop_file($row['icon']);
                $row['icon'] = '';
            }
            $iconLink = launch_norm_url((string)($_POST['icon_url'] ?? ''));
            if (empty($_FILES['icon']['tmp_name']) && $iconLink !== '') {
                launch_drop_file($row['icon'] ?? '');
                $row['icon'] = $iconLink;
            }
            if (!empty($_FILES['icon']['tmp_name'])) {
                $ic = save_upload($_FILES['icon'], 'launch', 'ic-' . $row['id']);
                if ($ic) {
                    $row['icon'] = $ic;
                } else {
                    flash('Ikonet kunne ikke bruges. Prøv PNG, SVG eller WebP.');
                }
            }
            $imgLink = launch_norm_url((string)($_POST['img_url'] ?? ''));
            if (empty($_FILES['img']['tmp_name']) && $imgLink !== '') {
                launch_drop_file($row['img']);
                $row['img'] = $imgLink;
                $_POST['auto'] = '';
            }
            if (!empty($_FILES['img']['tmp_name'])) {
                $saved = save_upload($_FILES['img'], 'launch', 'lp-' . $row['id']);
                if ($saved) {
                    $row['img'] = $saved;
                } else {
                    flash('Billedet kunne ikke bruges. Prøv JPG, PNG eller WebP.');
                }
            } elseif (!empty($_POST['auto']) && ($row['img'] === '' || $urlChanged) && empty($_POST['remove_img'])) {
                $got = launch_fetch_image($url, $row['id']);
                if ($got) {
                    $row['img'] = $got;
                }
            }
            unset($row);
        }
        launch_save($lp);
        redirect($back);
    }

    if ($action === 'watch_add') {
        $q = trim((string)($_POST['q'] ?? ''));
        if ($q !== '') {
            watch_add($q);
        }
        redirect('index.php');
    }

    if ($action === 'watch_remove') {
        watch_remove((string)($_POST['id'] ?? ''));
        redirect('index.php');
    }

    if ($action === 'add_reminder') {
        $text = trim((string)($_POST['text'] ?? ''));
        $due = rem_parse_due((string)($_POST['due'] ?? '')) ?? strtotime('+1 hour');
        if ($text !== '') {
            rem_add($text, $due);
        }
        redirect('index.php');
    }

    if ($action === 'toggle_reminder') {
        [$op, $rid] = array_pad(explode(':', (string)($_POST['op'] ?? ''), 2), 2, '');
        if ($op === 'done') {
            rem_toggle($rid);
        } elseif (in_array($op, ['1h', 'tomorrow', '1w'], true)) {
            rem_snooze($rid, $op);
        }
        redirect('index.php');
    }

    if ($action === 'log_workout') {
        training_add($_POST);
        redirect('index.php');
    }

    if ($action === 'undo_workout') {
        $t = training_load();
        for ($i = count($t['log']) - 1; $i >= 0; $i--) {
            if ($t['log'][$i]['date'] === date('Y-m-d')) {
                array_splice($t['log'], $i, 1);
                break;
            }
        }
        training_save($t);
        redirect('index.php');
    }

    if ($action === 'toggle_habit') {
        $t = training_load();
        $hb = (string)($_POST['habit'] ?? '');
        if (in_array($hb, $t['habits'], true)) {
            $d = date('Y-m-d');
            $cur = $t['checks'][$d] ?? [];
            $t['checks'][$d] = in_array($hb, $cur, true) ? array_values(array_diff($cur, [$hb])) : array_merge($cur, [$hb]);
            training_save($t);
        }
        redirect('index.php');
    }

    if ($action === 'save_training') {
        $t = training_load();
        $t['week_goal'] = max(1, min(14, (int)($_POST['week_goal'] ?? 3)));
        $t['km_goal'] = max(0, min(1000, (int)($_POST['km_goal'] ?? 30)));
        $t['min_goal'] = max(0, min(10000, (int)($_POST['min_goal'] ?? 600)));
        $t['habits'] = array_values(array_slice(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 30), preg_split('/\R/', (string)($_POST['habits'] ?? '')))), 0, 6));
        training_save($t);
        flash('Træningsmålene er gemt.');
        redirect('index.php?view=settings#traening');
    }

    if ($action === 'send_message') {
        $to = mb_strtolower(trim((string)($_POST['to'] ?? '')));
        $text = trim((string)($_POST['text'] ?? ''));
        if ($text !== '' && isset(users_all()[$to]) && $to !== $me['email']) {
            dm_send($me['email'], $to, $text);
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            exit;
        }
        redirect('index.php?view=messages&with=' . rawurlencode($to));
    }

    if ($action === 'chat') {
        header('Content-Type: application/json; charset=utf-8');
        $text = trim((string)($_POST['text'] ?? ''));
        if ($text === '') {
            echo json_encode(['error' => 'Skriv en besked.']);
            exit;
        }
        $usage = load_json(ukey('chat-usage'));
        $today = date('Y-m-d');
        $limit = $isOwner ? 300 : 60;
        if (($usage[$today] ?? 0) >= $limit) {
            echo json_encode(['error' => 'Dagens grænse på ' . $limit . ' beskeder er nået. Prøv igen i morgen.']);
            exit;
        }
        $usage = [$today => ($usage[$today] ?? 0) + 1];
        save_json(ukey('chat-usage'), $usage);
        @set_time_limit(120);
        echo json_encode(claude_chat($me, mb_substr($text, 0, 4000)), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'clear_chat') {
        save_json(ukey('chat'), []);
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true]);
            exit;
        }
        flash('Samtalen er ryddet.');
        redirect('index.php?view=chat');
    }

    if ($action === 'save_claude_key') {
        if (!$isOwner) {
            redirect('index.php');
        }
        $key = trim((string)($_POST['key'] ?? ''));
        $cfg = load_json('claude');
        if (!empty($_POST['remove'])) {
            unset($cfg['key'], $cfg['saved']);
            save_json('claude', $cfg);
            flash('API-nøglen er fjernet.');
        } else {
            if ($key !== '') {
                $cfg['key'] = $key;
                $cfg['saved'] = date('c');
            }
            $model = (string)($_POST['model'] ?? '');
            if (isset(CLAUDE_MODELS[$model])) {
                $cfg['model'] = $model;
            }
            $cfg['cap'] = max(0, (int)preg_replace('/\D/', '', (string)($_POST['cap'] ?? '0')));
            save_json('claude', $cfg);
            flash($key !== '' ? 'API-nøglen er gemt.' : 'Indstillingerne er gemt.');
        }
        redirect('index.php?view=settings#claude');
    }

    if ($action === 'save_budget') {
        save_json(ukey('budget'), budget_from_post($_POST));
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
        save_json(ukey('calendars'), $cals);
        save_json(ukey('cal-cache'), []);
        $res = cal_events(30, true);
        flash($res['errors'] ? 'Gemt, men disse kunne ikke hentes: ' . implode(', ', $res['errors']) . '. Tjek linket.' : 'Kalendere gemt.');
        redirect('index.php?view=settings');
    }

    if ($action === 'save_background') {
        $ui = load_json(ukey('ui'));
        if (!empty($_POST['reset'])) {
            if (!empty($ui['bg'])) {
                @unlink(UPLOAD_DIR . '/' . $ui['bg']);
            }
            $ui['bg'] = '';
            flash('Standardbaggrunden er sat igen.');
        } else {
            $ui['bg_dim'] = max(0, min(85, (int)($_POST['bg_dim'] ?? ($ui['bg_dim'] ?? 45))));
            $ui['bg_blur'] = max(0, min(60, (int)($_POST['bg_blur'] ?? ($ui['bg_blur'] ?? 24))));
            $ui['bg_card'] = max(0, min(95, (int)($_POST['bg_card'] ?? ($ui['bg_card'] ?? 42))));
            $ui['bg_el'] = max(0, min(40, (int)($_POST['bg_el'] ?? ($ui['bg_el'] ?? 7))));
            $ui['bg_img_blur'] = max(0, min(40, (int)($_POST['bg_img_blur'] ?? ($ui['bg_img_blur'] ?? 0))));
            $hasFile = !empty($_FILES['bg']['tmp_name']);
            $saved = $hasFile ? save_upload($_FILES['bg'], 'backgrounds', 'bg-' . bin2hex(random_bytes(3))) : null;
            if ($saved && function_exists('imagecreatefromstring')) {
                $full = UPLOAD_DIR . '/' . $saved;
                $sz = @getimagesize($full);
                if ($sz && $sz[0] > 2560 && ($im = @imagecreatefromstring((string)file_get_contents($full)))) {
                    $nw = 2560;
                    $nh = (int)round($sz[1] * $nw / $sz[0]);
                    $dst = imagecreatetruecolor($nw, $nh);
                    imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $sz[0], $sz[1]);
                    $jpg = preg_replace('/\.\w+$/', '.jpg', $saved);
                    imagejpeg($dst, UPLOAD_DIR . '/' . $jpg, 84);
                    if ($jpg !== $saved) {
                        @unlink($full);
                    }
                    $saved = $jpg;
                }
            }
            if (!$hasFile) {
                flash('Baggrunden er opdateret.');
            } elseif ($saved) {
                if (!empty($ui['bg'])) {
                    @unlink(UPLOAD_DIR . '/' . $ui['bg']);
                }
                $ui['bg'] = $saved;
                flash('Ny baggrund gemt.');
            } else {
                flash('Vælg et billede (JPG, PNG eller WebP).');
            }
        }
        save_json(ukey('ui'), $ui);
        redirect('index.php?view=settings#baggrund');
    }

    if ($action === 'api_token') {
        $token = bin2hex(random_bytes(20));
        save_json(ukey('api'), ['hash' => hash('sha256', $token), 'created' => date('c')]);
        $_SESSION['new_token'] = $token;
        flash('Ny nøgle lavet. Kopiér den nu – den vises kun én gang.');
        redirect('index.php?view=settings#siri');
    }

    if ($action === 'api_revoke') {
        save_json(ukey('api'), []);
        flash('Nøglen er slået fra. Dine genveje virker ikke længere.');
        redirect('index.php?view=settings#siri');
    }

    if ($action === 'fetch_logos') {
        @set_time_limit(120);
        $logos = load_json('logos');
        $ok = 0;
        $fail = [];
        foreach (get_profile()['experience'] as $x) {
            $d = domain_of($x['url']);
            if ($d === '' || (isset($logos[$d]) && empty($_POST['force']))) {
                continue;
            }
            $saved = fetch_logo($x['url']);
            if ($saved) {
                $logos[$d] = $saved;
                $ok++;
            } else {
                $fail[] = $x['company'];
            }
        }
        save_json('logos', $logos);
        flash("Hentede $ok logo" . ($ok === 1 ? '' : 'er') . '.' . ($fail ? ' Kunne ikke finde: ' . implode(', ', array_unique($fail)) . ' – upload dem selv.' : ''));
        redirect('index.php?view=profile');
    }

    if ($action === 'fetch_projects') {
        @set_time_limit(180);
        $images = load_json('project-images');
        $ok = 0;
        $fail = [];
        foreach (get_profile()['projects'] as $pr) {
            if ($pr['image'] !== '' && empty($_POST['force'])) {
                continue;
            }
            $saved = fetch_project_image($pr);
            if ($saved) {
                $images[$pr['url']] = $saved;
                $ok++;
            } else {
                $fail[] = $pr['title'];
            }
        }
        save_json('project-images', $images);
        if (!empty($_POST['force'])) {
            $p = get_profile();
            foreach ($p['projects'] as &$pr) {
                if (isset($images[$pr['url']])) {
                    $pr['image'] = $images[$pr['url']];
                }
            }
            unset($pr);
            save_json('profile', $p);
        }
        flash("Hentede $ok billede" . ($ok === 1 ? '' : 'r') . '.' . ($fail ? ' Mangler: ' . implode(', ', $fail) . ' – upload dem selv.' : ''));
        redirect('index.php?view=projects');
    }

    if ($action === 'save_projects') {
        $p = get_profile();
        $list = [];
        foreach ($_POST['pr_title'] ?? [] as $i => $title) {
            $row = [
                'title' => trim((string)$title),
                'url' => trim((string)($_POST['pr_url'][$i] ?? '')),
                'image' => trim((string)($_POST['pr_image'][$i] ?? '')),
                'show' => !empty($_POST['pr_show'][$i]),
            ];
            if ($row['title'] === '' && $row['url'] === '') {
                continue;
            }
            if (isset($_FILES['pr_file']['error'][$i]) && $_FILES['pr_file']['error'][$i] === UPLOAD_ERR_OK) {
                $saved = save_upload(['error' => UPLOAD_ERR_OK, 'tmp_name' => $_FILES['pr_file']['tmp_name'][$i]], 'projects', slug($row['title'] ?: 'projekt') . '-' . bin2hex(random_bytes(2)));
                if ($saved) {
                    $row['image'] = $saved;
                }
            }
            $list[] = $row;
        }
        $p['projects'] = $list;
        save_json('profile', $p);
        flash('Projekter gemt.');
        redirect('index.php?view=projects');
    }

    if ($action === 'refresh_calendar') {
        cal_events(30, true);
        redirect('index.php?view=calendar');
    }

    if ($action === 'save_profile') {
        $p = get_profile();
        foreach (['name', 'title', 'location', 'email', 'linkedin', 'dribbble', 'about', 'now', 'status'] as $f) {
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
            $p['education'][] = ['title' => $parts[0], 'school' => $parts[1] ?? '', 'period' => $parts[2] ?? '', 'url' => $parts[3] ?? ''];
        }
        $p['highlights'] = [];
        foreach (preg_split('/\R/', (string)($_POST['highlights'] ?? '')) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if ($parts[0] !== '') {
                $p['highlights'][] = ['value' => $parts[0], 'label' => $parts[1] ?? ''];
            }
        }
        $exp = [];
        foreach ($_POST['exp_role'] ?? [] as $i => $role) {
            $row = [
                'role' => trim((string)$role),
                'company' => trim((string)($_POST['exp_company'][$i] ?? '')),
                'url' => trim((string)($_POST['exp_url'][$i] ?? '')),
                'period' => trim((string)($_POST['exp_period'][$i] ?? '')),
                'text' => trim((string)($_POST['exp_text'][$i] ?? '')),
                'logo' => trim((string)($_POST['exp_logo'][$i] ?? '')),
            ];
            if ($row['url'] !== '' && !preg_match('~^https?://~i', $row['url'])) {
                $row['url'] = 'https://' . $row['url'];
            }
            if (!empty($_POST['exp_logo_remove'][$i])) {
                $row['logo'] = '';
            }
            if (isset($_FILES['exp_logo_file']['error'][$i]) && $_FILES['exp_logo_file']['error'][$i] === UPLOAD_ERR_OK) {
                $file = [
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => $_FILES['exp_logo_file']['tmp_name'][$i],
                ];
                $saved = save_upload($file, 'logos', 'custom-' . slug($row['company'] ?: 'logo'));
                if ($saved) {
                    $row['logo'] = $saved;
                }
            }
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
if (in_array($view, ['hacks', 'hack', 'edit'], true)) {
    $view = 'dashboard';
}
if (!$isOwner && in_array($view, ['profile', 'projects'], true)) {
    $view = 'dashboard';
}
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
    'projects' => 'Projekter',
    'messages' => 'Beskeder',
    'chat' => 'Jarvis',
];

try {
    rem_dispatch();
} catch (Throwable $e) {
}

$budget = load_json(ukey('budget'), ['income' => [], 'expenses' => []]);

if (($_GET['ajax'] ?? '') === 'thread') {
    $with = mb_strtolower((string)($_GET['with'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!isset(users_all()[$with])) {
        echo '[]';
        exit;
    }
    dm_mark_read($me['email'], $with);
    echo json_encode(array_map(fn($m) => dm_public($m, $me['email']), array_slice(dm_thread($me['email'], $with), -200)), JSON_UNESCAPED_UNICODE);
    exit;
}

function jarvis_orb(string $class = ''): string
{
    static $ticks = null;
    if ($ticks === null) {
        $ticks = '';
        for ($i = 0; $i < 72; $i++) {
            $a = deg2rad($i * 5);
            $l = $i % 6 === 0 ? 4 : 2;
            $ticks .= sprintf('<line x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f"/>', 50 + 44 * cos($a), 50 + 44 * sin($a), 50 + (44 - $l) * cos($a), 50 + (44 - $l) * sin($a));
        }
    }
    return '<div class="jv-orb ' . $class . '" aria-hidden="true"><svg viewBox="0 0 100 100"><circle class="jv-r jv-r1" cx="50" cy="50" r="48"/><g class="jv-ticks">' . $ticks . '</g><circle class="jv-r jv-r2" cx="50" cy="50" r="40"/><circle class="jv-r jv-r3" cx="50" cy="50" r="34"/><circle class="jv-r jv-r4" cx="50" cy="50" r="29" pathLength="100"/><circle class="jv-r jv-r5" cx="50" cy="50" r="23"/></svg><span class="jv-pulse"></span><span class="jv-pulse jv-p2"></span><div class="jv-core"></div></div>';
}

function sound_path(?array $user = null): string
{
    return DATA_DIR . '/' . ukey('startsound', $user) . '.mp3';
}

function weather_card(?array $w): string
{
    if (!$w) {
        return '<div class="wx-empty" data-wx-load><span class="wx-spin"></span><p class="muted small">Henter vejret…</p></div>';
    }
    [$label, $ic] = weather_info($w['code'], $w['day']);
    $dn = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];
    $h = '<div class="wxs-now"><span class="wxs-bub">' . icon($ic) . '</span><div><p class="wxs-t">' . $w['temp'] . '<sup>°</sup></p><p class="wxs-l">' . e($label) . ' · føles som ' . $w['feels'] . '°</p></div></div>';
    $h .= '<div class="wxs-days">';
    foreach (array_slice($w['days'], 0, 4) as $i => $d) {
        [$dl, $di] = weather_info($d['code']);
        $h .= '<div class="wxs-d"><span>' . ($i === 0 ? 'I dag' : $dn[(int)date('N', strtotime($d['d'])) - 1]) . '</span><span title="' . e($dl) . '">' . icon($di) . '</span><span class="lo">' . $d['min'] . '°</span><span class="hi">' . $d['max'] . '°</span></div>';
    }
    return $h . '</div>';
}

function training_card(array $t): string
{
    $s = training_stats($t);
    $today = date('Y-m-d');
    $logs = array_values(array_filter($t['log'], fn($r) => $r['date'] >= date('Y-m-d', training_week_start(time()))));
    usort($logs, fn($a, $b) => strcmp($a['date'], $b['date']));
    $dn = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'];
    $slots = max($s['goal'], count($logs));
    $h = '<div class="trc-slots" style="--n: ' . min($slots, 7) . '">';
    $next = true;
    for ($i = 0; $i < $slots; $i++) {
        if (isset($logs[$i])) {
            $d = $logs[$i]['date'];
            $h .= '<span class="trc-slot on' . ($i >= $s['goal'] ? ' extra' : '') . '"><i>' . icon('check') . '</i><small>' . ($d === $today ? 'I dag' : $dn[(int)date('N', strtotime($d)) - 1]) . '</small></span>';
        } else {
            $h .= '<span class="trc-slot' . ($next ? ' next' : '') . '"><i>' . ($i + 1) . '</i><small>' . ($next ? 'Næste' : '&nbsp;') . '</small></span>';
            $next = false;
        }
    }
    $h .= '</div>';
    $trainedToday = (bool)array_filter($t['log'], fn($r) => $r['date'] === $today);
    $h .= '<form method="post" class="trc-act">' . csrf_field();
    if ($trainedToday) {
        $h .= '<input type="hidden" name="action" value="undo_workout"><p class="trc-done">' . icon('check') . 'Trænet i dag</p><button class="trc-undo">Fortryd</button>';
    } else {
        $h .= '<input type="hidden" name="action" value="log_workout"><input type="hidden" name="type" value="Træning"><button class="btn trc-btn">Jeg har trænet i dag</button>';
    }
    return $h . '</form>';
}

function app_version(): string
{
    $root = dirname(__DIR__);
    $files = array_merge([__FILE__, $root . '/assets/admin.js', $root . '/assets/admin.css'], glob($root . '/inc/*.php') ?: []);
    return substr(md5(implode('|', array_map(fn($f) => is_file($f) ? filemtime($f) . filesize($f) : '', $files))), 0, 10);
}

if (($_GET['ajax'] ?? '') === 'version') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['v' => app_version()]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'push_key') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['key' => push_public_key(), 'count' => count(push_subs($me))]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'sound') {
    $path = sound_path();
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: audio/mpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=86400');
    header('Accept-Ranges: none');
    readfile($path);
    exit;
}

if (($_GET['ajax'] ?? '') === 'weather') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $w = weather_get();
    echo $w ? weather_card($w) : '<div class="wx-empty"><p class="muted small">Vejret kunne ikke hentes lige nu.</p></div>';
    exit;
}

if (($_GET['ajax'] ?? '') === 'unread') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['count' => dm_unread($me['email'])]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'search') {
    $items = [];
    $pages = [['Oversigt', './'], ['Budget', './?view=budget'], ['Rediger budget', './?view=budget&edit=1'], ['Kalender', './?view=calendar'], ['Indstillinger', './?view=settings']];
    if ($isOwner) {
        array_push($pages, ['Profil & CV', './?view=profile'], ['Projekter', './?view=projects']);
    }
    foreach ($pages as [$t, $u]) {
        $items[] = ['type' => 'Side', 'title' => $t, 'sub' => '', 'url' => $u, 'text' => $t];
    }
    foreach (rem_load() as $r) {
        if (!$r['done']) {
            $items[] = ['type' => 'Påmindelse', 'title' => $r['text'], 'sub' => rem_when($r['due']), 'url' => './', 'text' => $r['text']];
        }
    }
    foreach (array_merge($budget['income'] ?? [], $budget['expenses'] ?? []) as $x) {
        $items[] = ['type' => 'Budget', 'title' => $x['name'], 'sub' => kr((float)$x['amount']) . ' · ' . mb_strtolower(BUDGET_FREQ[$x['freq'] ?? 'month'][0]), 'url' => './?view=budget', 'text' => $x['name'] . ' ' . ($x['category'] ?? '')];
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($items, JSON_UNESCAPED_UNICODE);
    exit;
}
$sum = budget_summary($budget);
$cal = in_array($view, ['dashboard', 'calendar'], true) ? cal_events($view === 'calendar' ? 30 : 62) : ['events' => [], 'errors' => []];
$hasCal = (bool)cal_sources();

$pageTitle = $titles[$view] ?? 'Oversigt';
if ($view === 'launch') {
    require __DIR__ . '/launch.php';
    exit;
}
?><!doctype html>
<html lang="da" data-version="<?= e(app_version()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050506">
<?= app_head() ?>
<title>Jarvis</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
<script src="../assets/admin.js?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.js') ?>" defer></script>
</head>
<?php $bgFile = (string)($ui['bg'] ?? ''); $hasBg = $bgFile !== '' && is_file(UPLOAD_DIR . '/' . $bgFile); ?>
<body<?= $hasBg ? ' class="has-bg" style="--bg-dim: ' . ((int)($ui['bg_dim'] ?? 45) / 100) . '; --glass-blur: ' . (int)($ui['bg_blur'] ?? 24) . 'px; --glass-a: ' . ((int)($ui['bg_card'] ?? 42) / 100) . '; --el-a: ' . ((int)($ui['bg_el'] ?? 7) / 100) . '; --img-blur: ' . (int)($ui['bg_img_blur'] ?? 0) . 'px"' : '' ?>>
<?php if ($hasBg): ?><div class="dash-bg" style="background-image: url('../uploads/<?= e($bgFile) ?>?v=<?= filemtime(UPLOAD_DIR . '/' . $bgFile) ?>')" aria-hidden="true"></div><?php endif; ?>
<div class="app">
  <aside class="rail" aria-label="Menu">
    <a href="./" class="rail-logo" aria-label="Oversigt"><img src="../assets/brand/dash-mark.png" alt=""></a>
    <nav class="rail-nav">
      <span class="rail-pill" aria-hidden="true"></span>
      <?php
        $railItems = [
            ['dashboard', './', 'home', 'Oversigt'],
            ['budget', './?view=budget', 'wallet', 'Budget'],
            ['messages', './?view=messages', 'inbox', 'Beskeder'],
            ['chat', './?view=chat', 'ai', 'Jarvis'],
        ];
        $unreadCount = dm_unread($me['email']);
        $activeView = in_array($view, ['hack', 'edit'], true) ? 'hacks' : (in_array($view, ['profile', 'projects'], true) ? 'settings' : $view);
      ?>
      <?php foreach ($railItems as [$v, $href, $ic, $label]): ?>
        <a href="<?= $href ?>" class="<?= $activeView === $v ? 'active' : '' ?>" aria-label="<?= e($label) ?>"><?= icon($ic) ?><?php if ($v === 'messages'): ?><i class="rail-dot" data-unread-dot<?= $unreadCount ? '' : ' hidden' ?>></i><?php endif; ?><span class="tip"><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <form method="post" class="rail-out" data-reload>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="logout">
      <button aria-label="Log ud"><?= icon('logout') ?><span class="tip">Log ud</span></button>
    </form>
  </aside>

  <main class="content">
    <header class="topbar">
      <?php [$greet, $emoji] = greeting(); ?>
      <h1><?php if ($view === 'dashboard'): ?><?= e(str_replace('{n}', first_name($me), $greet)) ?><?php else: ?><?= e($pageTitle) ?><?php endif; ?></h1>
      <div class="mp-dock" role="group" aria-label="Musik">
        <button type="button" class="dk-b" data-mp="prev" aria-label="Forrige"><?= icon('prev') ?></button>
        <button type="button" class="dk-b big" data-mp="toggle" aria-label="Afspil/pause"><span class="i-play"><?= icon('play') ?></span><span class="i-pause"><?= icon('pause') ?></span></button>
        <button type="button" class="dk-b" data-mp="next" aria-label="Næste"><?= icon('next') ?></button>
        <span class="dk-vol">
          <button type="button" class="dk-b" data-mp="mute" aria-label="Slå lyd fra/til"><span class="i-vol"><?= icon('vol2') ?></span><span class="i-mute"><?= icon('mute') ?></span></button>
          <input type="range" class="mp-volume" min="0" max="100" value="60" aria-label="Lydstyrke">
        </span>
        <button type="button" class="dk-title" data-player-open aria-label="Udvid musikafspiller"><span class="eq" aria-hidden="true"><i></i><i></i><i></i></span><span class="mp-dock-t">Musik</span><?= icon('down') ?></button>
      </div>
      <button type="button" class="bell search-btn" data-search-open aria-label="Søg (Cmd+K)"><?= icon('search') ?></button>
      <?php
        $notes = [];
        foreach (array_reverse(dm_all()) as $m) {
            if ($m['to'] === $me['email'] && empty($m['read'])) {
                $from = users_all()[$m['from']] ?? ['name' => $m['from'], 'email' => $m['from']];
                $notes[] = ['ic' => $m['type'] === 'request' ? 'sparkle' : 'chat', 'title' => ($m['type'] === 'request' ? 'Ønske fra ' : 'Besked fra ') . first_name($from), 'text' => $m['text'], 'when' => day_label(strtotime($m['created'])) . ' ' . date('H:i', strtotime($m['created'])), 'url' => './?view=messages&with=' . rawurlencode($m['from']), 'unread' => true];
            }
            if (count($notes) >= 6) break;
        }
        $todayEnd = strtotime('tomorrow');
        foreach (cal_events(2)['events'] as $ev) {
            if ($ev['start'] < $todayEnd + 86400) {
                $notes[] = ['ic' => 'calendar', 'title' => $ev['title'], 'text' => event_time($ev), 'when' => day_label($ev['start']), 'url' => './?view=calendar', 'unread' => false];
            }
        }
        foreach (upcoming_payments($budget, 2) as $pp) {
            $notes[] = ['ic' => 'wallet', 'title' => $pp['name'] . ' trækkes', 'text' => kr($pp['amount']), 'when' => day_label($pp['ts']), 'url' => './?view=budget', 'unread' => false];
        }
      ?>
      <div class="notif">
        <button type="button" class="bell" data-bell aria-label="Notifikationer" aria-expanded="false"><?= icon('bell') ?><i data-unread-dot<?= $unreadCount ? '' : ' hidden' ?>></i></button>
        <div class="notif-pop" hidden>
          <p class="notif-h">Notifikationer</p>
          <?php if ($notes): ?>
            <ul>
              <?php foreach (array_slice($notes, 0, 8) as $n): ?>
                <li><a href="<?= e($n['url']) ?>" class="<?= $n['unread'] ? 'new' : '' ?>"><span class="notif-ic"><?= icon($n['ic']) ?></span><span class="notif-t"><strong><?= e($n['title']) ?></strong><small><?= e(mb_strimwidth($n['text'], 0, 90, '…')) ?></small></span><time><?= e($n['when']) ?></time></a></li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="notif-empty">Ingen nye notifikationer.</p>
          <?php endif; ?>
        </div>
      </div>
      <a class="me bell" href="./?view=settings" aria-label="<?= e($me['name']) ?>" title="<?= e($me['name'] . ' · ' . $me['email']) ?>">
        <?= icon('user') ?>
      </a>
    </header>

    <?php if ($flash): ?><p class="alert"><?= e($flash) ?></p><?php endif; ?>

    <?php if ($view === 'dashboard'): ?>
      <?php
        $fmt = fn($v) => number_format($v, 0, ',', '.');
        $periods = ['m' => ['Måned', 1], 'q' => ['Kvartal', 3], 'y' => ['År', 12]];
        $catBars = array_slice($sum['by_cat'], 0, 9, true);
        $topCat = $catBars ? array_key_first($catBars) : null;
        $niceMax = function (float $x): float {
            if ($x <= 0) return 1;
            $p = pow(10, floor(log10($x)));
            $step = $p / 2;
            return ceil($x / $step) * $step;
        };
        $leftPct = $sum['income'] > 0 ? round($sum['left'] / $sum['income'] * 100) : 0;

        $months = ['januar', 'februar', 'marts', 'april', 'maj', 'juni', 'juli', 'august', 'september', 'oktober', 'november', 'december'];
        $cm = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['cm'] ?? '')) ? $_GET['cm'] : date('Y-m');
        $firstDay = strtotime($cm . '-01');
        $dim = (int)date('t', $firstDay);
        $lead = (int)date('N', $firstDay) - 1;
        $prevM = date('Y-m', strtotime('-1 month', $firstDay));
        $nextM = date('Y-m', strtotime('+1 month', $firstDay));
        $marks = [];
        $dayEv = [];
        $monthEnd = strtotime(date('Y-m-t 23:59', $firstDay));
        $calM = cal_events(max(1, (int)ceil(($monthEnd - strtotime('today')) / 86400) + 1), false, $firstDay);
        foreach ($calM['events'] as $ev) {
            $k = date('Y-m-d', $ev['start']);
            if ($ev['start'] > $monthEnd) {
                continue;
            }
            $marks[$k] = 'm-ev';
            $dayEv[$k][] = ['t' => $ev['title'], 'h' => $ev['allday'] ? 'Hele dagen' : event_time($ev), 'l' => !empty($ev['local']), 'id' => $ev['id'] ?? '', 'c' => $ev['calendar'] ?? ''];
        }

        $items = [];
        foreach ($cal['events'] as $ev) if ($ev['start'] < strtotime('+30 days')) $items[] = ['ts' => $ev['start'], 'kind' => 'ev', 'name' => $ev['title'], 'value' => event_time($ev)];
        foreach (upcoming_payments($budget, 30) as $pp) $items[] = ['ts' => $pp['ts'], 'kind' => 'pay', 'name' => $pp['name'], 'value' => kr($pp['amount'])];
        usort($items, fn($a, $b) => $a['ts'] <=> $b['ts']);
        $next7 = count(array_filter($cal['events'], fn($ev) => $ev['start'] < strtotime('+7 days')));
        $usedPct = $sum['income'] > 0 ? min(100, round($sum['expenses'] / $sum['income'] * 100)) : 0;

        $palette = ['#ff7a2f', '#3ecf6e', '#f2c230', '#5b9dff', '#b18cff', '#ff5f8f', '#5fd4d4', '#8a8a8a'];
        $donut = [];
        $acc = 0;
        $catList = array_slice($sum['by_cat'], 0, 7, true);
        $rest = array_sum(array_slice($sum['by_cat'], 7));
        if ($rest > 0) $catList['Andet øvrigt'] = $rest;
        $i = 0;
        foreach ($catList as $c => $v) {
            $pct = $sum['expenses'] > 0 ? $v / $sum['expenses'] * 100 : 0;
            $donut[] = ['name' => $c, 'value' => $v, 'pct' => $pct, 'start' => $acc, 'color' => $palette[$i++ % count($palette)]];
            $acc += $pct;
        }

        $summary = [];
        $trD = training_load();
        $trS = training_stats($trD);
        $summary[] = training_nag($trD, $trS);
        $remToday = count(array_filter(rem_load(), fn($r) => !$r['done'] && $r['due'] <= strtotime('tomorrow')));
        if ($remToday) {
            $summary[] = "Du har $remToday påmindelse" . ($remToday === 1 ? '' : 'r') . ' i dag.';
        }
        if ($hasCal) {
            $summary[] = $next7 ? "Du har $next7 aftale" . ($next7 === 1 ? '' : 'r') . ' de næste 7 dage.' : 'Kalenderen er tom de næste 7 dage.';
        }
        foreach (watch_load() as $w) {
            $wd = watch_days($w['date']);
            if ($wd !== null && $wd >= 0 && $wd <= 3) {
                $summary[] = $w['title'] . ($wd === 0 ? ' udkommer i dag!' : ($wd === 1 ? ' udkommer i morgen.' : " udkommer om $wd dage."));
            }
        }
      ?>
      <div class="dash">
        <section class="card c-jarvis jv" data-jv data-s="idle" data-home-jarvis>
          <div class="hj">
            <?= jarvis_orb('hj-orb') ?>
            <div class="hj-body">
              <div class="card-head">
                <h2>Jarvis</h2>
                <span class="jv-state"><i></i><span data-jv-label>Klar</span></span>
                <a href="./?view=chat" class="round" aria-label="Åbn chatten"><?= icon('arrow') ?></a>
              </div>
              <p class="hj-say" data-hj-say><?= e(implode(' ', $summary)) ?></p>
              <p class="jv-log" data-jv-log hidden></p>
              <form class="ask hj-ask" data-hj-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="chat">
                <input name="text" placeholder="Spørg Jarvis om noget…" autocomplete="off" <?= claude_key() !== '' ? '' : 'disabled' ?>>
                <button aria-label="Send" <?= claude_key() !== '' ? '' : 'disabled' ?>><?= icon('send') ?></button>
              </form>
              <div class="hj-chips">
                <button type="button" data-hj-q="Hvordan ser min dag ud?">Min dag</button>
                <button type="button" data-hj-q="Jeg har trænet i dag">Log træning</button>
                <button type="button" data-hj-q="Hvad holder jeg øje med?">Udgivelser</button>
              </div>
            </div>
          </div>
        </section>

        <section class="card c-weather c-wxs">
          <div class="card-head">
            <h2>Vejret</h2>
            <a href="./?view=settings#vejr" class="pill-link"><?= e(weather_place()['name']) ?></a>
          </div>
          <?php $wxc = load_json('weather-cache'); $wxp = weather_place(); $wxe = $wxc[$wxp['lat'] . ',' . $wxp['lon']] ?? null; ?>
          <div class="wx-wrap" data-wx<?= (!$wxe || $wxe['t'] < time() - 1200) ? ' data-wx-refresh' : '' ?>><?= weather_card($wxe['d'] ?? null) ?></div>
        </section>

        <section class="card c-train">
          <div class="card-head">
            <h2>Træning</h2>
            <span class="drop"><?= $trS['sessions'] ?> af <?= $trS['goal'] ?> denne uge</span>
          </div>
          <?= training_card($trD) ?>
        </section>

        <section class="card c-cal">
          <div class="cal-head">
            <a href="./?cm=<?= $prevM ?>" class="round" aria-label="Forrige måned"><?= icon('left') ?></a>
            <h2><?= e(ucfirst($months[(int)date('n', $firstDay) - 1]) . ' ' . date('Y', $firstDay)) ?></h2>
            <a href="./?cm=<?= $nextM ?>" class="round" aria-label="Næste måned"><?= icon('right') ?></a>
          </div>
          <div class="cal-grid" data-cal-days="<?= e(json_encode($dayEv, JSON_UNESCAPED_UNICODE)) ?>" data-cm="<?= e($cm) ?>">
            <?php foreach (['M', 'T', 'O', 'T', 'F', 'L', 'S'] as $d): ?><span class="dow"><?= $d ?></span><?php endforeach; ?>
            <?php for ($i = 0; $i < $lead; $i++): ?><span class="day off"></span><?php endfor; ?>
            <?php for ($d = 1; $d <= $dim; $d++): $key = $cm . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT); ?>
              <button type="button" class="day<?= $key === date('Y-m-d') ? ' today' : '' ?><?= isset($marks[$key]) ? ' mark ' . $marks[$key] : '' ?><?= $key < date('Y-m-d') ? ' was' : '' ?>" data-day="<?= $key ?>" aria-label="<?= e(dk_date($key)) ?><?= isset($dayEv[$key]) ? ', ' . count($dayEv[$key]) . ' aftale' . (count($dayEv[$key]) === 1 ? '' : 'r') : '' ?>"><?= $d ?></button>
            <?php endfor; ?>
            <?php for ($i = ($lead + $dim) % 7; $i && $i < 7; $i++): ?><span class="day off"></span><?php endfor; ?>
          </div>
          <div class="cal-tip" data-cal-tip hidden></div>
          <div class="cal-pop" data-cal-pop hidden>
            <div class="cal-pop-h"><strong data-cal-pop-title></strong><button type="button" class="cal-pop-x" data-cal-close aria-label="Luk"><?= icon('cross') ?></button></div>
            <form method="post" class="cal-pop-list" data-cal-list>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="remove_event">
              <input type="hidden" name="cm" value="<?= e($cm) ?>">
            </form>
            <form method="post" class="cal-pop-add">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="add_event">
              <input type="hidden" name="cm" value="<?= e($cm) ?>">
              <input type="hidden" name="date" data-cal-date>
              <input type="hidden" name="time" data-cal-time>
              <input name="title" placeholder="Ny aftale…" autocomplete="off" required aria-label="Titel">
              <div class="dp-hours" data-cal-hours>
                <button type="button" data-th="" class="on">Hele dagen</button>
                <?php foreach ([7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21] as $hh): ?><button type="button" data-th="<?= sprintf('%02d', $hh) ?>"><?= sprintf('%02d', $hh) ?></button><?php endforeach; ?>
              </div>
              <div class="dp-mins" data-cal-mins hidden>
                <?php foreach ([0, 15, 30, 45] as $mm): ?><button type="button" data-tm="<?= sprintf('%02d', $mm) ?>" class="<?= $mm === 0 ? 'on' : '' ?>">:<?= sprintf('%02d', $mm) ?></button><?php endforeach; ?>
              </div>
              <button class="btn">Tilføj til kalenderen</button>
            </form>
          </div>
          <?php $nextEv = null; foreach (($cal['events'] ?? []) as $ev) { if (($ev['end'] ?? $ev['start']) >= time()) { $nextEv = $ev; break; } } ?>
          <a class="kpi next-ev" href="./?view=calendar">
            <span class="kpi-ic"><?= icon('calendar') ?></span>
            <?php if ($nextEv): ?>
              <span class="kpi-v"><?= e($nextEv['title']) ?></span>
              <span class="kpi-l"><?= e(day_label($nextEv['start']) . ' · ' . event_time($nextEv)) ?><?= !empty($nextEv['location']) ? ' · ' . e($nextEv['location']) : '' ?></span>
            <?php else: ?>
              <span class="kpi-v">Ingen aftaler</span>
              <span class="kpi-l"><?= $hasCal ? 'de næste 30 dage' : 'Forbind din kalender under Indstillinger' ?></span>
            <?php endif; ?>
          </a>
        </section>

        <section class="card c-rem">
          <div class="card-head">
            <h2>Påmindelser</h2>
            <?php $rems = rem_load(); $open = array_values(array_filter($rems, fn($r) => !$r['done'])); ?>
            <span class="drop"><?= count($open) ?> aktive</span>
          </div>
          <form method="post" class="rem-add">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_reminder">
            <input name="text" placeholder="Husk at…" autocomplete="off" required aria-label="Påmindelse">
            <input type="hidden" name="due" value="<?= date('Y-m-d\TH:00', strtotime('+1 hour')) ?>" data-due>
            <button type="button" class="rem-when" data-when-btn aria-haspopup="dialog" aria-expanded="false"><?= icon('calendar') ?><span data-when-label>Om 1 time</span></button>
            <div class="dp" data-dp hidden></div>
            <button aria-label="Tilføj"><?= icon('plus') ?></button>
          </form>
          <?php if ($open): ?>
            <form method="post" class="rem-list">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_reminder">
              <?php foreach (array_slice($open, 0, 5) as $r): ?>
                <div class="rem-row<?= $r['due'] <= time() ? ' due' : '' ?>">
                  <button name="op" value="done:<?= e($r['id']) ?>" class="rem" title="Markér som klaret">
                    <i></i><span class="rem-t"><?= e($r['text']) ?></span><small><?= e(rem_when($r['due'])) ?></small>
                  </button>
                  <span class="rem-snz">
                    <button name="op" value="1h:<?= e($r['id']) ?>" title="Ryk 1 time">+1 t</button>
                    <button name="op" value="tomorrow:<?= e($r['id']) ?>" title="Ryk til i morgen">I morgen</button>
                    <button name="op" value="1w:<?= e($r['id']) ?>" title="Ryk 1 uge">+1 uge</button>
                  </span>
                </div>
              <?php endforeach; ?>
            </form>
            <?php if (count($open) > 5): ?><p class="muted small">+ <?= count($open) - 5 ?> mere</p><?php endif; ?>
          <?php else: ?>
            <p class="muted small rem-empty">Ingen påmindelser. Skriv en ovenfor eller sig "Jarvis, mind mig om …".</p>
          <?php endif; ?>
        </section>

        <section class="card c-watch">
          <div class="card-head">
            <h2>Holder øje med</h2>
          </div>
          <form method="post" class="rem-add wt-add">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="watch_add">
            <input name="q" placeholder="Serie, film eller noget andet…" autocomplete="off" required aria-label="Hold øje med">
            <button aria-label="Tilføj"><?= icon('plus') ?></button>
          </form>
          <?php $wl = watch_load(); usort($wl, fn($a, $b) => [($a['date'] && watch_days($a['date']) >= 0) ? 0 : 1, $a['date'] ?? '9999'] <=> [($b['date'] && watch_days($b['date']) >= 0) ? 0 : 1, $b['date'] ?? '9999']); ?>
          <?php if ($wl): ?>
            <form method="post" class="wt-list">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="watch_remove">
              <?php foreach (array_slice($wl, 0, 6) as $w): $wd = watch_days($w['date']); ?>
                <div class="wt<?= $wd !== null && $wd >= 0 && $wd <= 1 ? ' soon' : '' ?>">
                  <span class="wt-when"><strong><?= e(watch_when($w['date'])) ?></strong><?php if ($w['date'] && $wd >= 14): ?><small><?= $wd ?> dage</small><?php endif; ?></span>
                  <span class="wt-tx"><strong><?= e($w['title']) ?></strong><small><?= e($w['label']) ?></small></span>
                  <button name="id" value="<?= e($w['id']) ?>" class="wt-x" aria-label="Stop med at holde øje med <?= e($w['title']) ?>"><?= icon('cross') ?></button>
                </div>
              <?php endforeach; ?>
            </form>
          <?php else: ?>
            <p class="muted small rem-empty">Skriv en serie eller film, så holder Jarvis øje med næste episode eller premiere og giver dig besked.</p>
          <?php endif; ?>
        </section>
      </div>

    <?php elseif ($view === 'hacks'): ?>
      <?php $list = filter_hacks($hacks, $cat, $q); ?>
      <div class="chipbar">
        <a href="./?view=hacks" class="<?= $cat === '' ? 'on' : '' ?>">Alle <span><?= $total ?></span></a>
        <?php foreach ($categories as $c): ?><a href="./?view=hacks&cat=<?= urlencode($c) ?>" class="<?= $cat === $c ? 'on' : '' ?>"><?= e($c) ?> <span><?= $counts[$c] ?? 0 ?></span></a><?php endforeach; ?>
        <a href="./?view=edit<?= $cat !== '' ? '&cat=' . urlencode($cat) : '' ?>" class="btn small"><?= icon('plus') ?> Ny lifehack</a>
      </div>
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
        <?php if ($h['link']): ?><p><a href="<?= e($h['link']) ?>" target="_blank" rel="noopener" class="see">Åbn link <?= icon('arrow') ?></a></p><?php endif; ?>
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
        <div class="two">
          <label>LinkedIn-URL<input type="url" name="linkedin" value="<?= e($profile['linkedin']) ?>" placeholder="https://www.linkedin.com/in/…"></label>
          <label>Dribbble-URL<input type="url" name="dribbble" value="<?= e($profile['dribbble']) ?>" placeholder="https://dribbble.com/…"></label>
        </div>
        <input type="hidden" name="now" value="<?= e($profile['now']) ?>">
        <label>Om mig<textarea name="about" rows="4"><?= e($profile['about']) ?></textarea></label>
        <label>Kompetencer <small>(adskilt med komma)</small><input name="skills" value="<?= e(implode(', ', $profile['skills'])) ?>"></label>
        <label>Sprog <small>(adskilt med komma)</small><input name="languages" value="<?= e(implode(', ', $profile['languages'])) ?>"></label>
        <label>Uddannelse <small>(én pr. linje: Titel | Sted | Periode | Hjemmeside)</small><textarea name="education" rows="4"><?= e(implode("\n", array_map(fn($x) => rtrim($x['title'] . ' | ' . $x['school'] . ' | ' . $x['period'] . ' | ' . ($x['url'] ?? ''), ' |'), $profile['education']))) ?></textarea></label>
        <label>Nøgletal på forsiden <small>(én pr. linje: Tal | Tekst)</small><textarea name="highlights" rows="4"><?= e(implode("\n", array_map(fn($x) => $x['value'] . ' | ' . $x['label'], $profile['highlights']))) ?></textarea></label>

        <div class="panel-head">
          <h2 class="section-title">Erfaring</h2>
          <button class="btn ghost" form="fetch-logos">Hent logoer fra hjemmesider</button>
        </div>
        <p class="muted small">Logoer hentes automatisk fra virksomhedens hjemmeside. Ser et forkert ud, så upload det rigtige. Tomme rækker ignoreres; gem for at få flere.</p>
        <?php foreach (array_merge($profile['experience'], array_fill(0, 2, ['role' => '', 'company' => '', 'url' => '', 'period' => '', 'text' => '', 'logo' => ''])) as $i => $x): ?>
          <fieldset class="exp">
            <div class="exp-top">
              <span class="logo"><?php if ($x['logo']): ?><img src="<?= e(img_src($x['logo'], '../')) ?>" alt=""><?php else: ?><?= e(mb_substr($x['company'] ?: '+', 0, 1)) ?><?php endif; ?></span>
              <div class="three">
                <label>Rolle<input name="exp_role[]" value="<?= e($x['role']) ?>"></label>
                <label>Virksomhed<input name="exp_company[]" value="<?= e($x['company']) ?>"></label>
                <label>Periode<input name="exp_period[]" value="<?= e($x['period']) ?>" placeholder="2020 – nu"></label>
              </div>
            </div>
            <div class="two">
              <label>Hjemmeside<input name="exp_url[<?= $i ?>]" value="<?= e($x['url']) ?>" placeholder="firma.dk"></label>
              <label>Eget logo <small>(PNG, SVG, JPG)</small><input type="file" name="exp_logo_file[<?= $i ?>]" accept="image/png,image/svg+xml,image/jpeg,image/webp"></label>
            </div>
            <input type="hidden" name="exp_logo[<?= $i ?>]" value="<?= e($x['logo']) ?>">
            <label>Beskrivelse<textarea name="exp_text[]" rows="2"><?= e($x['text']) ?></textarea></label>
            <?php if ($x['logo']): ?><label class="check"><input type="checkbox" name="exp_logo_remove[<?= $i ?>]" value="1"> Fjern logo</label><?php endif; ?>
          </fieldset>
        <?php endforeach; ?>
        <div class="actions"><button class="btn">Gem profil</button></div>
      </form>
      <form method="post" id="fetch-logos"><?= csrf_field() ?><input type="hidden" name="action" value="fetch_logos"></form>

    <?php elseif ($view === 'settings'): ?>
      <?php if ($isOwner): ?>
        <section class="tiles">
          <a class="tile" href="./?view=profile"><span class="tile-ic"><?= icon('user') ?></span><span><strong>Profil & CV</strong><small>Navn, billede, erfaring og uddannelse</small></span><?= icon('right') ?></a>
          <a class="tile" href="./?view=projects"><span class="tile-ic"><?= icon('grid') ?></span><span><strong>Projekter</strong><small>Billeder og projekter på forsiden</small></span><?= icon('right') ?></a>
          <a class="tile" href="../" target="_blank"><span class="tile-ic"><?= icon('external') ?></span><span><strong>Se siden</strong><small>Åbn langkildelarsen.dk</small></span><?= icon('arrow') ?></a>
        </section>
      <?php endif; ?>
      <div class="settings-grid">
      <div class="cols">
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

      <?php $cals = load_json(ukey('calendars')); ?>
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
        <h3>Få dashboardets aftaler over på iPhone</h3>
        <p class="muted small">Aftaler du tilføjer på startsiden eller via Jarvis ligger i en "Jarvis"-kalender. Abonnér på den én gang, så dukker de op i din iPhone-kalender (opdateres hvert ~15. minut).</p>
        <?php $feedUrl = 'webcal://' . ($_SERVER['HTTP_HOST'] ?? 'langkildelarsen.dk') . '/api/calendar.php?k=' . ev_feed_key(); ?>
        <label>Dit kalender-link<input value="<?= e($feedUrl) ?>" readonly onfocus="this.select()"></label>
        <ol class="steps muted small">
          <li>Åbn linket herunder på din iPhone, eller gå til <strong>Indstillinger → Kalender → Konti → Tilføj konto → Andet → Tilføj abonnementskalender</strong> og indsæt linket.</li>
          <li>Tryk <strong>Abonnér</strong>.</li>
        </ol>
        <div class="actions"><a class="btn ghost" href="<?= e($feedUrl) ?>">Abonnér på iPhone</a></div>
      </form>

      <?php if ($isOwner): $ck = load_json('claude'); ?>
        <form method="post" class="panel form" id="claude">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_claude_key">
          <h2 class="section-title">Claude</h2>
          <p class="muted small">Indsæt din API-nøgle fra console.anthropic.com. Den gemmes kun på din server og bruges af både dig og andre brugere. Forbruget betales via din Anthropic-konto.</p>
          <?php if (!empty($ck['key'])): ?>
            <p class="small"><span class="st green"><i></i>Nøgle gemt</span> <span class="muted">slutter på …<?= e(substr($ck['key'], -4)) ?></span></p>
          <?php endif; ?>
          <label>API-nøgle<input type="password" name="key" placeholder="<?= !empty($ck['key']) ? 'Lad stå tomt for at beholde nøglen' : 'sk-ant-…' ?>" autocomplete="off"></label>
          <label>Model<select name="model"><?php foreach (CLAUDE_MODELS as $mid => $mlabel): ?><option value="<?= e($mid) ?>"<?= claude_model() === $mid ? ' selected' : '' ?>><?= e($mlabel) ?></option><?php endforeach; ?></select></label>
          <label>Maks. tokens pr. måned <small class="muted">0 = ingen grænse</small><input name="cap" inputmode="numeric" value="<?= e((string)claude_cap()) ?>"></label>
          <?php $cu = claude_month_usage(); $ctot = claude_month_total($cu); $ccap = claude_cap(); ?>
          <div class="usage-box">
            <div class="usage-top"><span>Forbrug i <?= e(dk_month(date('Y-m'))) ?></span><strong><?= e(number_format($ctot, 0, ',', '.')) ?><?= $ccap ? ' / ' . e(number_format($ccap, 0, ',', '.')) : '' ?> tokens</strong></div>
            <?php if ($ccap): ?><div class="usage-bar"><i style="width: <?= min(100, round($ctot / $ccap * 100)) ?>%"></i></div><?php endif; ?>
            <dl class="usage-grid">
              <div><dt>Samtaler</dt><dd><?= e(number_format($cu['chats'], 0, ',', '.')) ?></dd></div>
              <div><dt>Input</dt><dd><?= e(number_format($cu['in'], 0, ',', '.')) ?></dd></div>
              <div><dt>Output</dt><dd><?= e(number_format($cu['out'], 0, ',', '.')) ?></dd></div>
              <div><dt>Fra cache</dt><dd><?= e(number_format($cu['cache_read'], 0, ',', '.')) ?></dd></div>
            </dl>
            <?php if (count($cu['users']) > 1): ?><p class="muted small"><?= e(implode(' · ', array_map(fn($em, $n) => first_name(users_all()[$em] ?? ['name' => $em, 'email' => $em]) . ' ' . number_format($n, 0, ',', '.'), array_keys($cu['users']), $cu['users']))) ?></p><?php endif; ?>
          </div>
          <div class="actions">
            <button class="btn">Gem</button>
            <?php if (!empty($ck['key'])): ?><button class="btn ghost danger" name="remove" value="1">Fjern</button><?php endif; ?>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($isOwner): ?>
        <?php $allUsers = users_all(); $newLink = $_SESSION['invite_link'] ?? null; unset($_SESSION['invite_link']); ?>
        <section class="panel form users" id="brugere">
          <h2 class="section-title">Brugere</h2>
          <p class="muted small">Andre brugere får deres eget dashboard med eget budget, egne påmindelser, egen træning og egen kalender. De kan ikke se dine ting og kan ikke rette forsiden.</p>
          <ul class="user-list">
            <?php foreach ($allUsers as $u): ?>
              <li>
                <span class="initials"><?= e(initials($u)) ?></span>
                <span class="who"><strong><?= e($u['name']) ?></strong><small><?= e($u['email']) ?></small></span>
                <span class="st <?= $u['role'] === 'owner' ? 'green' : (empty($u['hash']) ? 'red' : 'green') ?>"><i></i><?= $u['role'] === 'owner' ? 'Ejer' : (empty($u['hash']) ? 'Inviteret' : 'Aktiv') ?></span>
                <?php if ($u['role'] !== 'owner'): ?>
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="user_invite"><input type="hidden" name="email" value="<?= e($u['email']) ?>"><button class="btn small ghost"><?= empty($u['hash']) ? 'Nyt link' : 'Nulstil kode' ?></button></form>
                  <details class="confirm">
                    <summary class="btn small ghost danger">Fjern</summary>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="remove_user"><input type="hidden" name="email" value="<?= e($u['email']) ?>"><button class="btn small danger">Ja, fjern</button></form>
                  </details>
                <?php endif; ?>
              </li>
              <?php if ($newLink && $newLink['email'] === $u['email']): ?>
                <li class="invite-link">
                  <label>Send dette link til <?= e(first_name($u)) ?> – det virker én gang i 7 dage<input value="<?= e($newLink['url']) ?>" readonly onfocus="this.select()"></label>
                  <a class="btn small" href="mailto:<?= e($u['email']) ?>?subject=<?= rawurlencode('Dit dashboard') ?>&body=<?= rawurlencode("Hej " . first_name($u) . "\n\nHer er dit link til at oprette din adgangskode:\n" . $newLink['url'] . "\n\nLinket virker i 7 dage.") ?>">Send på mail</a>
                </li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
          <form method="post" class="add-user">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_user">
            <input name="name" placeholder="Navn" aria-label="Navn" required>
            <input type="email" name="email" placeholder="Mail" aria-label="Mail" required>
            <button class="btn"><?= icon('plus') ?> Tilføj bruger</button>
          </form>
        </section>
      <?php endif; ?>

      <?php $api = load_json(ukey('api')); $newToken = $_SESSION['new_token'] ?? null; unset($_SESSION['new_token']); ?>
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

        <h3>"Hey Siri, Jarvis" – den der kan det hele</h3>
        <ol class="steps muted small">
          <li>Åbn <strong>Genveje</strong> på iPhone → <strong>+</strong> → giv den navnet <strong>Jarvis</strong>.</li>
          <li>Tilføj handlingen <strong>Diktér tekst</strong> (sprog: Dansk).</li>
          <li>Tilføj <strong>Hent indhold af URL</strong> med adressen <code><?= e($base) ?></code>. Tryk på pilen: Metode <strong>POST</strong>, Anmodningstekst <strong>Formular</strong>, og tilføj tre felter: <code>token</code> = din nøgle, <code>action</code> = <code>jarvis</code>, <code>text</code> = <strong>Dikteret tekst</strong>.</li>
          <li>Tilføj <strong>Læs tekst op</strong>, så Siri læser Jarvis' svar højt.</li>
        </ol>
        <p class="muted small">Sig fx "Hey Siri, Jarvis" → "mind mig om at ringe til tandlægen fredag kl. 9", "jeg har trænet i dag", "hvad skal jeg i morgen?" eller "hvor meget har jeg tilbage om måneden?".</p>

        <h3>Hurtige genveje uden at tale</h3>
        <ol class="steps muted small">
          <li><strong>Jeg har trænet</strong>: Hent indhold af URL med <code>token</code> og <code>action</code> = <code>træning</code> → Læs tekst op. Logger dagens træning med det samme.</li>
          <li><strong>Husk</strong>: Diktér tekst → Hent indhold af URL med <code>token</code>, <code>action</code> = <code>påmindelse</code> og <code>text</code> = Dikteret tekst → Læs tekst op.</li>
          <li><strong>Min dag</strong> / <strong>Mit budget</strong>: <code>token</code> og <code>action</code> = <code>agenda</code> eller <code>budget</code> → Læs tekst op.</li>
        </ol>
      </div>

      <?php $tr = training_load(); ?>
      <form method="post" class="panel form" id="traening">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_training">
        <h2 class="section-title">Træning</h2>
        <p class="muted small">Hvor mange gange om ugen du vil træne. Du kan også logge via Jarvis, fx "jeg har trænet i dag".</p>
        <label>Træninger pr. uge<input type="number" name="week_goal" min="1" max="7" value="<?= (int)$tr['week_goal'] ?>"></label>
        <div class="actions"><button class="btn">Gem</button></div>
      </form>

      <form method="post" enctype="multipart/form-data" class="panel form" id="baggrund" data-reload>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_background">
        <h2 class="section-title">Baggrund</h2>
        <p class="muted small">Et billede bag hele dashboardet. Kortene bliver til mat glas med blur ovenpå.</p>
        <?php if ($hasBg): ?><div class="bg-preview" style="background-image: url('../uploads/<?= e($bgFile) ?>')"></div><?php endif; ?>
        <label>Billede (JPG, PNG eller WebP)<input type="file" name="bg" accept="image/jpeg,image/png,image/webp"></label>
        <label>Blur på baggrundsbilledet <span class="muted small" data-out="bg_img_blur"><?= (int)($ui['bg_img_blur'] ?? 0) ?>px</span><input type="range" name="bg_img_blur" min="0" max="40" value="<?= (int)($ui['bg_img_blur'] ?? 0) ?>" data-live="--img-blur" data-unit="px"></label>
        <label>Sort overlay på baggrundsbilledet <span class="muted small" data-out="bg_dim"><?= (int)($ui['bg_dim'] ?? 45) ?>%</span><input type="range" name="bg_dim" min="0" max="85" value="<?= (int)($ui['bg_dim'] ?? 45) ?>" data-live="--bg-dim" data-scale="100"></label>
        <label>Kortenes gennemsigtighed <span class="muted small" data-out="bg_card"><?= (int)($ui['bg_card'] ?? 42) ?>%</span><input type="range" name="bg_card" min="0" max="95" value="<?= (int)($ui['bg_card'] ?? 42) ?>" data-live="--glass-a" data-scale="100"></label>
        <label>Knapper og felter <span class="muted small" data-out="bg_el"><?= (int)($ui['bg_el'] ?? 7) ?>%</span><input type="range" name="bg_el" min="0" max="40" value="<?= (int)($ui['bg_el'] ?? 7) ?>" data-live="--el-a" data-scale="100"></label>
        <label>Blur på glasset <span class="muted small" data-out="bg_blur"><?= (int)($ui['bg_blur'] ?? 24) ?>px</span><input type="range" name="bg_blur" min="0" max="60" value="<?= (int)($ui['bg_blur'] ?? 24) ?>" data-live="--glass-blur" data-unit="px"></label>
        <div class="actions">
          <button class="btn">Gem</button>
          <?php if ($hasBg): ?><button class="btn ghost danger" name="reset" value="1">Fjern baggrund</button><?php endif; ?>
        </div>
      </form>

      <form method="post" class="panel form" id="vejr">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_weather">
        <h2 class="section-title">Vejr</h2>
        <p class="muted small">Vises på oversigten. Vejrdata kommer fra Open-Meteo og opdateres hvert 20. minut.</p>
        <label>By<input name="city" value="<?= e(weather_place()['name']) ?>" placeholder="Fx Esbjerg" autocomplete="off"></label>
        <div class="actions"><button class="btn">Gem</button></div>
      </form>

      <form method="post" enctype="multipart/form-data" class="panel form" id="opstart" data-reload>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_startup">
        <h2 class="section-title">Opstart</h2>
        <p class="muted small">Startanimationen vises, når du åbner Jarvis. Uploader du en mp3, afspilles den samtidig. Lyden gemmes kun på serveren.</p>
        <label class="check"><input type="checkbox" name="splash" value="1"<?= ($ui['splash'] ?? true) ? ' checked' : '' ?>> Vis startanimation</label>
        <?php if (is_file(sound_path())): ?>
          <audio controls preload="none" src="index.php?ajax=sound&amp;v=<?= filemtime(sound_path()) ?>" class="sound-preview"></audio>
        <?php endif; ?>
        <label>Startlyd (mp3)<input type="file" name="sound" accept="audio/mpeg,.mp3"></label>
        <div class="actions">
          <button class="btn">Gem</button>
          <button type="button" class="btn ghost" data-splash-run>Vis animationen nu</button>
          <?php if (is_file(sound_path())): ?><button class="btn ghost danger" name="remove_sound" value="1">Fjern lyd</button><?php endif; ?>
        </div>
      </form>

      <section class="panel push-panel" id="notifikationer" data-push>
        <h2 class="section-title">Notifikationer</h2>
        <p class="muted small">Få besked på telefonen og i Chrome, når der kommer en ny besked – også når Jarvis er lukket. Slå det til på hver enhed for sig.</p>
        <p class="push-status small" data-push-status>Tjekker…</p>
        <div class="actions">
          <button type="button" class="btn" data-push-on hidden>Slå notifikationer til</button>
          <button type="button" class="btn ghost" data-push-test hidden>Send en test</button>
          <button type="button" class="btn ghost" data-push-off hidden>Slå fra på denne enhed</button>
        </div>
      </section>

      <div class="panel">
        <h2 class="section-title">Admin som app på iPhone</h2>
        <ol class="steps muted small">
          <li>Åbn <strong>langkildelarsen.dk/admin</strong> i Safari på din iPhone og log ind.</li>
          <li>Tryk på <strong>Del</strong>-ikonet (firkant med pil op).</li>
          <li>Vælg <strong>Føj til hjemmeskærm</strong> → <strong>Tilføj</strong>.</li>
        </ol>
        <p class="muted small">Så åbner Jarvis i fuld skærm som en app, og du forbliver logget ind i 60 dage.</p>
      </div>
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

    <?php elseif ($view === 'projects'): ?>
      <div class="panel-head">
        <p class="muted small">Vises på forsiden under Udvalgte projekter. Billeder hentes fra Dribbble – eller upload dit eget.</p>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="fetch_projects"><button class="btn ghost">Hent billeder fra Dribbble</button></form>
      </div>
      <form method="post" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_projects">
        <div class="project-grid">
          <?php foreach (array_merge($profile['projects'], array_fill(0, 2, ['title' => '', 'url' => '', 'image' => '', 'show' => true])) as $i => $pr): ?>
            <fieldset class="project-edit panel">
              <div class="thumb"><?php if ($pr['image']): ?><img src="<?= e(img_src($pr['image'], '../')) ?>" alt=""><?php else: ?><span><?= $pr['title'] ? 'Intet billede' : 'Nyt projekt' ?></span><?php endif; ?></div>
              <input name="pr_title[<?= $i ?>]" value="<?= e($pr['title']) ?>" placeholder="Titel" aria-label="Titel">
              <input name="pr_url[<?= $i ?>]" value="<?= e($pr['url']) ?>" placeholder="https://dribbble.com/shots/…" aria-label="Link">
              <input type="hidden" name="pr_image[<?= $i ?>]" value="<?= e($pr['image']) ?>">
              <input type="file" name="pr_file[<?= $i ?>]" accept="image/png,image/jpeg,image/webp,image/gif" aria-label="Billede">
              <label class="check"><input type="checkbox" name="pr_show[<?= $i ?>]" value="1" <?= $pr['show'] ? 'checked' : '' ?>> Vis på forsiden</label>
            </fieldset>
          <?php endforeach; ?>
        </div>
        <div class="actions sticky"><button class="btn">Gem projekter</button></div>
      </form>

    <?php elseif ($view === 'messages'): ?>
      <?php
        $others = array_values(array_filter(users_all(), fn($u) => $u['email'] !== $me['email']));
        $with = mb_strtolower((string)($_GET['with'] ?? ($others[0]['email'] ?? '')));
        $withUser = users_all()[$with] ?? null;
        if ($withUser && $with !== $me['email']) dm_mark_read($me['email'], $with);
      ?>
      <?php if (!$others): ?>
        <div class="empty"><p>Der er ingen andre brugere endnu.</p><?php if ($isOwner): ?><a href="./?view=settings#brugere" class="btn">Tilføj en bruger</a><?php endif; ?></div>
      <?php else: ?>
        <div class="dm">
          <aside class="card dm-people">
            <?php foreach ($others as $o): $last = array_slice(dm_thread($me['email'], $o['email']), -1)[0] ?? null; $un = dm_unread($me['email'], $o['email']); ?>
              <a href="./?view=messages&with=<?= rawurlencode($o['email']) ?>" class="dm-person<?= $o['email'] === $with ? ' on' : '' ?>">
                <span class="initials"><?= e(initials($o)) ?></span>
                <span class="dm-who"><strong><?= e($o['name']) ?></strong><small><?= $last ? e(($last['from'] === $me['email'] ? 'Dig: ' : '') . mb_strimwidth($last['text'], 0, 40, '…')) : 'Ingen beskeder endnu' ?></small></span>
                <?php if ($un): ?><span class="dm-count"><?= $un ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </aside>
          <?php if ($withUser): ?>
            <section class="card dm-thread" data-thread="<?= e($with) ?>">
              <div class="card-head"><span class="initials"><?= e(initials($withUser)) ?></span><h2><?= e($withUser['name']) ?></h2></div>
              <div class="dm-list" id="dm-list">
                <?php $lastDay = null; foreach (array_slice(dm_thread($me['email'], $with), -200) as $m): $pm = dm_public($m, $me['email']); ?>
                  <?php if ($pm['day'] !== $lastDay): $lastDay = $pm['day']; ?><p class="dm-day"><?= e($pm['label']) ?></p><?php endif; ?>
                  <div class="bubble<?= $pm['mine'] ? ' mine' : '' ?><?= $pm['type'] === 'request' ? ' request' : '' ?>"><?php if ($pm['type'] === 'request'): ?><span class="req-tag"><?= icon('sparkle') ?> Ønske via Jarvis</span><?php endif; ?><p><?= nl2br(e($pm['text'])) ?></p><time><?= e($pm['time']) ?></time></div>
                <?php endforeach; ?>
              </div>
              <form method="post" class="ask dm-compose" data-dm-form>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="to" value="<?= e($with) ?>">
                <input name="text" placeholder="Skriv til <?= e(first_name($withUser)) ?>…" autocomplete="off" required>
                <button aria-label="Send"><?= icon('send') ?></button>
              </form>
            </section>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    <?php elseif ($view === 'chat'): ?>
      <?php $history = load_json(ukey('chat')); $hasKey = claude_key() !== ''; ?>
      <div class="jv-layout">
      <aside class="card jv" data-jv data-s="idle" aria-live="polite">
        <div class="jv-top">
          <span class="jv-name">Jarvis</span>
          <span class="jv-state"><i></i><span data-jv-label>Klar</span></span>
        </div>
        <?= jarvis_orb() ?>
        <p class="jv-log" data-jv-log><span>Klar.</span> Skriv eller tal til mig.</p>
      </aside>
      <section class="card chat" data-chat>
        <div class="card-head">
          <h2 class="chat-h" aria-label="Jarvis"></h2>
          <span class="chat-note" data-chat-note role="status" hidden></span>
          <button type="button" class="round" data-voice-toggle aria-label="Læs svar højt" aria-pressed="false"><span class="i-vol"><?= icon('vol2') ?></span><span class="i-mute"><?= icon('mute') ?></span></button>
          <button type="button" class="round" data-voice-settings aria-label="Stemme"><?= icon('gear') ?></button>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clear_chat"><button class="round" aria-label="Ryd samtale" title="Ryd samtale"><?= icon('cross') ?></button></form>
        </div>
        <div class="voice-pop" hidden>
          <label>Stemme<select data-voice-select><option value="">Standard</option></select></label>
          <label>Tempo <input type="range" min="0.7" max="1.3" step="0.05" value="1" data-voice-rate></label>
          <label>Tonehøjde <input type="range" min="0.6" max="1.4" step="0.05" value="1" data-voice-pitch></label>
          <button type="button" class="btn small ghost" data-voice-test>Afprøv stemmen</button>
        </div>
        <div class="chat-list" id="chat-list">
          <?php if (!$history): ?>
            <div class="chat-hello">
              <p>Hej <?= e(first_name($me)) ?>. Spørg mig om din dag, din træning, dit budget – eller bed mig minde dig om noget.</p>
              <div class="chips-q">
                <button type="button" data-q="Hvor meget har jeg tilbage om måneden?">Hvor meget har jeg tilbage?</button>
                <button type="button" data-q="Hvad skal jeg de næste dage?">Hvad skal jeg de næste dage?</button>
                <button type="button" data-q="Mind mig om ">Ny påmindelse</button>
              </div>
            </div>
          <?php endif; ?>
          <?php foreach (array_slice($history, -40) as $h): ?>
            <div class="bubble<?= $h['role'] === 'user' ? ' mine' : ' ai' ?>"><p><?= nl2br(e($h['text'])) ?></p></div>
          <?php endforeach; ?>
        </div>
        <?php if (!$hasKey): ?>
          <p class="insight"><?= icon('info') ?><span><?= $isOwner ? 'Jarvis er ikke sat op endnu. Indsæt din API-nøgle under Indstillinger → Claude.' : 'Jarvis er ikke sat op endnu. Bed ' . e(first_name(array_values(array_filter(users_all(), fn($u) => $u['role'] === 'owner'))[0] ?? ['name' => 'ejeren', 'email' => ''])) . ' om at sætte det op.' ?></span></p>
        <?php endif; ?>
        <form class="ask chat-compose" data-chat-form>
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="chat">
          <button type="button" class="mic" data-mic aria-label="Tal til Jarvis"><?= icon('mic') ?></button>
          <input name="text" placeholder="Skriv eller tal til Jarvis…" autocomplete="off" <?= $hasKey ? '' : 'disabled' ?>>
          <button aria-label="Send" <?= $hasKey ? '' : 'disabled' ?>><?= icon('send') ?></button>
        </form>
      </section>
      </div>

    <?php else: ?>
      <div class="empty"><p>Siden findes ikke.</p><a href="./" class="btn">Til oversigten</a></div>
    <?php endif; ?>
  </main>
</div>
<?php $music = ($ui['music'] ?? null) ?: ['v' => 'byOVTxnILMU', 'list' => 'RDbyOVTxnILMU', 'url' => 'https://www.youtube.com/watch?v=byOVTxnILMU&list=RDbyOVTxnILMU']; ?>
<?php if (($ui['splash'] ?? true)): ?>
<div class="boot" id="boot" hidden data-sound="<?= is_file(sound_path()) ? 'index.php?ajax=sound&amp;v=' . filemtime(sound_path()) : '' ?>" data-name="<?= e(first_name($me)) ?>">
  <div class="boot-frame" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
  <div class="boot-hud boot-tl" aria-hidden="true">
    <span class="boot-tag">JARVIS OS 5.5</span>
    <div class="boot-log" data-boot-log></div>
  </div>
  <div class="boot-hud boot-tr" aria-hidden="true">
    <div class="boot-clock" data-boot-clock>00:00:00</div>
    <div data-boot-date></div>
    <div>LANGKILDELARSEN.DK</div>
    <div>UPLINK <span class="v">SECURE</span><span class="boot-sig"><i></i><i></i><i></i><i></i></span></div>
  </div>
  <div class="boot-hud boot-bl" aria-hidden="true">
    <div class="boot-stat"><span>CPU</span><span class="b"><i data-stat="cpu"></i></span><span data-stat-v="cpu">0%</span></div>
    <div class="boot-stat"><span>MEM</span><span class="b"><i data-stat="mem"></i></span><span data-stat-v="mem">0%</span></div>
    <div class="boot-stat"><span>NET</span><span class="b"><i data-stat="net"></i></span><span data-stat-v="net">0%</span></div>
    <div class="boot-stat"><span>KAFFE</span><span class="b"><i data-stat="cof"></i></span><span data-stat-v="cof">0%</span></div>
    <svg class="boot-spark" viewBox="0 0 100 30" preserveAspectRatio="none"><polyline data-boot-spark points=""/></svg>
  </div>
  <div class="boot-hud boot-br" aria-hidden="true">
    <div>MEMORY DUMP</div>
    <div class="boot-hex" data-boot-hex></div>
    <div>SEKTOR <span class="v" data-boot-sec>7G</span> · TEMP <span class="v" data-boot-tmp>36.6°</span></div>
  </div>
  <div class="boot-in">
    <?= jarvis_orb('boot-orb') ?>
    <p class="boot-name">JARVIS</p>
    <div class="boot-bar"><i></i></div>
    <div class="boot-row"><span data-boot-pct>000%</span><span data-boot-step>0/6</span></div>
    <p class="boot-text" data-boot-text></p>
    <button type="button" class="boot-tap" data-boot-tap hidden>Tryk for at starte</button>
  </div>
</div>
<?php endif; ?>
<div class="sx" id="sx" hidden>
  <div class="sx-box" role="dialog" aria-modal="true" aria-label="Søg">
    <label class="sx-field"><?= icon('search') ?><input id="sx-q" type="search" placeholder="Søg i påmindelser, budget og sider…" autocomplete="off" spellcheck="false"><kbd>esc</kbd></label>
    <ul class="sx-list" id="sx-list" role="listbox"></ul>
  </div>
</div>
<div class="mp-bg" aria-hidden="true"><div id="mp-yt"></div></div>
<section class="mp" id="mp" hidden aria-label="Musikafspiller" data-v="<?= e($music['v']) ?>" data-list="<?= e($music['list']) ?>">
  <div class="mp-head" data-drag>
    <span class="mp-label">Pladespiller</span>
    <button type="button" class="mp-x mp-link" data-mp-link aria-label="Skift YouTube-link" aria-expanded="false"><?= icon('drag') ?></button>
    <button type="button" class="mp-x" data-player-close aria-label="Luk afspilleren"><?= icon('cross') ?></button>
  </div>
  <form class="mp-url" id="mp-url" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_music">
    <input name="url" value="<?= e($music['url'] ?? '') ?>" placeholder="Indsæt YouTube-link til video eller playliste" aria-label="YouTube-link" autocomplete="off">
    <button class="btn small">Brug</button>
    <p class="mp-err" id="mp-err" hidden></p>
  </form>
  <div class="mp-screen">
    <span class="mp-ph"><?= icon('music') ?></span>
    <img class="mp-vinyl" src="../assets/brand/vinyl.webp" alt="" width="560" height="560">
  </div>
  <p class="mp-title" id="mp-title">YouTube Mix</p>
  <input type="range" class="mp-seek" id="mp-seek" min="0" max="1000" value="0" aria-label="Spol">
  <div class="mp-times"><span id="mp-cur">0:00</span><span id="mp-dur">0:00</span></div>
  <div class="mp-ctrl">
    <button type="button" class="mp-b" data-mp="prev" aria-label="Forrige"><?= icon('prev') ?></button>
    <button type="button" class="mp-b big" data-mp="toggle" aria-label="Afspil"><span class="i-play"><?= icon('play') ?></span><span class="i-pause"><?= icon('pause') ?></span></button>
    <button type="button" class="mp-b" data-mp="next" aria-label="Næste"><?= icon('next') ?></button>
  </div>
  <div class="mp-vol">
    <button type="button" class="mp-b small" data-mp="mute" aria-label="Slå lyd fra"><span class="i-vol"><?= icon('vol2') ?></span><span class="i-mute"><?= icon('mute') ?></span></button>
    <input type="range" id="mp-volume" class="mp-volume" min="0" max="100" value="60" aria-label="Lydstyrke">
  </div>
</section>
<div class="update-toast" id="update-toast" hidden role="status">
  <span class="u-dot" aria-hidden="true"></span>
  <span>Der er en ny version af Jarvis</span>
  <button type="button" class="btn small" data-update>Opdater</button>
</div>
<aside class="install" id="install" hidden>
  <img src="../assets/brand/dash-180.png?v=ball2" alt="" class="install-ic">
  <div class="install-txt">
    <strong>Læg Jarvis på hjemmeskærmen</strong>
    <span class="ios-only">Tryk på <b class="share-ic" aria-label="Del"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15V3M12 3L8 7M12 3l4 4M8 11H6.5C5.12 11 4 12.12 4 13.5v4C4 18.88 5.12 20 6.5 20h11c1.38 0 2.5-1.12 2.5-2.5v-4c0-1.38-1.12-2.5-2.5-2.5H16"/></svg></b> og vælg <b>Føj til hjemmeskærm</b>.</span>
    <span class="other-only">Så åbner det i fuld skærm som en app, og du forbliver logget ind.</span>
  </div>
  <button class="btn small install-btn" id="install-btn" hidden>Installér</button>
  <button class="install-x" id="install-x" aria-label="Luk"><?= icon('plus') ?></button>
</aside>
<script>
(() => {
  const box = document.getElementById('install');
  if (!box) return;
  const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const mobile = matchMedia('(max-width: 900px)').matches && matchMedia('(pointer: coarse)').matches;
  let dismissed = false;
  try { dismissed = (Number(localStorage.getItem('install-dismissed')) || 0) > Date.now() - 14 * 864e5; } catch (e) {}
  if (standalone || !mobile || dismissed) return;
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  box.classList.add(ios ? 'is-ios' : 'is-other');
  box.hidden = false;
  requestAnimationFrame(() => box.classList.add('show'));
  let deferred = null;
  addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    deferred = e;
    document.getElementById('install-btn').hidden = false;
  });
  document.getElementById('install-btn').addEventListener('click', async () => {
    if (!deferred) return;
    deferred.prompt();
    await deferred.userChoice;
    deferred = null;
    box.remove();
  });
  document.getElementById('install-x').addEventListener('click', () => {
    try { localStorage.setItem('install-dismissed', String(Date.now())); } catch (e) {}
    box.classList.remove('show');
    setTimeout(() => box.remove(), 400);
  });
})();
</script>
</body>
</html>
