<?php

function cal_fetch(string $url): ?string
{
    $url = preg_replace('~^webcals?://~i', 'https://', trim($url));
    if (!preg_match('~^https?://~i', $url)) {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'langkildelarsen.dk calendar',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code < 400) ? $body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'langkildelarsen.dk calendar']]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

function cal_sources(): array
{
    $out = [];
    foreach (load_json('calendars') as $c) {
        if (!empty($c['url'])) {
            $out[] = $c;
        }
    }
    return $out;
}

function cal_events(int $days = 30, bool $force = false): array
{
    $sources = cal_sources();
    if (!$sources) {
        return ['events' => [], 'errors' => []];
    }
    $cache = load_json('cal-cache');
    $tz = new DateTimeZone(date_default_timezone_get());
    $from = new DateTime('now', $tz);
    $to = (new DateTime('today', $tz))->modify("+$days days");
    $events = [];
    $errors = [];
    $dirty = false;

    foreach ($sources as $i => $src) {
        $key = md5($src['url']);
        $entry = $cache[$key] ?? null;
        if ($force || !$entry || time() - $entry['time'] > 900) {
            $body = cal_fetch($src['url']);
            if ($body !== null && str_contains($body, 'BEGIN:VCALENDAR')) {
                $entry = ['time' => time(), 'ics' => $body];
                $cache[$key] = $entry;
                $dirty = true;
            } elseif (!$entry) {
                $errors[] = $src['name'] ?: 'Kalender ' . ($i + 1);
                continue;
            }
        }
        foreach (ics_parse($entry['ics'], $tz, $from, $to) as $ev) {
            $ev['calendar'] = $src['name'];
            $ev['color'] = $i % 4;
            $events[] = $ev;
        }
    }

    if ($dirty) {
        save_json('cal-cache', $cache);
    }
    usort($events, fn($a, $b) => [$a['start'], !$a['allday']] <=> [$b['start'], !$b['allday']]);
    return ['events' => $events, 'errors' => $errors];
}

function ics_text(string $v): string
{
    return str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $v);
}

function ics_date(string $value, array $params, DateTimeZone $tz): array
{
    if (($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value)) {
        $d = DateTime::createFromFormat('!Ymd', substr($value, 0, 8), $tz);
        return [$d, true];
    }
    if (str_ends_with($value, 'Z')) {
        $d = DateTime::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
    } else {
        $zone = $tz;
        if (!empty($params['TZID'])) {
            try {
                $zone = new DateTimeZone(trim($params['TZID'], '"'));
            } catch (Exception $e) {
                $zone = $tz;
            }
        }
        $d = DateTime::createFromFormat('Ymd\THis', substr($value, 0, 15), $zone);
    }
    if ($d) {
        $d->setTimezone($tz);
    }
    return [$d, false];
}

function ics_parse(string $ics, DateTimeZone $tz, DateTime $from, DateTime $to): array
{
    $ics = preg_replace("/\r\n[ \t]|\n[ \t]/", '', str_replace("\r\n", "\n", $ics));
    preg_match_all('/BEGIN:VEVENT\n(.*?)END:VEVENT/s', $ics, $blocks);

    $raw = [];
    $overrides = [];
    foreach ($blocks[1] as $block) {
        $ev = ['props' => [], 'exdates' => []];
        foreach (explode("\n", $block) as $line) {
            if (!preg_match('/^([A-Z\-]+)((?:;[^:]*)?):(.*)$/', $line, $m)) {
                continue;
            }
            $params = [];
            foreach (array_filter(explode(';', $m[2])) as $p) {
                [$k, $v] = array_pad(explode('=', $p, 2), 2, '');
                $params[strtoupper($k)] = $v;
            }
            if ($m[1] === 'EXDATE') {
                foreach (explode(',', $m[3]) as $x) {
                    [$d] = ics_date(trim($x), $params, $tz);
                    if ($d) {
                        $ev['exdates'][] = $d->format('YmdHi');
                    }
                }
                continue;
            }
            $ev['props'][$m[1]] = ['value' => $m[3], 'params' => $params];
        }
        if (strtoupper($ev['props']['STATUS']['value'] ?? '') === 'CANCELLED' || empty($ev['props']['DTSTART'])) {
            if (!empty($ev['props']['RECURRENCE-ID'])) {
                [$rid] = ics_date($ev['props']['RECURRENCE-ID']['value'], $ev['props']['RECURRENCE-ID']['params'], $tz);
                if ($rid) {
                    $overrides[($ev['props']['UID']['value'] ?? '') . '|' . $rid->format('YmdHi')] = true;
                }
            }
            continue;
        }
        if (!empty($ev['props']['RECURRENCE-ID'])) {
            [$rid] = ics_date($ev['props']['RECURRENCE-ID']['value'], $ev['props']['RECURRENCE-ID']['params'], $tz);
            if ($rid) {
                $overrides[($ev['props']['UID']['value'] ?? '') . '|' . $rid->format('YmdHi')] = true;
            }
        }
        $raw[] = $ev;
    }

    $out = [];
    foreach ($raw as $ev) {
        $p = $ev['props'];
        [$start, $allday] = ics_date($p['DTSTART']['value'], $p['DTSTART']['params'], $tz);
        if (!$start) {
            continue;
        }
        $duration = 0;
        if (!empty($p['DTEND'])) {
            [$end] = ics_date($p['DTEND']['value'], $p['DTEND']['params'], $tz);
            if ($end) {
                $duration = $end->getTimestamp() - $start->getTimestamp();
            }
        } elseif ($allday) {
            $duration = 86400;
        }
        $uid = $p['UID']['value'] ?? '';
        $isOverride = !empty($p['RECURRENCE-ID']);
        $base = [
            'title' => ics_text($p['SUMMARY']['value'] ?? '(uden titel)'),
            'location' => ics_text($p['LOCATION']['value'] ?? ''),
            'allday' => $allday,
        ];

        $starts = (!empty($p['RRULE']) && !$isOverride) ? ics_expand($start, $p['RRULE']['value'], $tz, $to) : [$start];
        foreach ($starts as $s) {
            $sig = $s->format('YmdHi');
            if (in_array($sig, $ev['exdates'], true) || (!$isOverride && isset($overrides[$uid . '|' . $sig]))) {
                continue;
            }
            $endTs = $s->getTimestamp() + max(0, $duration);
            if ($endTs <= $from->getTimestamp() && !($duration === 0 && $s >= $from)) {
                continue;
            }
            if ($s >= $to) {
                continue;
            }
            $out[] = $base + ['start' => $s->getTimestamp(), 'end' => $endTs];
        }
    }
    return $out;
}

