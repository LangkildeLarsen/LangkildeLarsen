<?php

function ev_load(?array $user = null): array
{
    return load_json(ukey('events', $user));
}

function ev_save(array $list, ?array $user = null): void
{
    $cut = date('Y-m-d', strtotime('-400 days'));
    $list = array_values(array_filter($list, fn($e) => $e['date'] >= $cut));
    usort($list, fn($a, $b) => [$a['date'], $a['time'] ?? ''] <=> [$b['date'], $b['time'] ?? '']);
    save_json(ukey('events', $user), array_slice($list, -1000));
}

function ev_add(string $title, string $date, ?string $time = null, ?string $end = null, string $note = '', ?array $user = null): ?array
{
    $title = mb_substr(trim($title), 0, 120);
    if ($title === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $time = ($time !== null && preg_match('/^\d{2}:\d{2}$/', $time)) ? $time : null;
    $end = ($time && $end !== null && preg_match('/^\d{2}:\d{2}$/', $end) && $end > $time) ? $end : null;
    $e = ['id' => bin2hex(random_bytes(5)), 'title' => $title, 'date' => $date, 'time' => $time, 'end' => $end, 'note' => mb_substr(trim($note), 0, 300), 'created' => time()];
    $list = ev_load($user);
    $list[] = $e;
    ev_save($list, $user);
    return $e;
}

function ev_remove(string $id, ?array $user = null): void
{
    ev_save(array_values(array_filter(ev_load($user), fn($e) => $e['id'] !== $id)), $user);
}

function ev_as_events(int $fromTs, int $toTs, ?array $user = null): array
{
    $out = [];
    foreach (ev_load($user) as $e) {
        $start = strtotime($e['date'] . ' ' . ($e['time'] ?? '00:00'));
        $endTs = $e['time'] ? ($e['end'] ? strtotime($e['date'] . ' ' . $e['end']) : $start + 3600) : strtotime($e['date'] . ' 23:59');
        if ($endTs < $fromTs || $start > $toTs) {
            continue;
        }
        $out[] = ['title' => $e['title'], 'start' => $start, 'end' => $endTs, 'allday' => !$e['time'], 'location' => '', 'calendar' => 'Jarvis', 'color' => 4, 'local' => true, 'id' => $e['id']];
    }
    return $out;
}

function ev_feed_key(?array $user = null): string
{
    $k = load_json(ukey('events-feed', $user));
    if (empty($k['key'])) {
        $k = ['key' => bin2hex(random_bytes(16))];
        save_json(ukey('events-feed', $user), $k);
    }
    return $k['key'];
}

function ev_ics(?array $user = null): string
{
    $esc = fn($s) => str_replace(["\\", ';', ',', "\n"], ["\\\\", '\\;', '\\,', '\\n'], $s);
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//langkildelarsen.dk//Jarvis//DA', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:Jarvis', 'X-WR-TIMEZONE:Europe/Copenhagen', 'REFRESH-INTERVAL;VALUE=DURATION:PT15M', 'X-PUBLISHED-TTL:PT15M'];
    $stamp = gmdate('Ymd\THis\Z');
    foreach (ev_load($user) as $e) {
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:' . $e['id'] . '@langkildelarsen.dk';
        $lines[] = 'DTSTAMP:' . $stamp;
        if ($e['time']) {
            $s = strtotime($e['date'] . ' ' . $e['time']);
            $en = $e['end'] ? strtotime($e['date'] . ' ' . $e['end']) : $s + 3600;
            $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', $s);
            $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', $en);
        } else {
            $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $e['date']);
            $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime($e['date'] . ' +1 day'));
        }
        $lines[] = 'SUMMARY:' . $esc($e['title']);
        if ($e['note'] !== '') {
            $lines[] = 'DESCRIPTION:' . $esc($e['note']);
        }
        $lines[] = 'END:VEVENT';
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines) . "\r\n";
}
