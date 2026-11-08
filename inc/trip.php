<?php

const TRIP = [
    'title' => 'Skiferie',
    'place' => 'Mayrhofen',
    'country' => 'Østrig',
    'start' => '2026-12-25',
    'end' => '2027-01-02',
    'depart' => '2026-12-25 16:30',
    'lat' => 47.1667,
    'lon' => 11.8667,
    'spots' => [
        ['label' => 'Bjerget', 'elevation' => 2000],
        ['label' => 'Byen', 'elevation' => 630],
    ],
];

function trip_status(int $now = 0): array
{
    $now = $now ?: time();
    $tz = new DateTimeZone('Europe/Vienna');
    $start = (new DateTime(TRIP['depart'], $tz))->getTimestamp();
    $end = (new DateTime(TRIP['end'] . ' 23:59:59', $tz))->getTimestamp();
    $first = (new DateTime(TRIP['start'], $tz))->getTimestamp();
    $days = (int)round(($end - $first) / 86400);
    if ($now < $start) {
        $left = $start - $now;
        return ['phase' => 'before', 'start' => $start, 'end' => $end, 'nights' => $days - 1, 'days' => intdiv($left, 86400), 'hours' => intdiv($left % 86400, 3600), 'mins' => intdiv($left % 3600, 60)];
    }
    if ($now <= $end) {
        return ['phase' => 'during', 'start' => $start, 'end' => $end, 'nights' => $days - 1, 'day' => intdiv($now - $first, 86400) + 1, 'total' => $days];
    }
    return ['phase' => 'after', 'start' => $start, 'end' => $end, 'nights' => $days - 1];
}

function trip_snow_fetch(array $params): ?array
{
    $base = getenv('WEATHER_API_URL') ?: 'https://api.open-meteo.com/v1/forecast';
    $url = $base . '?' . http_build_query($params);
    $body = false;
    $code = 0;
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_USERAGENT => 'langkildelarsen-ski']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'header' => "User-Agent: langkildelarsen-ski\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = preg_match('~\s(\d{3})\s~', $http_response_header[0] ?? '', $m) ? (int)$m[1] : 0;
        $err = $body === false ? 'file_get_contents fejlede' : '';
    } else {
        $err = 'Serveren har hverken curl eller allow_url_fopen';
    }
    $j = is_string($body) ? json_decode($body, true) : null;
    if ($code === 200 && is_array($j) && !empty($j['hourly']['time'])) {
        return $j;
    }
    $reason = is_array($j) && !empty($j['reason']) ? $j['reason'] : ($err ?: (is_string($body) ? mb_substr(trim(strip_tags($body)), 0, 160) : 'intet svar'));
    save_json('trip-snow-error', ['t' => date('c'), 'code' => $code, 'msg' => $reason, 'elevation' => $params['elevation'] ?? null]);
    return null;
}

function trip_snow(): ?array
{
    $key = 'trip-snow-cache';
    $cache = load_json($key);
    if (($cache['t'] ?? 0) > time() - 3600 && !empty($cache['d'])) {
        return $cache['d'];
    }
    $tz = new DateTimeZone('Europe/Vienna');
    $nowHour = (new DateTime('now', $tz))->format('Y-m-d\\TH:00');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $spots = [];
    $days = [];
    foreach (TRIP['spots'] as $s) {
        $params = [
            'latitude' => TRIP['lat'],
            'longitude' => TRIP['lon'],
            'hourly' => 'snow_depth,snowfall,temperature_2m',
            'daily' => 'snowfall_sum',
            'past_days' => 7,
            'forecast_days' => 7,
            'timezone' => 'Europe/Vienna',
        ];
        $j = trip_snow_fetch($params + ['elevation' => $s['elevation']]) ?? trip_snow_fetch($params);
        if (!$j) {
            continue;
        }
        $hr = $j['hourly'];
        $idx = array_search($nowHour, $hr['time'], true);
        if ($idx === false) {
            $idx = 0;
            foreach ($hr['time'] as $i => $t) {
                if ($t <= $nowHour) {
                    $idx = $i;
                }
            }
        }
        $depth = null;
        for ($i = $idx; $i >= 0 && $depth === null; $i--) {
            $depth = $hr['snow_depth'][$i] ?? null;
        }
        $spots[] = [
            'label' => $s['label'],
            'elevation' => $s['elevation'],
            'depth' => (int)round(((float)$depth) * 100),
            'temp' => (int)round((float)($hr['temperature_2m'][$idx] ?? 0)),
            'falling' => (float)($hr['snowfall'][$idx] ?? 0) > 0,
        ];
        if (!$days) {
            foreach ($j['daily']['time'] ?? [] as $k => $t) {
                $days[] = ['d' => $t, 'cm' => round((float)($j['daily']['snowfall_sum'][$k] ?? 0), 1)];
            }
        }
    }
    if (!$spots) {
        return $cache['d'] ?? null;
    }
    $past = array_values(array_filter($days, fn($d) => $d['d'] < $today));
    $future = array_values(array_filter($days, fn($d) => $d['d'] >= $today));
    $last = null;
    foreach (array_reverse(array_values(array_filter($days, fn($d) => $d['d'] <= $today))) as $d) {
        if ($d['cm'] >= 0.5) {
            $last = $d;
            break;
        }
    }
    $d = [
        'spots' => $spots,
        'days' => $days,
        'today' => $today,
        'past7' => round(array_sum(array_column($past, 'cm'))),
        'next7' => round(array_sum(array_column($future, 'cm'))),
        'last' => $last,
    ];
    save_json($key, ['t' => time(), 'd' => $d]);
    return $d;
}