function ics_expand(DateTime $start, string $rrule, DateTimeZone $tz, DateTime $to): array
{
    $r = [];
    foreach (explode(';', $rrule) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $r[strtoupper($k)] = $v;
    }
    $freq = $r['FREQ'] ?? '';
    $interval = max(1, (int)($r['INTERVAL'] ?? 1));
    $count = isset($r['COUNT']) ? (int)$r['COUNT'] : null;
    $until = null;
    if (!empty($r['UNTIL'])) {
        [$until] = ics_date($r['UNTIL'], [], $tz);
        if ($until && strlen($r['UNTIL']) === 8) {
            $until->modify('+1 day -1 second');
        }
    }
    $limit = $until && $until < $to ? $until : $to;
    $days = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];
    $byday = [];
    foreach (array_filter(explode(',', $r['BYDAY'] ?? '')) as $d) {
        if (isset($days[substr($d, -2)]) && preg_match('/^[A-Z]{2}$/', $d)) {
            $byday[] = $days[substr($d, -2)];
        }
    }

    $out = [];
    $n = 0;
    $guard = 0;

    if ($freq === 'WEEKLY' && $byday) {
        sort($byday);
        $week = (clone $start)->modify('monday this week');
        while ($guard++ < 2000) {
            foreach ($byday as $dow) {
                $d = (clone $week)->modify('+' . ($dow - 1) . ' days');
                $d->setTime((int)$start->format('H'), (int)$start->format('i'), (int)$start->format('s'));
                if ($d < $start) {
                    continue;
                }
                if ($d > $limit || ($count !== null && $n >= $count)) {
                    return $out;
                }
                $out[] = $d;
                $n++;
            }
            $week->modify("+$interval weeks");
        }
        return $out;
    }

    $step = ['DAILY' => 'days', 'WEEKLY' => 'weeks', 'MONTHLY' => 'months', 'YEARLY' => 'years'][$freq] ?? null;
    if (!$step) {
        return [$start];
    }
    $dom = (int)$start->format('j');
    $i = 0;
    while ($guard++ < 5000) {
        $d = clone $start;
        if ($step === 'months' || $step === 'years') {
            $months = $step === 'months' ? $i * $interval : $i * $interval * 12;
            $d->setDate((int)$start->format('Y'), (int)$start->format('n') + $months, 1);
            if ((int)$d->format('t') < $dom) {
                $i++;
                continue;
            }
            $d->setDate((int)$d->format('Y'), (int)$d->format('n'), $dom);
        } else {
            $d->modify('+' . ($i * $interval) . " $step");
        }
        if ($d > $limit || ($count !== null && $n >= $count)) {
            break;
        }
        $out[] = $d;
        $n++;
        $i++;
    }
    return $out;
}

function dk_weekday(int $ts): string
{
    $d = ['søndag', 'mandag', 'tirsdag', 'onsdag', 'torsdag', 'fredag', 'lørdag'];
    return $d[(int)date('w', $ts)];
}

function day_label(int $ts): string
{
    $today = strtotime('today');
    $day = strtotime(date('Y-m-d', $ts));
    if ($day === $today) {
        return 'I dag';
    }
    if ($day === $today + 86400) {
        return 'I morgen';
    }
    return ucfirst(dk_weekday($ts)) . ' ' . dk_date(date('c', $ts));
}

function event_time(array $ev): string
{
    if ($ev['allday']) {
        return 'Hele dagen';
    }
    $s = date('H:i', $ev['start']);
    return $ev['end'] > $ev['start'] ? $s . '–' . date('H:i', $ev['end']) : $s;
}
