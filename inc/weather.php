<?php

const WEATHER_DEFAULT = ['name' => 'Esbjerg', 'lat' => 55.4765, 'lon' => 8.4594];

function weather_place(): array
{
    $ui = load_json(ukey('ui'));
    $w = $ui['weather'] ?? [];
    return isset($w['lat'], $w['lon'], $w['name']) ? $w : WEATHER_DEFAULT;
}

function weather_http(string $url): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_USERAGENT => 'langkildelarsen-dashboard']);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400) {
        return null;
    }
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : null;
}

function weather_geocode(string $q): ?array
{
    $j = weather_http('https://geocoding-api.open-meteo.com/v1/search?count=1&language=da&format=json&name=' . rawurlencode($q));
    $r = $j['results'][0] ?? null;
    if (!$r) {
        return null;
    }
    return ['name' => $r['name'], 'lat' => round((float)$r['latitude'], 4), 'lon' => round((float)$r['longitude'], 4)];
}

function weather_get(): ?array
{
    $p = weather_place();
    $key = 'weather-cache';
    $cache = load_json($key);
    $id = $p['lat'] . ',' . $p['lon'];
    if (($cache[$id]['t'] ?? 0) > time() - 1200) {
        return $cache[$id]['d'];
    }
    $base = getenv('WEATHER_API_URL') ?: 'https://api.open-meteo.com/v1/forecast';
    $j = weather_http($base . '?' . http_build_query([
        'latitude' => $p['lat'],
        'longitude' => $p['lon'],
        'current' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,relative_humidity_2m,is_day,precipitation',
        'hourly' => 'temperature_2m,weather_code,precipitation_probability,is_day',
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
        'timezone' => 'Europe/Copenhagen',
        'forecast_days' => 6,
        'wind_speed_unit' => 'ms',
    ]));
    if (!$j || empty($j['current'])) {
        return $cache[$id]['d'] ?? null;
    }
    $now = time();
    $hours = [];
    foreach ($j['hourly']['time'] ?? [] as $i => $t) {
        $ts = strtotime($t);
        if ($ts < $now - 3600) {
            continue;
        }
        $hours[] = ['t' => date('H', $ts), 'temp' => (int)round($j['hourly']['temperature_2m'][$i]), 'code' => (int)$j['hourly']['weather_code'][$i], 'day' => (int)$j['hourly']['is_day'][$i], 'rain' => (int)($j['hourly']['precipitation_probability'][$i] ?? 0)];
        if (count($hours) >= 12) {
            break;
        }
    }
    $days = [];
    foreach ($j['daily']['time'] ?? [] as $i => $t) {
        $days[] = ['d' => $t, 'code' => (int)$j['daily']['weather_code'][$i], 'max' => (int)round($j['daily']['temperature_2m_max'][$i]), 'min' => (int)round($j['daily']['temperature_2m_min'][$i]), 'rain' => (int)($j['daily']['precipitation_probability_max'][$i] ?? 0), 'rise' => substr((string)$j['daily']['sunrise'][$i], 11, 5), 'set' => substr((string)$j['daily']['sunset'][$i], 11, 5)];
    }
    $c = $j['current'];
    $d = [
        'place' => $p['name'],
        'temp' => (int)round($c['temperature_2m']),
        'feels' => (int)round($c['apparent_temperature']),
        'code' => (int)$c['weather_code'],
        'day' => (int)$c['is_day'],
        'wind' => round((float)$c['wind_speed_10m'], 1),
        'hum' => (int)$c['relative_humidity_2m'],
        'hours' => $hours,
        'days' => $days,
    ];
    $cache[$id] = ['t' => $now, 'd' => $d];
    save_json($key, $cache);
    return $d;
}

function weather_info(int $code, int $day = 1): array
{
    $map = [
        0 => ['Klart', $day ? 'sun' : 'moon'],
        1 => ['Mest klart', $day ? 'sun' : 'moon'],
        2 => ['Delvist skyet', 'cloud'],
        3 => ['Overskyet', 'cloud'],
        45 => ['Tåge', 'wind'],
        48 => ['Rimtåge', 'wind'],
        51 => ['Let støvregn', 'drop'],
        53 => ['Støvregn', 'drop'],
        55 => ['Kraftig støvregn', 'drop'],
        56 => ['Isslag', 'drop'],
        57 => ['Isslag', 'drop'],
        61 => ['Let regn', 'rain'],
        63 => ['Regn', 'rain'],
        65 => ['Kraftig regn', 'rain'],
        66 => ['Isregn', 'rain'],
        67 => ['Isregn', 'rain'],
        71 => ['Let sne', 'snow'],
        73 => ['Sne', 'snow'],
        75 => ['Kraftig sne', 'snow'],
        77 => ['Snefnug', 'snow'],
        80 => ['Byger', 'rain'],
        81 => ['Regnbyger', 'rain'],
        82 => ['Skybrud', 'rain'],
        85 => ['Snebyger', 'snow'],
        86 => ['Snebyger', 'snow'],
        95 => ['Torden', 'storm'],
        96 => ['Torden med hagl', 'storm'],
        99 => ['Torden med hagl', 'storm'],
    ];
    return $map[$code] ?? ['Ukendt', 'cloud'];
}

function weather_quip(array $w): string
{
    $t = $w['temp'];
    $c = $w['code'];
    if ($c >= 95) return 'Torden på vej. Hold dig inde og lad Jarvis passe på dig.';
    if ($c >= 71 && $c <= 86 && !in_array($c, [80, 81, 82], true)) return 'Sne! Find vanterne frem.';
    if ($c >= 51) return 'Tag paraplyen med – eller bare hætten op.';
    if ($w['wind'] >= 10) return 'Det blæser godt. Hold fast i kaffen.';
    if ($t <= 0) return 'Frostvejr. Husk at skrabe ruderne.';
    if ($t >= 25) return 'Solbriller på – det er sommer for alvor.';
    if ($c <= 1 && $w['day']) return 'Flot vejr. Perfekt til en tur ud.';
    return 'Helt almindeligt dansk vejr. Jakken klarer den.';
}