function trip_day_label(string $date, string $today): string
{
    $diff = (int)round((strtotime($today) - strtotime($date)) / 86400);
    if ($diff === 0) return 'i dag';
    if ($diff === 1) return 'i går';
    return 'for ' . $diff . ' dage siden';
}

function trip_snow_html(?array $s): string
{
    if (!$s) {
        $er = load_json('trip-snow-error');
        $detail = $er ? 'Fejl ' . ($er['code'] ?: '–') . ': ' . $er['msg'] : '';
        return '<div class="wx-empty"><p class="muted small">Snedata kunne ikke hentes lige nu.</p>' . ($detail ? '<p class="muted small" style="max-width: 320px; text-align: center; opacity: .7">' . e($detail) . '</p>' : '') . '</div>';
    }
    $h = '<div class="trip-spots">';
    foreach ($s['spots'] as $sp) {
        $h .= '<div class="trip-spot"><span class="trip-spot-l">' . e($sp['label']) . ' <small>' . number_format($sp['elevation'], 0, ',', '.') . ' m</small></span><span class="trip-spot-v">' . $sp['depth'] . '<small> cm</small></span><span class="trip-spot-s">' . ($sp['depth'] > 0 ? 'snedybde' : 'ingen sne') . ' · ' . $sp['temp'] . '°' . ($sp['falling'] ? ' · sner nu' : '') . '</span></div>';
    }
    $h .= '</div>';
    $max = max(1, ...array_map(fn($d) => $d['cm'], $s['days'] ?: [['cm' => 0]]));
    $dn = ['M', 'T', 'O', 'T', 'F', 'L', 'S'];
    $h .= '<div class="trip-bars" style="--n: ' . count($s['days']) . '">';
    foreach ($s['days'] as $d) {
        $cls = $d['d'] === $s['today'] ? ' now' : ($d['d'] > $s['today'] ? ' fc' : '');
        $h .= '<span class="trip-bar' . $cls . '" title="' . e(dk_date($d['d'])) . ': ' . str_replace('.', ',', (string)$d['cm']) . ' cm"><i style="--h: ' . round($d['cm'] / $max * 100) . '%"></i><small>' . $dn[(int)date('N', strtotime($d['d'])) - 1] . '</small></span>';
    }
    $h .= '</div>';
    $last = $s['last'] ? 'Sidst faldet ' . trip_day_label($s['last']['d'], $s['today']) . ' (' . str_replace('.', ',', (string)$s['last']['cm']) . ' cm)' : 'Ingen nysne den seneste uge';
    $h .= '<div class="trip-meta"><span class="trip-chip">' . icon('snow') . $s['past7'] . ' cm sidste 7 dage</span><span class="trip-chip">' . $s['next7'] . ' cm ventet næste 7 dage</span></div>';
    $h .= '<p class="trip-last muted small">' . e($last) . ' · bjerget, modelberegnet</p>';
    return $h;
}

function trip_card(): string
{
    $st = trip_status();
    $range = date('j', $st['start']) . '. dec. – ' . date('j', $st['end']) . '. jan.';
    $h = '<div class="trip-count" data-trip-start="' . $st['start'] . '">';
    if ($st['phase'] === 'before') {
        $h .= '<p class="trip-big"><span data-trip-d>' . $st['days'] . '</span><small> ' . ($st['days'] === 1 ? 'dag' : 'dage') . '</small></p>';
        $h .= '<p class="trip-sub"><span data-trip-hm>' . $st['hours'] . ' t ' . $st['mins'] . ' min</span> til afgang kl. ' . date('H:i', $st['start']) . ' · ' . e($range) . '</p>';
    } elseif ($st['phase'] === 'during') {
        $h .= '<p class="trip-big">Dag ' . $st['day'] . '<small> af ' . $st['total'] . '</small></p><p class="trip-sub">God tur på pisterne · ' . e($range) . '</p>';
    } else {
        $h .= '<p class="trip-big">Hjemme<small> igen</small></p><p class="trip-sub">Skiferien er slut · ' . e($range) . '</p>';
    }
    $h .= '</div>';
    $cache = load_json('trip-snow-cache');
    $fresh = ($cache['t'] ?? 0) > time() - 3600;
    $h .= '<div class="trip-snow" data-trip-snow' . ($fresh ? '' : ' data-trip-refresh') . '>';
    $h .= !empty($cache['d']) ? trip_snow_html($cache['d']) : '<div class="wx-empty" data-wx-load><span class="wx-spin"></span><p class="muted small">Henter snedata…</p></div>';
    return $h . '</div>';
}
