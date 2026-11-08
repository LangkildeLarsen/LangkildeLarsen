<?php

const TRIP = [
    'title' => 'Skiferie',
    'place' => 'Mayrhofen',
    'country' => 'Østrig',
    'start' => '2026-12-25',
    'end' => '2027-01-02',
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
    $start = (new DateTime(TRIP['start'], $tz))->getTimestamp();
    $end = (new DateTime(TRIP['end'] . ' 23:59:59', $tz))->getTimestamp();
    $days = (int)round(($end - $start) / 86400);
    if ($now < $start) {
        $left = $start - $now;
        return ['phase' => 'before', 'start' => $start, 'end' => $end, 'nights' => $days - 1, 'days' => intdiv($left, 86400), 'hours' => intdiv($left % 86400, 3600), 'mins' => intdiv($left % 3600, 60)];
    }
    if ($now <= $end) {
        return ['phase' => 'during', 'start' => $start, 'end' => $end, 'nights' => $days - 1, 'day' => intdiv($now - $start, 86400) + 1, 'total' => $days];
    }
    return ['phase' => 'after', 'start' => $start, 'end' => $end, 'nights' => $days - 1];
}

function trip_snow(): ?array
{
    $key = 'trip-snow-cache';
    $cache = load_json($key);
    if (($cache['t'] ?? 0) > time() - 3600 && !empty($cache['d'])) {
        return $cache['d'];
    }
    $base = getenv('WEATHER_API_URL') ?: 'https://api.open-meteo.com/v1/forecast';
    $spots = [];
    $days = [];
    foreach (TRIP['spots'] as $i => $s) {
        $j = weather_http($base . '?' . http_build_query([
            'latitude' => TRIP['lat'],
            'longitude' => TRIP['lon'],
            'elevation' => $s['elevation'],
            'current' => 'snow_depth,temperature_2m,snowfall',
            'daily' => 'snowfall_sum,temperature_2m_max,temperature_2m_min',
            'past_days' => 7,
            'forecast_days' => 7,
            'timezone' => 'Europe/Vienna',
        ]));
        if (!$j || !isset($j['current'])) {
            return $cache['d'] ?? null;
        }
        $spots[] = [
            'label' => $s['label'],
            'elevation' => $s['elevation'],
            'depth' => (int)round(((float)($j['current']['snow_depth'] ?? 0)) * 100),
            'temp' => (int)round((float)($j['current']['temperature_2m'] ?? 0)),
            'falling' => (float)($j['current']['snowfall'] ?? 0) > 0,
        ];
        if ($i === 0) {
            foreach ($j['daily']['time'] ?? [] as $k => $t) {
                $days[] = ['d' => $t, 'cm' => round((float)($j['daily']['snowfall_sum'][$k] ?? 0), 1)];
            }
        }
    }
    $today = (new DateTime('now', new DateTimeZone('Europe/Vienna')))->format('Y-m-d');
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
        return '<div class="wx-empty"><p class="muted small">Snedata kunne ikke hentes lige nu.</p></div>';
    }
    $h = '<div class="trip-spots">';
    foreach ($s['spots'] as $sp) {
        $h .= '<div class="trip-spot"><span class="trip-spot-l">' . e($sp['label']) . ' <small>' . number_format($sp['elevation'], 0, ',', '.') . ' m</small></span><span class="trip-spot-v">' . $sp['depth'] . '<small> cm</small></span><span class="trip-spot-s">' . ($sp['depth'] > 0 ? 'snedybde' : 'ingen sne') . ' · ' . $sp['temp'] . '°' . ($sp['falling'] ? ' · sner nu' : '') . '</span></div>';
    }
    $h .= '</div>';
    $max = max(1, ...array_map(fn($d) => $d['cm'], $s['days']));
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
        $h .= '<p class="trip-sub"><span data-trip-hm>' . $st['hours'] . ' t ' . $st['mins'] . ' min</span> til afrejse · ' . e($range) . '</p>';
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
