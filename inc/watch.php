<?php

function watch_load(?array $user = null): array
{
    return load_json(ukey('watch', $user));
}

function watch_save(array $list, ?array $user = null): void
{
    save_json(ukey('watch', $user), array_values(array_slice($list, 0, 40)));
}

function watch_http(string $url): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_USERAGENT => 'langkildelarsen-dashboard']);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        return null;
    }
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : null;
}

function watch_tvmaze(string $q, int $id = 0): ?array
{
    $base = getenv('TVMAZE_URL') ?: 'https://api.tvmaze.com';
    $show = $id
        ? watch_http($base . '/shows/' . $id . '?embed[]=nextepisode&embed[]=previousepisode')
        : watch_http($base . '/singlesearch/shows?q=' . rawurlencode($q) . '&embed[]=nextepisode&embed[]=previousepisode');
    if (!$show || empty($show['id'])) {
        return null;
    }
    $next = $show['_embedded']['nextepisode'] ?? null;
    $prev = $show['_embedded']['previousepisode'] ?? null;
    $ep = fn($e) => 'S' . str_pad((string)($e['season'] ?? 0), 2, '0', STR_PAD_LEFT) . 'E' . str_pad((string)($e['number'] ?? 0), 2, '0', STR_PAD_LEFT) . (!empty($e['name']) ? ' · ' . $e['name'] : '');
    $date = null;
    $label = '';
    if ($next) {
        $date = $next['airstamp'] ?? $next['airdate'] ?? null;
        $date = $date ? date('Y-m-d', strtotime($date)) : null;
        $label = $ep($next);
    }
    return [
        'tvmaze' => (int)$show['id'],
        'title' => (string)$show['name'],
        'status' => (string)($show['status'] ?? ''),
        'date' => $date,
        'label' => $label ?: ($show['status'] === 'Ended' ? 'Serien er afsluttet' : 'Ingen ny dato endnu'),
        'prev' => $prev ? $ep($prev) . ' (' . date('j/n', strtotime($prev['airdate'] ?? 'now')) . ')' : '',
    ];
}

