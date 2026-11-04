<?php

function rem_load(?array $user = null): array
{
    return load_json(ukey('reminders', $user));
}

function rem_save(array $list, ?array $user = null): void
{
    usort($list, fn($a, $b) => $a['due'] <=> $b['due']);
    $cut = time() - 30 * 86400;
    $list = array_values(array_filter($list, fn($r) => !$r['done'] || ($r['done_at'] ?? 0) > $cut));
    save_json(ukey('reminders', $user), array_slice($list, -300));
}

function rem_parse_due(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $ts = strtotime($raw);
    return $ts ?: null;
}

function rem_add(string $text, int $due, ?array $user = null): array
{
    $list = rem_load($user);
    $r = [
        'id' => bin2hex(random_bytes(5)),
        'text' => mb_substr(trim($text), 0, 200),
        'due' => $due,
        'done' => false,
        'notified' => $due <= time() - 60,
        'created' => time(),
    ];
    $list[] = $r;
    rem_save($list, $user);
    return $r;
}

function rem_toggle(string $id, ?array $user = null): void
{
    $list = rem_load($user);
    foreach ($list as &$r) {
        if ($r['id'] === $id) {
            $r['done'] = !$r['done'];
            $r['done_at'] = $r['done'] ? time() : 0;
        }
    }
    unset($r);
    rem_save($list, $user);
}

function rem_snooze(string $id, string $by, ?array $user = null): void
{
    $list = rem_load($user);
    foreach ($list as &$r) {
        if ($r['id'] !== $id) {
            continue;
        }
        $base = max(time(), $r['due']);
        if ($by === '1h') {
            $r['due'] = $base + 3600;
        } elseif ($by === 'tomorrow') {
            $cand = strtotime('tomorrow ' . date('H:i', $r['due']));
            $r['due'] = $cand > $r['due'] ? $cand : $r['due'] + 86400;
        } elseif ($by === '1w') {
            $r['due'] = strtotime('+7 days', $base);
        }
        $r['notified'] = false;
    }
    unset($r);
    rem_save($list, $user);
}

function rem_when(int $ts): string
{
    $d = date('Y-m-d', $ts);
    $today = date('Y-m-d');
    $time = date('H:i', $ts);
    if ($d === $today) return 'I dag ' . $time;
    if ($d === date('Y-m-d', strtotime('+1 day'))) return 'I morgen ' . $time;
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'I går ' . $time;
    if ($ts > time() && $ts < strtotime('+6 days')) return ucfirst(dk_weekday($ts)) . ' ' . $time;
    return preg_replace('/\s\d{4}$/', '', dk_date(date('c', $ts))) . ' ' . $time;
}

function rem_dispatch(): int
{
    if (!function_exists('push_send')) {
        return 0;
    }
    $lock = load_json('reminder-run');
    if (($lock['t'] ?? 0) > time() - 45) {
        return 0;
    }
    save_json('reminder-run', ['t' => time()]);
    $sent = 0;
    foreach (users_all() as $u) {
        $list = rem_load($u);
        $changed = false;
        foreach ($list as &$r) {
            if (!$r['done'] && empty($r['notified']) && $r['due'] <= time()) {
                push_send($u, ['title' => 'Påmindelse', 'body' => $r['text'], 'url' => '/admin/', 'tag' => 'rem-' . $r['id']]);
                $r['notified'] = true;
                $changed = true;
                $sent++;
            }
        }
        unset($r);
        if ($changed) {
            save_json(ukey('reminders', $u), $list);
        }
    }
    return $sent;
}
