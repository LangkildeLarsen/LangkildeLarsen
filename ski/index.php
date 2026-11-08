<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/weather.php';
require dirname(__DIR__) . '/inc/trip.php';
require dirname(__DIR__) . '/inc/ski.php';

header('X-Robots-Tag: noindex');

$action = (string)($_POST['action'] ?? '');
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$joinToken = (string)($_GET['join'] ?? $_POST['join'] ?? '');
$error = '';

function ski_redirect(string $hash = ''): void
{
    global $isAjax;
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }
    redirect('./' . ($hash ? '#' . $hash : ''));
}

if ($action === 'join' || $action === 'login') {
    verify_csrf();
    if ($action === 'join') {
        if (!ski_invite_valid($joinToken)) {
            $error = 'Invitationslinket virker ikke længere. Bed om et nyt.';
        } else {
            $res = ski_join((string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
            if (isset($res['error'])) {
                $error = $res['error'];
            } else {
                session_regenerate_id(true);
                $_SESSION['ski_uid'] = $res['id'];
                ski_remember($res['id']);
                redirect('./');
            }
        }
    } else {
        $id = ski_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
        if ($id) {
            session_regenerate_id(true);
            $_SESSION['ski_uid'] = $id;
            ski_remember($id);
            redirect('./');
        }
        sleep(1);
        $error = 'Forkert mail eller adgangskode.';
    }
}

$me = ski_current();

if (!$me) {
    $mode = ski_invite_valid($joinToken) ? 'join' : 'login';
    ?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050505">
<title>Skiferie · Mayrhofen</title>
<link rel="icon" type="image/png" sizes="32x32" href="../assets/favicon-32.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
<link rel="stylesheet" href="../assets/ski.css?v=<?= filemtime(dirname(__DIR__) . '/assets/ski.css') ?>">
</head>
<body class="auth sk-auth">
<form method="post" class="auth-box" action="./<?= $mode === 'join' ? '?join=' . e($joinToken) : '' ?>">
  <?= csrf_field() ?>
  <span class="sk-auth-ic"><?= icon('snow') ?></span>
  <h1><?= $mode === 'join' ? 'Kom med til Mayrhofen' : 'Skiferie' ?></h1>
  <p class="muted"><?= $mode === 'join' ? 'Du er inviteret til skiferien 25. dec. – 2. jan. Opret dig, så får du nedtælling, sne, skitracker og huskelister.' : 'Log ind med den mail og adgangskode, du oprettede dig med.' ?></p>
  <?php if ($error): ?><p class="alert error"><?= e($error) ?></p><?php endif; ?>
  <input type="hidden" name="action" value="<?= $mode ?>">
  <?php if ($mode === 'join'): ?>
    <input type="hidden" name="join" value="<?= e($joinToken) ?>">
    <label>Navn<input name="name" required autofocus autocomplete="given-name" maxlength="40" value="<?= e($_POST['name'] ?? '') ?>"></label>
  <?php endif; ?>
  <label>Mail<input type="email" name="email" required <?= $mode === 'login' ? 'autofocus' : '' ?> autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></label>
  <label>Adgangskode<input type="password" name="password" required minlength="<?= $mode === 'join' ? 8 : 1 ?>" autocomplete="<?= $mode === 'join' ? 'new-password' : 'current-password' ?>"></label>
  <button class="btn"><?= $mode === 'join' ? 'Opret og kom med' : 'Log ind' ?></button>
  <?php if ($mode === 'join'): ?><a href="./" class="muted small">Har du allerede en adgang? Log ind</a><?php endif; ?>
</form>
</body>
</html>
<?php
    exit;
}

$isOwner = $me['role'] === 'owner';
$mid = $me['id'];

if ($action !== '') {
    verify_csrf();

    if ($action === 'logout') {
        ski_cookie('', time() - 3600);
        unset($_SESSION['ski_uid']);
        redirect('./');
    }

    if ($action === 'invite_new' && $isOwner) {
        ski_invite_new();
        ski_redirect('inviter');
    }

    if ($action === 'remove_member' && $isOwner) {
        $id = (string)($_POST['id'] ?? '');
        ski_mutate('members', function (&$m) use ($id) {
            if (isset($m[$id]) && $m[$id]['role'] !== 'owner') {
                unset($m[$id]);
            }
        });
        ski_redirect('inviter');
    }

    if ($action === 'run_save') {
        ski_run_save($mid, $_POST);
        ski_redirect('tracker');
    }

    if ($action === 'run_delete') {
        ski_run_delete($mid, (string)($_POST['id'] ?? ''), $isOwner);
        ski_redirect('rangliste');
    }

    if ($action === 'plan_add') {
        $date = (string)($_POST['date'] ?? '');
        $time = (string)($_POST['time'] ?? '');
        $title = ski_clean_text((string)($_POST['title'] ?? ''));
        if ($title !== '' && in_array($date, ski_trip_days(), true)) {
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300);
            ski_mutate('plan', function (&$p) use ($date, $time, $title, $note, $mid) {
                $id = ski_id();
                $p[$id] = ['id' => $id, 'date' => $date, 'time' => preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '', 'title' => $title, 'note' => $note, 'by' => $mid, 'created' => date('c')];
            });
        }
        ski_redirect('program');
    }

    if ($action === 'plan_delete') {
        $id = (string)($_POST['id'] ?? '');
        ski_mutate('plan', function (&$p) use ($id, $mid, $isOwner) {
            if ($id !== '' && $id[0] !== '_' && isset($p[$id]) && ($p[$id]['by'] === $mid || $isOwner)) {
                unset($p[$id]);
            }
        });
        ski_redirect('program');
    }

    if ($action === 'pack_add') {
        $text = ski_clean_text((string)($_POST['text'] ?? ''));
        if ($text !== '') {
            ski_packing_mutate($mid, function (&$l) use ($text) {
                $id = ski_id();
                $l[$id] = ['id' => $id, 'text' => $text, 'done' => false, 'created' => date('c')];
            });
        }
        ski_redirect('min-liste');
    }

    if ($action === 'pack_suggest') {
        ski_packing_mutate($mid, function (&$l) {
            $have = array_map('mb_strtolower', array_column($l, 'text'));
            foreach (SKI_SUGGEST as $t) {
                if (!in_array(mb_strtolower($t), $have, true)) {
                    $id = ski_id();
                    $l[$id] = ['id' => $id, 'text' => $t, 'done' => false, 'created' => date('c')];
                }
            }
        });
        ski_redirect('min-liste');
    }

    if ($action === 'pack_toggle' || $action === 'pack_delete') {
        $id = (string)($_POST['id'] ?? '');
        ski_packing_mutate($mid, function (&$l) use ($id, $action) {
            if (!isset($l[$id])) {
                return;
            }
            if ($action === 'pack_delete') {
                unset($l[$id]);
            } else {
                $l[$id]['done'] = empty($l[$id]['done']);
            }
        });
        ski_redirect('min-liste');
    }

    if ($action === 'shared_add') {
        $text = ski_clean_text((string)($_POST['text'] ?? ''));
        if ($text !== '') {
            ski_mutate('shared', function (&$l) use ($text, $mid) {
                $id = ski_id();
                $l[$id] = ['id' => $id, 'text' => $text, 'by' => $mid, 'have' => [], 'created' => date('c')];
            });
        }
        ski_redirect('faelles');
    }

    if ($action === 'shared_toggle' || $action === 'shared_delete') {
        $id = (string)($_POST['id'] ?? '');
        ski_mutate('shared', function (&$l) use ($id, $action, $mid, $isOwner) {
            if (!isset($l[$id])) {
                return;
            }
            if ($action === 'shared_delete') {
                if ($l[$id]['by'] === $mid || $isOwner) {
                    unset($l[$id]);
                }
                return;
            }
            $have = $l[$id]['have'];
            $l[$id]['have'] = in_array($mid, $have, true) ? array_values(array_diff($have, [$mid])) : array_merge($have, [$mid]);
        });
        ski_redirect('faelles');
    }

    ski_redirect();
}

if (($_GET['ajax'] ?? '') === 'snow') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo trip_snow_html(trip_snow());
    exit;
}

