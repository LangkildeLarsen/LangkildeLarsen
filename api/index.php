<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/budget.php';
require dirname(__DIR__) . '/inc/calendar.php';
require dirname(__DIR__) . '/inc/messages.php';
require dirname(__DIR__) . '/inc/training.php';
require dirname(__DIR__) . '/inc/reminders.php';
require dirname(__DIR__) . '/inc/claude.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function reply(string $text, int $code = 200): void
{
    http_response_code($code);
    echo $text;
    exit;
}

function ip_key(): string
{
    return 'api-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'x');
}

$limits = load_json('login');
$entry = $limits[ip_key()] ?? ['count' => 0, 'last' => 0];
if ($entry['count'] >= 10 && time() - $entry['last'] < 900) {
    reply('For mange forkerte forsøg. Prøv igen senere.', 429);
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : (string)($_POST['token'] ?? '');
users_migrate();
$apiUser = null;
if ($token !== '') {
    foreach (users_all() as $u) {
        $cand = load_json(ukey('api', $u));
        if (!empty($cand['hash']) && hash_equals($cand['hash'], hash('sha256', $token))) {
            $apiUser = $u;
            break;
        }
    }
}
$api = $apiUser ? ['hash' => hash('sha256', $token)] : [];
if ($apiUser) {
    $GLOBALS['ctx_user'] = $apiUser;
}

if ($token === '' || empty($api['hash']) || !hash_equals($api['hash'], hash('sha256', $token))) {
    $entry['count'] = time() - $entry['last'] > 900 ? 1 : $entry['count'] + 1;
    $entry['last'] = time();
    $limits[ip_key()] = $entry;
    save_json('login', $limits);
    reply('Ikke godkendt.', 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    reply('Brug POST.', 405);
}

$input = $_POST;
if (!$input) {
    $json = json_decode((string)file_get_contents('php://input'), true);
    $input = is_array($json) ? $json : [];
}

$action = mb_strtolower(trim((string)($input['action'] ?? '')));

if ($action === 'jarvis' || $action === 'spørg') {
    $text = trim(preg_replace('/\s+/u', ' ', (string)($input['text'] ?? '')));
    if ($text === '') {
        reply('Jeg hørte ikke noget. Prøv igen.', 422);
    }
    if (claude_key() === '') {
        reply('Jarvis er ikke sat op endnu.');
    }
    @set_time_limit(90);
    $res = claude_chat($apiUser, mb_substr($text, 0, 2000));
    reply(trim(str_replace('**', '', (string)($res['error'] ?? $res['reply'] ?? 'Det er gjort.'))));
}

if ($action === 'træning' || $action === 'traening' || $action === 'trænet') {
    training_add(['type' => (string)($input['type'] ?? 'Træning'), 'min' => $input['min'] ?? 0, 'km' => $input['km'] ?? 0], $apiUser);
    $st = training_stats(training_load($apiUser));
    $left = $st['goal'] - $st['sessions'];
    reply('Godt gået! Det er ' . $st['sessions'] . ' af ' . $st['goal'] . ' denne uge.' . ($left > 0 ? '' : ' Ugens mål er nået.'));
}

if ($action === 'påmindelse' || $action === 'paamindelse' || $action === 'husk') {
    $text = trim(preg_replace('/\s+/u', ' ', (string)($input['text'] ?? '')));
    if ($text === '') {
        reply('Hvad skal jeg minde dig om?', 422);
    }
    if (claude_key() !== '') {
        @set_time_limit(90);
        $res = claude_chat($apiUser, 'Opret en påmindelse ud fra dette (find selv tidspunktet, standard om 1 time hvis intet tidspunkt er nævnt). Svar med én kort sætning: ' . $text);
        reply(trim(str_replace('**', '', (string)($res['error'] ?? $res['reply'] ?? 'Påmindelsen er gemt.'))));
    }
    $r = rem_add($text, strtotime('+1 hour'), $apiUser);
    reply('Gemt. Jeg minder dig om det ' . mb_strtolower(rem_when($r['due'])) . '.');
}

if ($action === 'agenda' || $action === 'kalender') {
    if (!cal_sources()) {
        reply('Du har ikke forbundet en kalender endnu.');
    }
    $days = max(1, min(14, (int)($input['days'] ?? 2)));
    $res = cal_events($days);
    if (!$res['events']) {
        reply($days <= 2 ? 'Du har ingen aftaler i dag eller i morgen.' : "Du har ingen aftaler de næste $days dage.");
    }
    $groups = [];
    foreach ($res['events'] as $ev) {
        $label = mb_strtolower(day_label($ev['start']));
        $label = in_array($label, ['i dag', 'i morgen'], true) ? $label : dk_weekday($ev['start']);
        $groups[$label][] = $ev['allday'] ? $ev['title'] . ' hele dagen' : $ev['title'] . ' klokken ' . date('H:i', $ev['start']);
    }
    $parts = [];
    foreach ($groups as $label => $items) {
        $last = array_pop($items);
        $list = $items ? implode(', ', $items) . ' og ' . $last : $last;
        $parts[] = ucfirst($label) . ': ' . $list . '.';
    }
    reply(implode(' ', $parts));
}

if ($action === 'budget') {
    $sum = budget_summary(load_json(ukey('budget'), ['income' => [], 'expenses' => []]));
    if ($sum['income'] <= 0) {
        reply('Du har ikke lagt et budget ind endnu.');
    }
    $left = number_format($sum['left'], 0, ',', '.');
    reply($sum['left'] >= 0
        ? "Du har $left kroner tilbage om måneden efter faste udgifter og opsparing."
        : 'Dit budget går ' . number_format(abs($sum['left']), 0, ',', '.') . ' kroner i minus om måneden.');
}

reply('Ukendt handling. Brug jarvis, påmindelse, træning, agenda eller budget.', 400);
