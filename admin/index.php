<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/budget.php';
require dirname(__DIR__) . '/inc/calendar.php';
require dirname(__DIR__) . '/inc/media.php';
require dirname(__DIR__) . '/inc/messages.php';
require dirname(__DIR__) . '/inc/claude.php';

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
<link rel="manifest" href="manifest.webmanifest">
<link rel="apple-touch-icon" href="../assets/brand/dash-180.png">
<link rel="icon" type="image/png" sizes="96x96" href="../assets/brand/fav-96.png">
<link rel="icon" type="image/png" sizes="32x32" href="../assets/brand/fav-32.png">';
}

function default_categories(): array
{
    return ['Tech', 'Hjem', 'Mad', 'Økonomi', 'Produktivitet', 'Rejser'];
}

function greeting(): array
{
    $h = (int)date('G');
    if ($h < 5) return ['God nat', '🌙'];
    if ($h < 10) return ['Godmorgen', '☀️'];
    if ($h < 12) return ['God formiddag', '☕'];
    if ($h < 18) return ['God eftermiddag', '👋'];
    if ($h < 22) return ['God aften', '🌆'];
    return ['God nat', '🌙'];
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
</head>
<body class="auth">
<form method="post" class="auth-box" action="index.php">
  <?= csrf_field() ?>
  <img src="../assets/brand/dash-192.png" alt="" class="auth-logo">
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
            $saved = save_upload($_FILES['bg'] ?? [], 'backgrounds', 'bg-' . bin2hex(random_bytes(3)));
            if ($saved) {
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

if (($_GET['ajax'] ?? '') === 'unread') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['count' => dm_unread($me['email'])]);
    exit;
}