function watch_web(string $q): ?array
{
    if (!function_exists('claude_request') || claude_key() === '') {
        return null;
    }
    $res = claude_request([
        'model' => claude_model(),
        'max_tokens' => 400,
        'tools' => [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3]],
        'messages' => [['role' => 'user', 'content' => 'I dag er ' . date('Y-m-d') . '. Find den næste kommende udgivelsesdato for: "' . $q . '" (fx biografpremiere i Danmark, streaming-premiere, ny sæson eller næste episode – det der er relevant og næste i tiden). Svar KUN med JSON: {"title": "...", "date": "YYYY-MM-DD eller null", "label": "kort dansk beskrivelse af hvad der udkommer, maks 60 tegn"}']],
    ]);
    if (isset($res['error'])) {
        return null;
    }
    $text = '';
    foreach ($res['content'] ?? [] as $c) {
        if (($c['type'] ?? '') === 'text') {
            $text .= $c['text'];
        }
    }
    if (function_exists('claude_track')) {
        claude_track((array)($res['usage'] ?? []), 'watch', false);
    }
    if (!preg_match('/\{.*\}/s', $text, $m)) {
        return null;
    }
    $j = json_decode($m[0], true);
    if (!is_array($j)) {
        return null;
    }
    $date = (isset($j['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$j['date'])) ? $j['date'] : null;
    return ['title' => trim((string)($j['title'] ?? $q)) ?: $q, 'date' => $date, 'label' => mb_substr(trim((string)($j['label'] ?? '')), 0, 80) ?: ($date ? 'Udkommer' : 'Ingen dato endnu')];
}

function watch_add(string $q, ?array $user = null): array
{
    $q = trim($q);
    $list = watch_load($user);
    $tv = watch_tvmaze($q);
    $row = [
        'id' => bin2hex(random_bytes(5)),
        'q' => $q,
        'kind' => $tv ? 'serie' : 'web',
        'title' => $tv['title'] ?? $q,
        'date' => $tv['date'] ?? null,
        'label' => $tv['label'] ?? 'Jarvis tjekker…',
        'prev' => $tv['prev'] ?? '',
        'tvmaze' => $tv['tvmaze'] ?? 0,
        'checked' => $tv ? time() : 0,
        'pinged' => [],
    ];
    $list[] = $row;
    watch_save($list, $user);
    return $row;
}

function watch_remove(string $id, ?array $user = null): void
{
    watch_save(array_values(array_filter(watch_load($user), fn($w) => $w['id'] !== $id)), $user);
}

function watch_refresh(array $w): array
{
    $old = $w['date'];
    if ($w['kind'] === 'serie') {
        $tv = watch_tvmaze($w['q'], (int)$w['tvmaze']);
        if ($tv) {
            $w = array_merge($w, array_intersect_key($tv, ['title' => 1, 'date' => 1, 'label' => 1, 'prev' => 1]));
        }
    } else {
        $web = watch_web($w['title'] !== '' ? $w['title'] : $w['q']);
        if ($web) {
            $w = array_merge($w, $web);
        } elseif (!$w['checked']) {
            $w['label'] = 'Ingen dato fundet endnu';
        }
    }
    $w['checked'] = time();
    $w['changed'] = $w['date'] !== $old && $w['date'] !== null;
    return $w;
}

function watch_days(?string $date): ?int
{
    return $date ? (int)round((strtotime($date) - strtotime('today')) / 86400) : null;
}

function watch_when(?string $date): string
{
    $d = watch_days($date);
    if ($d === null) return 'Ingen dato';
    if ($d < 0) return 'Udkommet';
    if ($d === 0) return 'I dag!';
    if ($d === 1) return 'I morgen';
    if ($d < 14) return 'Om ' . $d . ' dage';
    return date('j/n', strtotime($date)) . (date('Y', strtotime($date)) !== date('Y') ? ' ' . date('Y', strtotime($date)) : '');
}

function watch_run(): int
{
    $lock = load_json('watch-run');
    if (($lock['t'] ?? 0) > time() - 1800) {
        return 0;
    }
    save_json('watch-run', ['t' => time()]);
    $n = 0;
    foreach (users_all() as $u) {
        $list = watch_load($u);
        if (!$list) {
            continue;
        }
        $changed = false;
        $webDone = 0;
        foreach ($list as &$w) {
            $age = time() - (int)$w['checked'];
            $due = $w['kind'] === 'serie' ? $age > 20 * 3600 : ($w['checked'] === 0 || $age > 7 * 86400);
            if ($w['kind'] === 'web' && $webDone >= 2) {
                $due = false;
            }
            if ($due) {
                $w = watch_refresh($w);
                if ($w['kind'] === 'web') {
                    $webDone++;
                }
                if (!empty($w['changed'])) {
                    push_send($u, ['title' => $w['title'], 'body' => 'Ny dato: ' . watch_when($w['date']) . ' – ' . $w['label'], 'url' => '/admin/', 'tag' => 'watch-' . $w['id']]);
                    $n++;
                }
                unset($w['changed']);
                $changed = true;
            }
            $days = watch_days($w['date']);
            foreach ([1 => 'I morgen', 0 => 'I dag'] as $dd => $txt) {
                $key = $w['date'] . ':' . $dd;
                if ($days === $dd && (int)date('G') >= 9 && !in_array($key, $w['pinged'] ?? [], true)) {
                    push_send($u, ['title' => $txt . ': ' . $w['title'], 'body' => $w['label'], 'url' => '/admin/', 'tag' => 'watch-' . $w['id']]);
                    $w['pinged'][] = $key;
                    $w['pinged'] = array_slice($w['pinged'], -10);
                    $changed = true;
                    $n++;
                }
            }
        }
        unset($w);
        if ($changed) {
            watch_save($list, $u);
        }
    }
    return $n;
}