$members = ski_members();
$runs = ski_runs();
uasort($runs, fn($a, $b) => strcmp($b['date'] . $b['updated'], $a['date'] . $a['updated']));
$board = ski_board($members, $runs);
$mine = ski_packing($mid);
uasort($mine, fn($a, $b) => ($a['done'] <=> $b['done']) ?: strcmp($a['created'], $b['created']));
$shared = ski_shared();
uasort($shared, fn($a, $b) => strcmp($a['created'], $b['created']));
$plan = ski_plan();
$tripDays = ski_trip_days();
$planByDay = [];
foreach ($plan as $x) {
    $planByDay[$x['date']][] = $x;
}
$st = trip_status();
$range = date('j', $st['start']) . '. dec. – ' . date('j', $st['end']) . '. jan.';
$inviteToken = $isOwner ? ski_invite_token() : '';
$snowCache = load_json('trip-snow-cache');
$snowFresh = ($snowCache['t'] ?? 0) > time() - 3600;
$myTotal = array_values(array_filter($board, fn($r) => $r['m']['id'] === $mid))[0] ?? null;
$mineDone = count(array_filter($mine, fn($x) => !empty($x['done'])));
?><!doctype html>
<html lang="da">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<meta name="theme-color" content="#050505">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Skiferie · Mayrhofen</title>
<link rel="icon" type="image/png" sizes="32x32" href="../assets/favicon-32.png">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300..700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/admin.css?v=<?= filemtime(dirname(__DIR__) . '/assets/admin.css') ?>">
<link rel="stylesheet" href="../assets/ski.css?v=<?= filemtime(dirname(__DIR__) . '/assets/ski.css') ?>">
<script src="../assets/ski.js?v=<?= filemtime(dirname(__DIR__) . '/assets/ski.js') ?>" defer></script>
</head>
<body class="sk" data-csrf="<?= e(csrf_token()) ?>">
<div class="sk-app">
  <header class="sk-top">
    <div class="sk-brand"><span class="sk-brand-ic"><?= icon('snow') ?></span><span>Skiferie</span></div>
    <div class="sk-top-r">
      <?php if ($isOwner): ?><a href="../admin/" class="pill-link">Jarvis</a><?php endif; ?>
      <?= ski_avatar($me, 'lg') ?>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="round" aria-label="Log ud" title="Log ud"><?= icon('logout') ?></button></form>
    </div>
  </header>

  <main class="sk-grid">
    <section class="card sk-hero">
      <p class="sk-eyebrow"><span class="dot"></span>Mayrhofen, Østrig · <?= e($range) ?></p>
      <div class="trip-count" data-trip-start="<?= $st['start'] ?>">
        <?php if ($st['phase'] === 'before'): ?>
          <p class="sk-big"><span data-trip-d><?= $st['days'] ?></span><small> <?= $st['days'] === 1 ? 'dag' : 'dage' ?></small></p>
          <p class="sk-sub"><span data-trip-hm><?= $st['hours'] ?> t <?= $st['mins'] ?> min</span> til vi kører fra Blåvand kl. <?= date('H:i', $st['start']) ?>, <?= e(first_name(['name' => $me['name'], 'email' => $me['email']])) ?></p>
        <?php elseif ($st['phase'] === 'during'): ?>
          <p class="sk-big">Dag <?= $st['day'] ?><small> af <?= $st['total'] ?></small></p>
          <p class="sk-sub">God tur på pisterne</p>
        <?php else: ?>
          <p class="sk-big">Hjemme<small> igen</small></p>
          <p class="sk-sub">Tak for turen</p>
        <?php endif; ?>
      </div>
      <div class="sk-crew" data-live="crew">
        <div class="sk-avs"><?php foreach ($members as $m): ?><?= ski_avatar($m) ?><?php endforeach; ?></div>
        <span class="muted small"><?= count($members) ?> med på turen</span>
      </div>
      <?php if ($isOwner): ?>
        <div class="sk-invite" id="inviter">
          <?php if ($inviteToken !== ''): ?>
            <div class="sk-link"><input readonly value="<?= e(ski_base_url() . '?join=' . $inviteToken) ?>" data-copy-src aria-label="Invitationslink"><button type="button" class="btn small" data-copy><?= icon('link') ?> Kopiér</button></div>
            <p class="muted small">Send linket til dem, der skal med. De opretter sig selv og ser kun skiferien.</p>
          <?php endif; ?>
          <details class="sk-manage">
            <summary class="muted small">Administrer deltagere</summary>
            <ul class="sk-members">
              <?php foreach ($members as $m): ?>
                <li><?= ski_avatar($m) ?><span><?= e($m['name']) ?><small><?= e($m['email']) ?></small></span>
                  <?php if ($m['role'] !== 'owner'): ?><form method="post" data-ajax><?= csrf_field() ?><input type="hidden" name="action" value="remove_member"><input type="hidden" name="id" value="<?= e($m['id']) ?>"><button class="sk-x" aria-label="Fjern <?= e($m['name']) ?>"><?= icon('cross') ?></button></form><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
            <form method="post" data-ajax><?= csrf_field() ?><input type="hidden" name="action" value="invite_new"><button class="btn ghost small"><?= $inviteToken !== '' ? 'Lav nyt link (det gamle stopper)' : 'Lav invitationslink' ?></button></form>
          </details>
        </div>
      <?php endif; ?>
    </section>

    <section class="card sk-snow">
      <div class="card-head"><h2>Sne</h2><span class="pill-link">Mayrhofen</span></div>
      <div class="trip-snow" data-trip-snow<?= $snowFresh ? '' : ' data-trip-refresh' ?>>
        <?= !empty($snowCache['d']) ? trip_snow_html($snowCache['d']) : '<div class="wx-empty" data-wx-load><span class="wx-spin"></span><p class="muted small">Henter snedata…</p></div>' ?>
      </div>
    </section>

    <section class="card sk-plan" id="program" data-live="plan">
      <div class="card-head"><h2>Overblik</h2><span class="drop"><?= count($tripDays) ?> dage · <?= count($tripDays) - 1 ?> nætter · <?= count(SKI_BOOKING['travellers']) ?> personer</span></div>
      <ol class="sk-days">
        <?php foreach ($tripDays as $i => $d): $evs = $planByDay[$d] ?? []; $wd = ['Man', 'Tir', 'Ons', 'Tor', 'Fre', 'Lør', 'Søn'][(int)date('N', strtotime($d)) - 1]; ?>
          <li class="sk-day<?= $d === date('Y-m-d') ? ' today' : '' ?><?= $d < date('Y-m-d') ? ' was' : '' ?><?= $evs ? ' has' : '' ?>">
            <div class="sk-day-h"><span><?= $wd ?></span><strong><?= (int)date('j', strtotime($d)) ?>.</strong><small><?= date('n', strtotime($d)) === '12' ? 'dec.' : 'jan.' ?></small></div>
            <p class="sk-day-tag"><?= $i === 0 ? 'Afrejse' : ($i === count($tripDays) - 1 ? 'Hjemrejse' : ($d === SKI_BOOKING['checkin'] ? 'Ankomst' : ($d === '2026-12-31' ? 'Nytårsaften' : ($d === '2027-01-01' ? 'Nytårsdag' : 'Skidag')))) ?></p>
            <?php foreach ($evs as $x): ?>
              <div class="sk-ev">
                <p class="sk-ev-t"><?php if ($x['time']): ?><time><?= e($x['time']) ?></time><?php endif; ?><?= e($x['title']) ?></p>
                <?php if ($x['note']): ?><p class="sk-ev-n"><?= e($x['note']) ?></p><?php endif; ?>
                <?php if ($x['by'] === $mid || $isOwner): ?><form method="post" data-ajax data-confirm><?= csrf_field() ?><input type="hidden" name="action" value="plan_delete"><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="sk-x" aria-label="Slet plan"><?= icon('cross') ?></button></form><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </li>
        <?php endforeach; ?>
      </ol>
      <details class="sk-manual">
        <summary class="muted small"><?= icon('plus') ?> Tilføj en plan</summary>
        <form method="post" data-ajax class="sk-plan-f">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="plan_add">
          <label>Dag<select name="date"><?php foreach ($tripDays as $d): ?><option value="<?= $d ?>"><?= e(ski_date($d)) ?></option><?php endforeach; ?></select></label>
          <label>Tid<input type="time" name="time"></label>
          <label class="wide">Hvad<input name="title" maxlength="80" required placeholder="Fx skiskole, après-ski, middag"></label>
          <label class="wide">Note<input name="note" maxlength="300" placeholder="Valgfrit"></label>
          <button class="btn small">Tilføj</button>
        </form>
      </details>
    </section>

    <section class="card sk-book" id="rejsen" data-live="book">
      <div class="card-head"><h2>Rejsen</h2><span class="drop"><?= count(SKI_BOOKING['travellers']) ?> rejsende</span></div>
      <div class="sk-hotel">
        <span class="sk-hotel-ic"><?= icon('home') ?></span>
        <div>
          <p class="sk-hotel-n"><?= e(SKI_BOOKING['hotel']) ?></p>
          <p class="muted small"><?= e(SKI_BOOKING['board']) ?> · <?= e(SKI_BOOKING['room']) ?></p>
        </div>
      </div>
      <div class="sk-dates">
        <div><small>Check-ind</small><span><?= e(ski_date(SKI_BOOKING['checkin'])) ?></span></div>
        <div><small>Check-ud</small><span><?= e(ski_date(SKI_BOOKING['checkout'])) ?></span></div>
        <div><small>Nætter</small><span><?= (int)round((strtotime(SKI_BOOKING['checkout']) - strtotime(SKI_BOOKING['checkin'])) / 86400) ?></span></div>
      </div>
      <p class="sk-incl"><?php foreach (SKI_BOOKING['included'] as $inc): ?><span class="trip-chip"><?= icon('check') ?><?= e($inc) ?></span><?php endforeach; ?><span class="muted small">inkluderet for alle</span></p>
      <ul class="sk-trav">
        <?php foreach (SKI_BOOKING['travellers'] as $t): $tm = ski_traveller_member($t['name'], $members); ?>
          <li>
            <?= $tm ? ski_avatar($tm) : '<span class="sk-av ghost">' . e(ski_initials(['name' => $t['name']])) . '</span>' ?>
            <span class="sk-who"><?= e($t['name']) ?><small><?= e($t['role']) ?><?= $t['extra'] ? ' · ' . e(implode(', ', $t['extra'])) : '' ?><?= $tm ? '' : ' · ikke oprettet endnu' ?></small></span>
            <span class="sk-val"><?= ski_kr($t['price']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="sk-total"><span class="muted">I alt</span><span><?= ski_kr(array_sum(array_column(SKI_BOOKING['travellers'], 'price'))) ?></span></p>
    </section>

    <section class="card sk-cams" id="webcams">
      <div class="card-head"><h2>Webcams</h2><a class="pill-link" href="https://www.mayrhofen.at/en/livecams" target="_blank" rel="noopener">mayrhofen.at</a></div>
      <div class="sk-cam-tabs" role="tablist">
        <?php foreach (SKI_CAMS as $i => $c): ?>
          <?php if ($c['embed']): ?>
            <button type="button" role="tab" class="<?= $i === 0 ? 'on' : '' ?>" data-cam="<?= e($c['url']) ?>" data-cam-name="<?= e($c['name']) ?>"><?= e($c['name']) ?></button>
          <?php else: ?>
            <a href="<?= e($c['url']) ?>" target="_blank" rel="noopener"><?= e($c['name']) ?> <?= icon('external') ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <div class="sk-cam-frame">
        <iframe src="<?= e(SKI_CAMS[0]['url']) ?>" title="Webcam <?= e(SKI_CAMS[0]['name']) ?>" loading="lazy" allowfullscreen data-cam-frame></iframe>
      </div>
      <p class="muted small sk-cam-note">Vises kameraet ikke? <a href="<?= e(SKI_CAMS[0]['url']) ?>" target="_blank" rel="noopener" data-cam-open>Åbn <?= e(SKI_CAMS[0]['name']) ?> i nyt vindue</a></p>
    </section>

    <section class="card sk-track" id="tracker" data-tracker>
      <div class="card-head"><h2>Skitracker</h2><span class="sk-gps" data-gps>GPS slukket</span></div>
      <div class="sk-speed"><span data-t-speed>0</span><small> km/t</small></div>
      <div class="sk-stats">
        <div><span data-t-max>0</span><small>Topfart km/t</small></div>
        <div><span data-t-km>0,0</span><small>Km på ski</small></div>
        <div><span data-t-desc>0</span><small>Højdemeter ned</small></div>
        <div><span data-t-time>0:00</span><small>Tid</small></div>
      </div>
      <button type="button" class="btn block sk-go" data-t-toggle><?= icon('play') ?><span>Start tracking</span></button>
      <p class="muted small sk-hint">Hold skærmen tændt, mens du tracker. Telefonen holder den vågen for dig, men GPS'en stopper, hvis du låser den eller skifter app. Data gemmes løbende.</p>
      <details class="sk-manual">
        <summary class="muted small">Tilføj en dag manuelt (fx fra Slopes eller Strava)</summary>
        <form method="post" data-ajax class="sk-manual-f">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="run_save">
          <label>Dato<input type="date" name="date" value="<?= e(date('Y-m-d')) ?>"></label>
          <label>Km<input type="number" name="km" step="0.1" min="0" inputmode="decimal" required></label>
          <label>Topfart km/t<input type="number" name="max" step="0.1" min="0" inputmode="decimal"></label>
          <label>Højdemeter ned<input type="number" name="descent" step="1" min="0" inputmode="numeric"></label>
          <button class="btn small">Gem</button>
        </form>
      </details>
    </section>

    <section class="card sk-board" id="rangliste" data-live="board">
      <div class="card-head"><h2>Rangliste</h2><span class="drop"><?= $myTotal ? ski_km($myTotal['km']) . ' km for dig' : '' ?></span></div>
      <ol class="sk-rank">
        <?php foreach ($board as $i => $r): ?>
          <li class="<?= $r['m']['id'] === $mid ? 'is-me' : '' ?>">
            <span class="sk-pos"><?= $i + 1 ?></span>
            <?= ski_avatar($r['m']) ?>
            <span class="sk-who"><?= e($r['m']['name']) ?><?php if ($r['live']): ?><i class="sk-live">på ski nu</i><?php endif; ?><small><?= count($r['days']) ?> <?= count($r['days']) === 1 ? 'dag' : 'dage' ?> · <?= number_format($r['descent'], 0, ',', '.') ?> hm</small></span>
            <span class="sk-val"><?= ski_km($r['km']) ?><small>km</small></span>
            <span class="sk-val"><?= ski_km($r['max']) ?><small>km/t</small></span>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php if ($runs): ?>
        <h3 class="sk-h3">Seneste ture</h3>
        <ul class="sk-runs">
          <?php foreach (array_slice($runs, 0, 12) as $r): $m = $members[$r['member']] ?? null; if (!$m) continue; ?>
            <li><?= ski_avatar($m) ?><span><?= e($m['name']) ?><small><?= e(ski_date($r['date'])) ?> · <?= $r['src'] === 'gps' ? 'GPS' : 'manuelt' ?></small></span><span class="sk-run-v"><?= ski_km($r['km']) ?> km · <?= ski_km($r['max']) ?> km/t</span>
              <?php if ($r['member'] === $mid || $isOwner): ?><form method="post" data-ajax data-confirm><?= csrf_field() ?><input type="hidden" name="action" value="run_delete"><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="sk-x" aria-label="Slet tur"><?= icon('cross') ?></button></form><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="muted small">Ingen ture endnu. Start trackeren, når I står på pisten.</p>
      <?php endif; ?>
    </section>

    <section class="card sk-list" id="min-liste" data-live="mine">
      <div class="card-head"><h2>Min huskeliste</h2><span class="drop"><?= $mineDone ?> af <?= count($mine) ?> pakket</span></div>
      <p class="muted small sk-list-note">Kun du kan se den her liste.</p>
      <ul class="sk-items">
        <?php foreach ($mine as $x): ?>
          <li class="<?= !empty($x['done']) ? 'done' : '' ?>">
            <form method="post" data-ajax><?= csrf_field() ?><input type="hidden" name="action" value="pack_toggle"><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="sk-check" aria-label="<?= !empty($x['done']) ? 'Fjern flueben' : 'Sæt flueben' ?>"><?= icon('check') ?></button></form>
            <span class="sk-item-t"><?= e($x['text']) ?></span>
            <form method="post" data-ajax><?= csrf_field() ?><input type="hidden" name="action" value="pack_delete"><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="sk-x" aria-label="Slet"><?= icon('cross') ?></button></form>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" data-ajax class="sk-add"><?= csrf_field() ?><input type="hidden" name="action" value="pack_add"><input name="text" placeholder="Tilføj til din liste…" autocomplete="off" maxlength="80" required><button aria-label="Tilføj"><?= icon('plus') ?></button></form>
      <?php if (count($mine) < 4): ?>
        <form method="post" data-ajax><?= csrf_field() ?><input type="hidden" name="action" value="pack_suggest"><button class="btn ghost small sk-suggest"><?= icon('sparkle') ?> Fyld op med en standard skiliste</button></form>
      <?php endif; ?>
    </section>

    <section class="card sk-list" id="faelles" data-live="shared">
      <div class="card-head"><h2>Fælles huskeliste</h2><span class="drop"><?= count($shared) ?> ting</span></div>
      <p class="muted small sk-list-note">Alle kan se og tilføje. Sæt flueben, når du har tingen med.</p>
      <ul class="sk-items">
        <?php foreach ($shared as $x): $haveMe = in_array($mid, $x['have'], true); $by = $members[$x['by']] ?? null; ?>
          <li class="<?= $haveMe ? 'done' : '' ?>">
            <form method="post" data-ajax><?= csrf_field() ?><input type="hidden" name="action" value="shared_toggle"><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="sk-check" aria-label="<?= $haveMe ? 'Jeg har den ikke med' : 'Jeg har den med' ?>"><?= icon('check') ?></button></form>
            <span class="sk-item-t"><?= e($x['text']) ?><small><?= $by ? 'Tilføjet af ' . e($by['name']) : '' ?><?= $x['have'] ? ' · ' . count($x['have']) . ' har den med' : '' ?></small></span>
            <span class="sk-avs sm"><?php foreach ($x['have'] as $h): if (isset($members[$h])): ?><?= ski_avatar($members[$h]) ?><?php endif; endforeach; ?></span>
            <?php if ($x['by'] === $mid || $isOwner): ?><form method="post" data-ajax><?= csrf_field() ?><input type="hidden" name="action" value="shared_delete"><input type="hidden" name="id" value="<?= e($x['id']) ?>"><button class="sk-x" aria-label="Slet"><?= icon('cross') ?></button></form><?php else: ?><span class="sk-x-pad"></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <form method="post" data-ajax class="sk-add"><?= csrf_field() ?><input type="hidden" name="action" value="shared_add"><input name="text" placeholder="Fx højtaler, kortspil, førstehjælp…" autocomplete="off" maxlength="80" required><button aria-label="Tilføj"><?= icon('plus') ?></button></form>
    </section>
  </main>
</div>
</body>
</html>