if (($_GET['ajax'] ?? '') === 'search') {
    $items = [];
    $pages = [['Oversigt', './'], ['Lifehacks', './?view=hacks'], ['Ny lifehack', './?view=edit'], ['Budget', './?view=budget'], ['Rediger budget', './?view=budget&edit=1'], ['Kalender', './?view=calendar'], ['Indstillinger', './?view=settings']];
    if ($isOwner) {
        array_push($pages, ['Profil & CV', './?view=profile'], ['Projekter', './?view=projects']);
    }
    foreach ($pages as [$t, $u]) {
        $items[] = ['type' => 'Side', 'title' => $t, 'sub' => '', 'url' => $u, 'text' => $t];
    }
    foreach ($hacks as $h) {
        $items[] = ['type' => 'Lifehack', 'title' => $h['title'], 'sub' => $h['category'], 'url' => './?view=hack&id=' . $h['id'], 'text' => $h['title'] . ' ' . $h['content'] . ' ' . implode(' ', $h['tags']) . ' ' . $h['category']];
    }
    foreach ($categories as $c) {
        $items[] = ['type' => 'Kategori', 'title' => $c, 'sub' => ($counts[$c] ?? 0) . ' lifehacks', 'url' => './?view=hacks&cat=' . rawurlencode($c), 'text' => $c];
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
?><!doctype html>
<html lang="da" data-version="<?= e(app_version()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050506">
<?= app_head() ?>
<title>Jarvis</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
<script src="../assets/admin.js?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.js') ?>" defer></script>
</head>
<body>
<div class="app">
  <aside class="rail" aria-label="Menu">
    <a href="./" class="rail-logo" aria-label="Oversigt"><img src="../assets/brand/dash-mark.png" alt=""></a>
    <nav class="rail-nav">
      <span class="rail-pill" aria-hidden="true"></span>
      <?php
        $railItems = [
            ['dashboard', './', 'home', 'Oversigt'],
            ['hacks', './?view=hacks', 'bulb', 'Lifehacks'],
            ['budget', './?view=budget', 'wallet', 'Budget'],
            ['calendar', './?view=calendar', 'calendar', 'Kalender'],
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
      <h1><?php if ($view === 'dashboard'): ?><?= e($greet . ', ' . first_name($me)) ?><?php else: ?><?= e($pageTitle) ?><?php endif; ?></h1>
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
      <a class="me" href="./?view=settings" aria-label="<?= e($me['name']) ?>" title="<?= e($me['name'] . ' · ' . $me['email']) ?>">
        <?php if ($isOwner): ?><img src="<?= e($profile['photo'] ? '../uploads/' . $profile['photo'] : '../assets/brand/avatar-round.png') ?>" alt=""><?php else: ?><span class="initials"><?= e(initials($me)) ?></span><?php endif; ?>
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
        foreach ($cal['events'] as $ev) $marks[date('Y-m-d', $ev['start'])] = 'm-ev';
        foreach (upcoming_payments($budget, 62) as $pp) $marks[date('Y-m-d', $pp['ts'])] = $marks[date('Y-m-d', $pp['ts'])] ?? 'm-pay';

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
        if ($sum['income'] > 0) {
            $summary[] = $sum['left'] >= 0
                ? 'Du har ' . $fmt($sum['left']) . ' kr. tilbage om måneden, når de faste udgifter er betalt.'
                : 'Dine faste udgifter er ' . $fmt(abs($sum['left'])) . ' kr. højere end din indkomst om måneden.';
            if ($topCat) {
                $summary[] = $topCat . ' er den største post med ' . $fmt($sum['by_cat'][$topCat]) . ' kr. – ' . round($sum['by_cat'][$topCat] / max(1, $sum['expenses']) * 100) . ' % af udgifterne.';
            }
            $summary[] = $sum['saving'] > 0 ? 'Du sparer ' . round($sum['saving_rate']) . ' % op hver måned.' : 'Der er ikke sat penge af til opsparing endnu.';
        } else {
            $summary[] = 'Læg dit budget ind, så giver jeg dig et overblik over din økonomi her.';
        }
        if ($hasCal) {
            $summary[] = $next7 ? "Du har $next7 aftale" . ($next7 === 1 ? '' : 'r') . ' de næste 7 dage.' : 'Kalenderen er tom de næste 7 dage.';
        }
      ?>
      <div class="dash">
        <section class="card c-budget">
          <input type="radio" name="per" id="per-m" class="per" checked>
          <input type="radio" name="per" id="per-q" class="per">
          <input type="radio" name="per" id="per-y" class="per">
          <div class="card-head">
            <h2>Budget</h2>
            <div class="seg" role="group" aria-label="Periode">
              <?php foreach ($periods as $k => [$label]): ?><label for="per-<?= $k ?>"><?= e($label) ?></label><?php endforeach; ?>
            </div>
            <a href="./?view=budget" class="round" aria-label="Åbn budget"><?= icon('arrow') ?></a>
          </div>
          <?php if ($sum['income'] > 0): ?>
            <?php foreach ($periods as $k => [$label, $f]): ?>
              <?php $top = $niceMax(($catBars ? max($catBars) : 0) * $f); ?>
              <div class="pv pv-<?= $k ?>">
                <div class="figure">
                  <p class="money"><?= e($fmt($sum['left'] * $f)) ?> <span class="cur">kr.</span></p>
                  <span class="badge <?= $sum['left'] < 0 ? 'bad' : 'good' ?>"><?= $sum['left'] < 0 ? '▼' : '▲' ?> <?= abs($leftPct) ?>%</span>
                  <span class="vs">tilbage af <?= e($fmt($sum['income'] * $f)) ?> kr. i indkomst</span>
                </div>
                <div class="chart">
                  <div class="axis"><?php foreach ([1, .75, .5, .25, 0] as $t): ?><span><?= e($fmt($top * $t)) ?></span><?php endforeach; ?></div>
                  <div class="barcols">
                    <?php foreach ($catBars as $c => $v): ?>
                      <?php $h = $top > 0 ? $v * $f / $top * 100 : 0; ?>
                      <div class="col<?= $c === $topCat ? ' hot' : '' ?>">
                        <div class="track"><div class="fill" style="height: <?= max(4, round($h, 1)) ?>%">
                          <?php if ($c === $topCat): ?>
                            <span class="flag"><?= round($v / max(1, $sum['expenses']) * 100) ?>%</span>
                            <span class="amt"><?= e($fmt($v * $f)) ?></span>
                          <?php endif; ?>
                        </div></div>
                        <small title="<?= e($c) ?>"><?= e(mb_strimwidth($c, 0, 11, '…')) ?></small>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-card"><p>Læg dine faste indtægter og udgifter ind for at se dit budget her.</p><a href="./?view=budget" class="btn">Opsæt budget</a></div>
          <?php endif; ?>
        </section>

        <section class="card c-cal">
          <div class="cal-head">
            <a href="./?cm=<?= $prevM ?>" class="round" aria-label="Forrige måned"><?= icon('left') ?></a>
            <h2><?= e(ucfirst($months[(int)date('n', $firstDay) - 1]) . ' ' . date('Y', $firstDay)) ?></h2>
            <a href="./?cm=<?= $nextM ?>" class="round" aria-label="Næste måned"><?= icon('right') ?></a>
          </div>
          <div class="cal-grid">
            <?php foreach (['M', 'T', 'O', 'T', 'F', 'L', 'S'] as $d): ?><span class="dow"><?= $d ?></span><?php endforeach; ?>
            <?php for ($i = 0; $i < $lead; $i++): ?><span class="day off"></span><?php endfor; ?>
            <?php for ($d = 1; $d <= $dim; $d++): $key = $cm . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT); ?>
              <a class="day<?= $key === date('Y-m-d') ? ' today' : '' ?><?= isset($marks[$key]) ? ' mark ' . $marks[$key] : '' ?>" href="./?view=calendar"><?= $d ?></a>
            <?php endfor; ?>
            <?php for ($i = ($lead + $dim) % 7; $i && $i < 7; $i++): ?><span class="day off"></span><?php endfor; ?>
          </div>
          <a class="kpi" href="./?view=budget">
            <span class="kpi-ic"><?= icon('chart') ?></span>
            <span class="kpi-v"><?= e($fmt($sum['expenses'])) ?> <small>kr.</small></span>
            <span class="kpi-l">faste udgifter pr. md.</span>
          </a>
        </section>

        <section class="card c-ai">
          <div class="card-head">
            <h2>Overblik</h2>
            <a href="./?view=hacks" class="round" aria-label="Alle lifehacks"><?= icon('arrow') ?></a>
          </div>
          <h3>Dit overblik i dag</h3>
          <p class="ai-text"><?= e(implode(' ', $summary)) ?></p>
          <div class="minis">
            <a class="mini" href="./?view=hacks"><span>Lifehacks</span><strong><?= $total ?> <em class="pill-s orange">Gemt</em></strong></a>
            <a class="mini" href="./?view=calendar"><span>Aftaler</span><strong><?= $next7 ?> <em class="pill-s green">7 dage</em></strong></a>
          </div>
          <form method="post" class="ask">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="quick_hack">
            <input name="text" placeholder="Gem en ny lifehack…" aria-label="Ny lifehack" autocomplete="off">
            <button aria-label="Gem"><?= icon('send') ?></button>
          </form>
        </section>

        <section class="card c-spend">
          <div class="card-head">
            <h2>Forbrug</h2>
            <span class="drop">Pr. måned</span>
          </div>
          <?php if ($donut): ?>
            <div class="donut-wrap">
              <div class="donut">
                <svg viewBox="0 0 120 120" aria-hidden="true">
                  <?php foreach ($donut as $seg): $len = max(0, $seg['pct'] + (count($donut) > 1 ? .15 : 0)); ?>
                    <circle cx="60" cy="60" r="48" pathLength="100" stroke="<?= $seg['color'] ?>" stroke-dasharray="<?= round($len, 2) ?> <?= round(100 - $len, 2) ?>" stroke-dashoffset="<?= round(25 - $seg['start'], 2) ?>"><title><?= e($seg['name'] . ': ' . kr($seg['value'])) ?></title></circle>
                  <?php endforeach; ?>
                </svg>
                <div class="donut-c"><strong><?= e($fmt($sum['expenses'])) ?> kr.</strong><span>I alt</span></div>
              </div>
              <ul class="legend">
                <?php foreach ($donut as $seg): ?><li><i style="background: <?= $seg['color'] ?>"></i><?= e($seg['name']) ?><span><?= round($seg['pct']) ?>%</span></li><?php endforeach; ?>
              </ul>
            </div>
            <p class="insight"><?= icon('info') ?><span>De fleste penge går til <?= e(implode(' og ', array_slice(array_column($donut, 'name'), 0, 2))) ?>.</span></p>
          <?php else: ?>
            <div class="empty-card"><p>Ingen udgifter endnu.</p><a href="./?view=budget" class="btn ghost">Tilføj udgifter</a></div>
          <?php endif; ?>
        </section>

        <section class="card c-next">
          <div class="card-head">
            <h2>Kommende</h2>
            <a href="./?view=budget&edit=1" class="round" aria-label="Tilføj betaling"><?= icon('plus') ?></a>
          </div>
          <div class="score">
            <span>Budget brugt</span>
            <span class="ticks" style="--on: <?= round($usedPct * 40 / 100) ?>"><?php for ($t = 0; $t < 40; $t++): ?><i class="<?= $t < round($usedPct * 40 / 100) ? 'on' : '' ?>"></i><?php endfor; ?></span>
            <strong><?= $usedPct ?></strong>
          </div>
          <?php if ($items): ?>
            <ul class="rows-next">
              <?php foreach (array_slice($items, 0, 4) as $it): $days = (int)round((strtotime(date('Y-m-d', $it['ts'])) - strtotime('today')) / 86400); ?>
                <li>
                  <span class="when"><strong><?= e(preg_replace('/\s\d{4}$/', '', dk_date(date('c', $it['ts'])))) ?></strong><small><?= $days === 0 ? 'i dag' : ($days === 1 ? 'i morgen' : "om $days dage") ?></small></span>
                  <span class="st <?= $it['kind'] === 'pay' ? 'red' : 'green' ?>"><i></i><?= $it['kind'] === 'pay' ? 'Betaling' : 'Aftale' ?></span>
                  <span class="nm"><?= e($it['name']) ?></span>
                  <span class="val"><?= e($it['value']) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="muted small">Intet de næste 30 dage. Sæt betalingsdage på dine udgifter i budgettet<?= $hasCal ? '' : ', eller forbind din iPhone-kalender' ?>.</p>
          <?php endif; ?>
          <a class="see" href="./?view=calendar">Se kalender <?= icon('arrow') ?></a>
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
          <p class="muted small">Andre brugere får deres eget dashboard med eget budget, egne lifehacks og egen kalender. De kan ikke se dine ting og kan ikke rette forsiden.</p>
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
      <section class="card chat" data-chat>
        <div class="card-head">
          <h2 class="chat-h" aria-label="Jarvis"></h2>
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
              <p>Hej <?= e(first_name($me)) ?>. Spørg mig om dit budget, dine lifehacks eller din kalender – eller bed mig gemme noget nyt.</p>
              <div class="chips-q">
                <button type="button" data-q="Hvor meget har jeg tilbage om måneden?">Hvor meget har jeg tilbage?</button>
                <button type="button" data-q="Hvad skal jeg de næste dage?">Hvad skal jeg de næste dage?</button>
                <button type="button" data-q="Gem en lifehack: ">Gem en lifehack</button>
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

    <?php else: ?>
      <div class="empty"><p>Siden findes ikke.</p><a href="./" class="btn">Til oversigten</a></div>
    <?php endif; ?>
  </main>
</div>
<?php $music = ($ui['music'] ?? null) ?: ['v' => 'byOVTxnILMU', 'list' => 'RDbyOVTxnILMU', 'url' => 'https://www.youtube.com/watch?v=byOVTxnILMU&list=RDbyOVTxnILMU']; ?>
<div class="sx" id="sx" hidden>
  <div class="sx-box" role="dialog" aria-modal="true" aria-label="Søg">
    <label class="sx-field"><?= icon('search') ?><input id="sx-q" type="search" placeholder="Søg i lifehacks, budget og sider…" autocomplete="off" spellcheck="false"><kbd>esc</kbd></label>
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
  <img src="../assets/brand/dash-180.png" alt="" class="install-ic">
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
