<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/budget.php';
require dirname(__DIR__) . '/inc/calendar.php';

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
$api = load_json('api');

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

if ($action === 'lifehack') {
    $text = trim(preg_replace('/\s+/u', ' ', (string)($input['text'] ?? '')));
    if ($text === '') {
        reply('Jeg hørte ikke noget. Prøv igen.', 422);
    }
    $categories = load_json('categories', ['Tech', 'Hjem', 'Mad', 'Økonomi', 'Produktivitet', 'Rejser']);
    $category = trim((string)($input['category'] ?? ''));
    if ($category === '' && preg_match('/^([\p{L}]+)[\s:,.\-]+(.+)$/u', $text, $m)) {
        foreach ($categories as $c) {
            if (mb_strtolower($c) === mb_strtolower($m[1])) {
                $category = $c;
                $text = $m[2];
                break;
            }
        }
    }
    $text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    $title = trim((string)($input['title'] ?? ''));
    if ($title === '') {
        $first = preg_split('/(?<=[.!?])\s+/u', $text)[0];
        $title = mb_strlen($first) > 60 ? rtrim(mb_substr($first, 0, 57)) . '…' : rtrim($first, '.');
    }
    $hacks = load_json('lifehacks');
    $id = bin2hex(random_bytes(6));
    $hacks[$id] = [
        'id' => $id,
        'title' => $title,
        'category' => $category,
        'content' => $text,
        'link' => '',
        'tags' => ['siri'],
        'pinned' => false,
        'created' => date('c'),
        'updated' => date('c'),
    ];
    save_json('lifehacks', $hacks);
    reply('Gemt' . ($category ? " under $category" : '') . ': ' . $title);
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
    $sum = budget_summary(load_json('budget', ['income' => [], 'expenses' => []]));
    if ($sum['income'] <= 0) {
        reply('Du har ikke lagt et budget ind endnu.');
    }
    $left = number_format($sum['left'], 0, ',', '.');
    reply($sum['left'] >= 0
        ? "Du har $left kroner tilbage om måneden efter faste udgifter og opsparing."
        : 'Dit budget går ' . number_format(abs($sum['left']), 0, ',', '.') . ' kroner i minus om måneden.');
}

reply('Ukendt handling. Brug lifehack, agenda eller budget.', 400);
