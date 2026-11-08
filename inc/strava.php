<?php

const STRAVA_SPORTS = ['AlpineSki', 'BackcountrySki', 'Snowboard'];
const STRAVA_FROM = '2026-12-25';

function strava_base(): string
{
    return getenv('STRAVA_BASE') ?: 'https://www.strava.com';
}

function strava_cfg(): array
{
    return load_json('ski/strava');
}

function strava_ready(): bool
{
    $c = strava_cfg();
    return !empty($c['client_id']) && !empty($c['client_secret']);
}

function strava_redirect_uri(): string
{
    return ski_base_url() . 'strava.php';
}

function strava_auth_url(string $state): string
{
    return 'https://www.strava.com/oauth/authorize?' . http_build_query([
        'client_id' => strava_cfg()['client_id'] ?? '',
        'redirect_uri' => strava_redirect_uri(),
        'response_type' => 'code',
        'approval_prompt' => 'auto',
        'scope' => 'read,activity:read_all',
        'state' => $state,
    ]);
}

function strava_http(string $method, string $url, array $data = [], string $token = ''): array
{
    if (!function_exists('curl_init')) {
        return ['code' => 0, 'body' => null, 'err' => 'curl mangler på serveren'];
    }
    $ch = curl_init($method === 'GET' && $data ? $url . '?' . http_build_query($data) : $url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_USERAGENT => 'langkildelarsen-ski'];
    if ($token !== '') {
        $opts[CURLOPT_HTTPHEADER] = ['Authorization: Bearer ' . $token];
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => is_string($body) ? json_decode($body, true) : null, 'err' => $err];
}

function strava_tokens(): array
{
    return load_json('ski/strava-tokens');
}

function strava_exchange(string $code): array
{
    $c = strava_cfg();
    $r = strava_http('POST', strava_base() . '/oauth/token', [
        'client_id' => $c['client_id'] ?? '',
        'client_secret' => $c['client_secret'] ?? '',
        'code' => $code,
        'grant_type' => 'authorization_code',
    ]);
    if ($r['code'] !== 200 || empty($r['body']['access_token'])) {
        return ['error' => strava_error($r)];
    }
    return $r['body'];
}

function strava_error(array $r): string
{
    $b = $r['body'];
    if (is_array($b)) {
        $msg = $b['message'] ?? '';
        if (!empty($b['errors'][0])) {
            $msg .= ' (' . trim(($b['errors'][0]['resource'] ?? '') . ' ' . ($b['errors'][0]['field'] ?? '') . ' ' . ($b['errors'][0]['code'] ?? '')) . ')';
        }
        if ($msg !== '') {
            return 'Strava ' . $r['code'] . ': ' . $msg;
        }
    }
    return $r['err'] !== '' ? $r['err'] : 'Strava svarede ' . $r['code'];
}

function strava_save_token(string $member, array $t): void
{
    ski_mutate('strava-tokens', function (&$l) use ($member, $t) {
        $l[$member] = [
            'access' => $t['access_token'],
            'refresh' => $t['refresh_token'],
            'expires' => (int)$t['expires_at'],
            'athlete' => (int)($t['athlete']['id'] ?? ($l[$member]['athlete'] ?? 0)),
            'name' => trim(($t['athlete']['firstname'] ?? ($l[$member]['name'] ?? '')) . ' ' . ($t['athlete']['lastname'] ?? '')),
            'connected' => $l[$member]['connected'] ?? date('c'),
            'synced' => $l[$member]['synced'] ?? '',
            'error' => '',
        ];
    });
}

function strava_disconnect(string $member): void
{
    $tok = strava_tokens()[$member] ?? null;
    if ($tok) {
        strava_http('POST', strava_base() . '/oauth/deauthorize', ['access_token' => $tok['access']]);
    }
    ski_mutate('strava-tokens', function (&$l) use ($member) {
        unset($l[$member]);
    });
}

function strava_access(string $member): ?string
{
    $tok = strava_tokens()[$member] ?? null;
    if (!$tok) {
        return null;
    }
    if ($tok['expires'] > time() + 120) {
        return $tok['access'];
    }
    $c = strava_cfg();
    $r = strava_http('POST', strava_base() . '/oauth/token', [
        'client_id' => $c['client_id'] ?? '',
        'client_secret' => $c['client_secret'] ?? '',
        'grant_type' => 'refresh_token',
        'refresh_token' => $tok['refresh'],
    ]);
    if ($r['code'] !== 200 || empty($r['body']['access_token'])) {
        strava_mark($member, ['error' => strava_error($r)]);
        return null;
    }
    strava_save_token($member, $r['body']);
    return $r['body']['access_token'];
}

function strava_mark(string $member, array $fields): void
{
    ski_mutate('strava-tokens', function (&$l) use ($member, $fields) {
        if (isset($l[$member])) {
            $l[$member] = array_merge($l[$member], $fields);
        }
    });
}

function strava_sync_member(string $member): array
{
    $token = strava_access($member);
    if (!$token) {
        return ['ok' => false, 'n' => 0];
    }
    $after = (new DateTime(STRAVA_FROM . ' 00:00', new DateTimeZone('Europe/Vienna')))->getTimestamp();
    $r = strava_http('GET', strava_base() . '/api/v3/athlete/activities', ['after' => $after, 'per_page' => 100], $token);
    if ($r['code'] !== 200 || !is_array($r['body'])) {
        strava_mark($member, ['error' => strava_error($r), 'synced' => date('c')]);
        return ['ok' => false, 'n' => 0];
    }
    $n = 0;
    foreach ($r['body'] as $a) {
        $sport = $a['sport_type'] ?? ($a['type'] ?? '');
        if (!in_array($sport, STRAVA_SPORTS, true)) {
            continue;
        }
        ski_run_save($member, [
            'id' => 'strava-' . (int)$a['id'],
            'date' => substr((string)($a['start_date_local'] ?? ''), 0, 10),
            'km' => ((float)($a['distance'] ?? 0)) / 1000,
            'max' => ((float)($a['max_speed'] ?? 0)) * 3.6,
            'descent' => (float)($a['total_elevation_gain'] ?? 0),
            'mins' => ((int)($a['moving_time'] ?? 0)) / 60,
            'src' => 'strava',
        ]);
        $n++;
    }
    strava_mark($member, ['error' => '', 'synced' => date('c')]);
    return ['ok' => true, 'n' => $n];
}

function strava_sync_all(bool $force = false): void
{
    if (!strava_ready()) {
        return;
    }
    $state = load_json('ski/strava-sync');
    if (!$force && ($state['t'] ?? 0) > time() - 600) {
        return;
    }
    save_json('ski/strava-sync', ['t' => time()]);
    foreach (array_keys(strava_tokens()) as $member) {
        strava_sync_member($member);
    }
}
