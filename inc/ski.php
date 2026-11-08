<?php

const SKI_COLORS = ['#5b9dff', '#3ecf6e', '#ff7a2f', '#f2c230', '#c77dff', '#ff5d8f', '#2ec4b6', '#ff4d4d'];

const SKI_CAMS = [
    ['name' => 'Unterberg', 'url' => 'https://pano.mayrhofen.at/unterberg', 'embed' => true],
    ['name' => 'Penken', 'url' => 'https://webtv.feratel.com/webtv/?design=v5&cam=5635&c2=0&c1=0', 'embed' => true],
    ['name' => 'Ahorn', 'url' => 'https://webtv.feratel.com/webtv/?design=v5&cam=5636&c2=0&c1=0', 'embed' => true],
    ['name' => 'Paulerhof', 'url' => 'https://webtv.feratel.com/webtv/?design=v5&cam=5639&c2=0&c1=0', 'embed' => true],
    ['name' => 'Flyingcam', 'url' => 'https://webtv.feratel.com/webtv/?design=v5&cam=75635&c2=0&c1=0', 'embed' => true],
    ['name' => 'Zimmereben', 'url' => 'https://pano.mayrhofen.at/zimmereben', 'embed' => true],
    ['name' => 'Brindlingalm', 'url' => 'https://pano.mayrhofen.at/brindlingalm', 'embed' => true],
    ['name' => 'Ginzling', 'url' => 'https://pano.mayrhofen.at/ginzling', 'embed' => true],
    ['name' => 'Coolnest', 'url' => 'https://pano.mayrhofen.at/coolnest', 'embed' => true],
];

const SKI_BOOKING = [
    'hotel' => 'Heart Hotel Nordstern',
    'board' => 'Med morgenmad',
    'room' => '1 familieværelse med 2 værelser i bygning B (Garni)',
    'checkin' => '2026-12-26',
    'checkout' => '2027-01-02',
    'included' => ['Liftkort Mayrhofen', 'Guideservice'],
    'travellers' => [
        ['name' => 'Emil Langkilde O. Larsen', 'role' => 'Rejsebestiller', 'price' => 6888, 'extra' => []],
        ['name' => 'Jeppe Smedegaard', 'role' => 'Rejsende', 'price' => 6888, 'extra' => [], 'gear' => 'snowboard'],
        ['name' => 'Gustav Langkilde O. Larsen', 'role' => 'Rejsende', 'price' => 6888, 'extra' => []],
        ['name' => 'Nikolaj Holm Iversen', 'role' => 'Rejsende', 'price' => 6888, 'extra' => []],
        ['name' => 'Nicolaj Baun Christensen', 'role' => 'Rejsende', 'price' => 6888, 'extra' => []],
    ],
];

const SKI_SUGGEST = ['Ski', 'Skistøvler', 'Skistave', 'Hjelm', 'Skibriller', 'Skijakke', 'Skibukser', 'Skiundertøj', 'Skisokker', 'Handsker eller luffer', 'Halsedisse', 'Hue', 'Solcreme og læbepomade', 'Pas', 'Blåt sygesikringskort', 'Oplader og powerbank', 'Badetøj', 'Kondomer'];
const SKI_SUGGEST_BOARD = ['Snowboard', 'Snowboardstøvler', 'Hjelm', 'Skibriller', 'Skijakke', 'Skibukser', 'Skiundertøj', 'Skisokker', 'Handsker eller luffer', 'Halsedisse', 'Hue', 'Solcreme og læbepomade', 'Pas', 'Blåt sygesikringskort', 'Oplader og powerbank', 'Badetøj', 'Kondomer'];

function ski_mutate(string $name, callable $fn)
{
    if (!is_dir(DATA_DIR . '/ski')) {
        mkdir(DATA_DIR . '/ski', 0755, true);
    }
    $lock = fopen(DATA_DIR . '/ski/.lock', 'c');
    flock($lock, LOCK_EX);
    $data = load_json('ski/' . $name);
    $result = $fn($data);
    save_json('ski/' . $name, $data);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $result;
}

function ski_members(): array
{
    return load_json('ski/members');
}

function ski_id(): string
{
    return bin2hex(random_bytes(6));
}

function ski_owner_from_cookie(): ?array
{
    $raw = (string)($_COOKIE['ll_remember'] ?? '');
    if ($raw === '' || !str_contains($raw, ':')) {
        return null;
    }
    [$uid, $token] = explode(':', $raw, 2);
    $u = users_all()[$uid] ?? null;
    if (!$u || ($u['tokens'][hash('sha256', $token)] ?? 0) <= time()) {
        return null;
    }
    $_SESSION['uid'] = $uid;
    return $u;
}

function ski_ensure_owner(array $user): string
{
    $id = 'o-' . user_slug($user['email']);
    $full = first_name($user);
    foreach (SKI_BOOKING['travellers'] as $t) {
        if ($t['role'] === 'Rejsebestiller') {
            $full = $t['name'];
        }
    }
    $members = ski_members();
    if (isset($members[$id]) && $members[$id]['name'] !== first_name($user)) {
        return $id;
    }
    ski_mutate('members', function (&$m) use ($id, $user, $full) {
        if (!isset($m[$id])) {
            $m[$id] = ['id' => $id, 'name' => $full, 'email' => $user['email'], 'role' => 'owner', 'hash' => '', 'tokens' => [], 'color' => ski_next_color($m), 'joined' => date('c')];
        } elseif ($m[$id]['name'] === first_name($user)) {
            $m[$id]['name'] = $full;
        }
    });
    return $id;
}

function ski_cookie(string $value, int $expires): void
{
    setcookie('ski_remember', $value, [
        'expires' => $expires,
        'path' => '/ski/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

function ski_remember(string $id): void
{
    $token = bin2hex(random_bytes(32));
    $expires = time() + 180 * 86400;
    ski_mutate('members', function (&$m) use ($id, $token, $expires) {
        if (isset($m[$id])) {
            $m[$id]['tokens'] = array_filter($m[$id]['tokens'] ?? [], fn($t) => $t > time());
            $m[$id]['tokens'][hash('sha256', $token)] = $expires;
        }
    });
    ski_cookie($id . ':' . $token, $expires);
}

function ski_current(): ?array
{
    $members = ski_members();
    if (!empty($_SESSION['ski_uid']) && isset($members[$_SESSION['ski_uid']])) {
        return $members[$_SESSION['ski_uid']];
    }
    $owner = current_user() ?? ski_owner_from_cookie();
    if ($owner && is_owner($owner)) {
        $id = ski_ensure_owner($owner);
        $_SESSION['ski_uid'] = $id;
        return ski_members()[$id];
    }
    $raw = (string)($_COOKIE['ski_remember'] ?? '');
    if ($raw !== '' && str_contains($raw, ':')) {
        [$id, $token] = explode(':', $raw, 2);
        if (($members[$id]['tokens'][hash('sha256', $token)] ?? 0) > time()) {
            $_SESSION['ski_uid'] = $id;
            return $members[$id];
        }
    }
    return null;
}

function ski_invite_token(): string
{
    $inv = load_json('ski/invite');
    return (string)($inv['token'] ?? '');
}

function ski_invite_new(): string
{
    $token = bin2hex(random_bytes(12));
    save_json('ski/invite', ['token' => $token, 'created' => date('c')]);
    return $token;
}

function ski_invite_valid(string $token): bool
{
    $real = ski_invite_token();
    return $real !== '' && $token !== '' && hash_equals($real, $token);
}

function ski_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'langkildelarsen.dk') . '/ski/';
}

function ski_initials(array $m): string
{
    $parts = preg_split('/\s+/', trim($m['name']));
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
}

function ski_avatar(array $m, string $class = ''): string
{
    $class .= !empty($m['pending']) ? ' pending' : '';
    $photo = (string)($m['photo'] ?? '');
    if ($photo !== '' && is_file(UPLOAD_DIR . '/' . $photo)) {
        return '<span class="sk-av photo' . ($class ? ' ' . trim($class) : '') . '" style="--c: ' . e($m['color']) . '; background-image: url(\'../uploads/' . e($photo) . '?v=' . filemtime(UPLOAD_DIR . '/' . $photo) . '\')" title="' . e($m['name']) . '"><i>' . e(ski_initials($m)) . '</i></span>';
    }
    return '<span class="sk-av' . ($class ? ' ' . trim($class) : '') . '" style="--c: ' . e($m['color']) . '" title="' . e($m['name']) . '">' . e(ski_initials($m)) . '</span>';
}

function ski_next_color(array $members): string
{
    $used = array_column($members, 'color');
    foreach (SKI_COLORS as $c) {
        if (!in_array($c, $used, true)) {
            return $c;
        }
    }
    return SKI_COLORS[count($members) % count(SKI_COLORS)];
}

function ski_join(string $name, string $email, string $password, string $claim = ''): array
{
    $email = mb_strtolower(trim($email));
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '' || mb_strlen($name) > 40) {
        return ['error' => 'Skriv dit navn.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'Skriv en gyldig mail.'];
    }
    if (mb_strlen($password) < 8) {
        return ['error' => 'Adgangskoden skal være mindst 8 tegn.'];
    }
    return ski_mutate('members', function (&$m) use ($name, $email, $password, $claim) {
        foreach ($m as $x) {
            if ($x['email'] !== '' && mb_strtolower($x['email']) === $email) {
                return ['error' => 'Den mail er allerede med. Log ind i stedet.'];
            }
        }
        $id = null;
        if ($claim !== '' && !empty($m[$claim]['pending'])) {
            $id = $claim;
        } else {
            $first = mb_strtolower(explode(' ', $name)[0]);
            $hits = array_filter($m, fn($x) => !empty($x['pending']) && mb_strtolower(explode(' ', $x['name'])[0]) === $first);
            if (count($hits) === 1) {
                $id = array_key_first($hits);
            }
        }
        if ($id) {
            $m[$id] = array_merge($m[$id], ['name' => $name, 'email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'tokens' => [], 'pending' => false, 'joined' => date('c')]);
            return ['id' => $id];
        }
        $id = 'm-' . ski_id();
        $m[$id] = ['id' => $id, 'name' => $name, 'email' => $email, 'role' => 'member', 'hash' => password_hash($password, PASSWORD_DEFAULT), 'tokens' => [], 'color' => ski_next_color($m), 'joined' => date('c')];
        return ['id' => $id];
    });
}

function ski_login(string $email, string $password): ?string
{
    $email = mb_strtolower(trim($email));
    foreach (ski_members() as $m) {
        if (mb_strtolower($m['email']) === $email && $m['hash'] !== '' && password_verify($password, $m['hash'])) {
            return $m['id'];
        }
    }
    $u = users_all()[$email] ?? null;
    if ($u && is_owner($u) && !empty($u['hash']) && password_verify($password, $u['hash'])) {
        return ski_ensure_owner($u);
    }
    return null;
}

function ski_runs(): array
{
    return load_json('ski/runs');
}

function ski_run_save(string $member, array $r): void
{
    $num = fn($v) => is_string($v) ? ski_parse_amount($v) : (float)$v;
    foreach (['km', 'max', 'descent'] as $k) {
        if (isset($r[$k])) {
            $r[$k] = $num($r[$k]);
        }
    }
    if (isset($r['time']) && preg_match('/^(\d{1,2})[:.,](\d{1,2})$/', trim((string)$r['time']), $tm)) {
        $r['mins'] = (int)$tm[1] * 60 + (int)$tm[2];
    } elseif (isset($r['time']) && is_numeric(trim((string)$r['time']))) {
        $r['mins'] = (float)$r['time'] * 60;
    }
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r['date'] ?? '')) ? $r['date'] : date('Y-m-d');
    if (($r['src'] ?? '') === 'slopes') {
        $r['id'] = 'd-' . preg_replace('/[^a-z0-9]/', '', $member) . '-' . $date;
        $r['date'] = $date;
    }
    $id = preg_replace('/[^a-z0-9-]/', '', (string)($r['id'] ?? '')) ?: ski_id();
    ski_mutate('runs', function (&$runs) use ($id, $member, $r) {
        if (isset($runs[$id]) && $runs[$id]['member'] !== $member) {
            return;
        }
        $runs[$id] = [
            'id' => $id,
            'member' => $member,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r['date'] ?? '')) ? $r['date'] : date('Y-m-d'),
            'km' => max(0, min(300, round((float)($r['km'] ?? 0), 2))),
            'max' => max(0, min(160, round((float)($r['max'] ?? 0), 1))),
            'descent' => max(0, min(30000, (int)round((float)($r['descent'] ?? 0)))),
            'mins' => max(0, min(1440, (int)round((float)($r['mins'] ?? 0)))),
            'runs' => max(0, min(500, (int)($r['runs'] ?? 0))),
            'src' => in_array($r['src'] ?? '', ['gps', 'strava', 'slopes'], true) ? $r['src'] : 'manual',
            'live' => !empty($r['live']),
            'updated' => date('c'),
        ];
    });
}

function ski_run_delete(string $member, string $id, bool $owner): void
{
    ski_mutate('runs', function (&$runs) use ($member, $id, $owner) {
        if (isset($runs[$id]) && ($runs[$id]['member'] === $member || $owner)) {
            unset($runs[$id]);
        }
    });
}

function ski_board(array $members, array $runs): array
{
    $rows = [];
    foreach ($members as $m) {
        $rows[$m['id']] = ['m' => $m, 'km' => 0.0, 'max' => 0.0, 'descent' => 0, 'runs' => 0, 'days' => [], 'live' => false];
    }
    foreach ($runs as $r) {
        if (!isset($rows[$r['member']])) {
            continue;
        }
        $row = &$rows[$r['member']];
        $row['km'] += $r['km'];
        $row['max'] = max($row['max'], $r['max']);
        $row['descent'] += $r['descent'];
        $row['runs'] += (int)($r['runs'] ?? 0);
        $row['days'][$r['date']] = true;
        if (!empty($r['live']) && strtotime($r['updated']) > time() - 300) {
            $row['live'] = true;
        }
        unset($row);
    }
    $rows = array_values($rows);
    usort($rows, fn($a, $b) => $b['max'] <=> $a['max'] ?: strcmp($a['m']['name'], $b['m']['name']));
    return $rows;
}

function ski_packing(string $member): array
{
    return load_json('ski/packing/' . preg_replace('/[^a-z0-9-]/', '', $member));
}

function ski_packing_mutate(string $member, callable $fn): void
{
    ski_mutate('packing/' . preg_replace('/[^a-z0-9-]/', '', $member), $fn);
}

function ski_shared(): array
{
    return load_json('ski/shared');
}

function ski_clean_text(string $t): string
{
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    return mb_substr(mb_strtoupper(mb_substr($t, 0, 1)) . mb_substr($t, 1), 0, 80);
}

function ski_km(float $v): string
{
    return number_format($v, $v >= 100 ? 0 : 1, ',', '.');
}

function ski_date(string $d): string
{
    $months = ['jan.', 'feb.', 'mar.', 'apr.', 'maj', 'jun.', 'jul.', 'aug.', 'sep.', 'okt.', 'nov.', 'dec.'];
    $days = ['man.', 'tir.', 'ons.', 'tor.', 'fre.', 'lør.', 'søn.'];
    $t = strtotime($d);
    return $days[(int)date('N', $t) - 1] . ' ' . date('j', $t) . '. ' . $months[(int)date('n', $t) - 1];
}

function ski_plan(): array
{
    $plan = load_json('ski/plan');
    if (!isset($plan['_seeded'])) {
        $plan = ski_mutate('plan', function (&$p) {
            if (!isset($p['_seeded'])) {
                $p['_seeded'] = date('c');
                $p['checkin'] = ['id' => 'checkin', 'date' => '2026-12-26', 'time' => '', 'title' => 'Check-ind på Heart Hotel Nordstern', 'note' => 'Familieværelse med 2 værelser i bygning B (Garni). Morgenmad er inkluderet.', 'by' => '', 'created' => date('c')];
                $p['checkout'] = ['id' => 'checkout', 'date' => '2027-01-02', 'time' => '', 'title' => 'Check-ud og hjemrejse', 'note' => '', 'by' => '', 'created' => date('c')];
                $p['nytaar'] = ['id' => 'nytaar', 'date' => '2026-12-31', 'time' => '20:30', 'title' => 'Nytårsaften: Silvestermenü', 'note' => 'Nytårspakke med velkomstdrinks, mad og minigolf. Bestilt til 5 personer (50 € pr. person) via Visorium. Afventer bekræftelse fra stedet.', 'by' => '', 'created' => date('c')];
            }
            return $p;
        });
    }
    if (!isset($plan['_seeded2'])) {
        $plan = ski_mutate('plan', function (&$p) {
            if (!isset($p['_seeded2'])) {
                $p['_seeded2'] = date('c');
                $p['afgang'] = ['id' => 'afgang', 'date' => '2026-12-25', 'time' => '16:30', 'title' => 'Afgang fra Blåvand', 'note' => 'Alle i samme bil direkte til Mayrhofen. Ca. 12 timers kørsel plus 3 timer til pauser og indkøb.', 'by' => '', 'created' => date('c')];
                $p['ankomst'] = ['id' => 'ankomst', 'date' => '2026-12-26', 'time' => '07:30', 'title' => 'Ankomst i Mayrhofen', 'note' => 'Halvanden time til parkering, bagage, skiudstyr på og liftkort, inden liften.', 'by' => '', 'created' => date('c')];
                $p['lift'] = ['id' => 'lift', 'date' => '2026-12-26', 'time' => '09:00', 'title' => 'Første tur med liften', 'note' => '', 'by' => '', 'created' => date('c')];
            }
            return $p;
        });
    }
    if (!isset($plan['_seeded3'])) {
        $plan = ski_mutate('plan', function (&$p) {
            if (!isset($p['_seeded3'])) {
                $p['_seeded3'] = date('c');
                $p['checkout'] = ['id' => 'checkout', 'date' => '2027-01-02', 'time' => '10:00', 'title' => 'Check-ud senest kl. 10:00', 'note' => 'Værelset skal være tømt, inden vi kører hjem.', 'by' => '', 'created' => $p['checkout']['created'] ?? date('c')];
            }
            return $p;
        });
    }
    unset($plan['_seeded'], $plan['_seeded2'], $plan['_seeded3']);
    uasort($plan, fn($a, $b) => strcmp($a['date'] . ($a['time'] ?: '99'), $b['date'] . ($b['time'] ?: '99')));
    return $plan;
}

function ski_trip_days(): array
{
    $out = [];
    $d = new DateTime(TRIP['start']);
    $end = new DateTime(TRIP['end']);
    while ($d <= $end) {
        $out[] = $d->format('Y-m-d');
        $d->modify('+1 day');
    }
    return $out;
}

function ski_traveller_member(string $name, array $members): ?array
{
    $pid = 'p-' . ski_slug($name);
    if (isset($members[$pid])) {
        return $members[$pid];
    }
    $first = mb_strtolower(explode(' ', trim($name))[0]);
    foreach ($members as $m) {
        if (mb_strtolower(explode(' ', trim($m['name']))[0]) === $first) {
            return $m;
        }
    }
    return null;
}

function ski_kr(float $v): string
{
    return number_format($v, 0, ',', '.') . ' kr.';
}

function ski_slug(string $s): string
{
    $s = strtr(mb_strtolower($s), ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue']);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

function ski_invites(): array
{
    return load_json('ski/invites');
}

function ski_invite_find(string $token): ?array
{
    if (preg_match('/([a-f0-9]{32})$/', $token, $mm)) {
        $token = $mm[1];
    }
    if ($token === '') {
        return null;
    }
    $h = hash('sha256', $token);
    foreach (ski_invites() as $inv) {
        if (hash_equals($inv['hash'], $h)) {
            return $inv;
        }
    }
    return null;
}

function ski_invite_for(string $name): array
{
    $key = ski_slug($name);
    $inv = ski_invites()[$key] ?? null;
    if (!$inv || empty($inv['token'])) {
        $token = bin2hex(random_bytes(16));
        ski_mutate('invites', function (&$l) use ($key, $name, $token) {
            if (empty($l[$key]['token'])) {
                $l[$key] = ['key' => $key, 'name' => $name, 'email' => '', 'hash' => hash('sha256', $token), 'token' => $token, 'sent' => date('c'), 'used' => ''];
            }
        });
        $inv = ski_invites()[$key];
    }
    return $inv + ['url' => ski_base_url() . '?invite=' . ski_slug(explode(' ', $name)[0]) . '-' . $inv['token']];
}

function ski_app_token(string $id): string
{
    $members = ski_members();
    if (!empty($members[$id]['app'])) {
        return $members[$id]['app'];
    }
    $token = bin2hex(random_bytes(20));
    ski_mutate('members', function (&$m) use ($id, $token) {
        if (isset($m[$id]) && empty($m[$id]['app'])) {
            $m[$id]['app'] = $token;
        }
    });
    return ski_members()[$id]['app'] ?? $token;
}

function ski_app_login(string $raw): ?string
{
    if (!str_contains($raw, '.')) {
        return null;
    }
    [$id, $token] = explode('.', $raw, 2);
    $m = ski_members()[$id] ?? null;
    return $m && !empty($m['app']) && hash_equals($m['app'], $token) ? $id : null;
}

const SKI_EUR = 7.46;

function ski_expenses(): array
{
    $l = load_json('ski/expenses');
    uasort($l, fn($a, $b) => strcmp($b['created'], $a['created']));
    return $l;
}

function ski_money(int $ore): string
{
    return number_format($ore / 100, $ore % 100 ? 2 : 0, ',', '.') . ' kr.';
}

function ski_balances(array $expenses, array $members): array
{
    $bal = array_fill_keys(array_keys($members), 0);
    $spent = array_fill_keys(array_keys($members), 0);
    $total = 0;
    foreach ($expenses as $x) {
        $split = array_values(array_filter($x['split'], fn($id) => isset($members[$id])));
        if (!$split || !isset($members[$x['by']])) {
            continue;
        }
        $amt = (int)$x['ore'];
        $base = intdiv($amt, count($split));
        $rest = $amt - $base * count($split);
        $bal[$x['by']] += $amt;
        foreach ($split as $i => $id) {
            $share = $base + ($i < $rest ? 1 : 0);
            $bal[$id] -= $share;
            if (($x['type'] ?? 'expense') === 'expense') {
                $spent[$id] += $share;
            }
        }
        if (($x['type'] ?? 'expense') === 'expense') {
            $total += $amt;
        }
    }
    $debt = [];
    $cred = [];
    foreach ($bal as $id => $v) {
        if ($v < 0) $debt[$id] = -$v;
        if ($v > 0) $cred[$id] = $v;
    }
    arsort($debt);
    arsort($cred);
    $moves = [];
    while ($debt && $cred) {
        $d = array_key_first($debt);
        $c = array_key_first($cred);
        $amt = min($debt[$d], $cred[$c]);
        if ($amt > 0) {
            $moves[] = ['from' => $d, 'to' => $c, 'ore' => $amt];
        }
        $debt[$d] -= $amt;
        $cred[$c] -= $amt;
        if ($debt[$d] <= 0) unset($debt[$d]);
        if ($cred[$c] <= 0) unset($cred[$c]);
        arsort($debt);
        arsort($cred);
    }
    return ['bal' => $bal, 'spent' => $spent, 'total' => $total, 'moves' => $moves];
}

function ski_parse_amount(string $s): float
{
    $s = trim(str_replace([' ', 'kr', '€', ' '], '', $s));
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    return (float)str_replace(',', '.', $s);
}

function ski_seed_travellers(): void
{
    if (load_json('ski/seed-travellers')) {
        return;
    }
    ski_mutate('members', function (&$m) {
        foreach (SKI_BOOKING['travellers'] as $t) {
            if ($t['role'] === 'Rejsebestiller') {
                continue;
            }
            $first = mb_strtolower(explode(' ', $t['name'])[0]);
            $exists = array_filter($m, fn($x) => mb_strtolower(explode(' ', $x['name'])[0]) === $first);
            if ($exists) {
                continue;
            }
            $id = 'p-' . ski_slug($t['name']);
            $m[$id] = ['id' => $id, 'name' => $t['name'], 'email' => '', 'role' => 'member', 'hash' => '', 'tokens' => [], 'color' => ski_next_color($m), 'pending' => true, 'joined' => ''];
        }
    });
    save_json('ski/seed-travellers', ['t' => date('c')]);
}

function ski_fix_early_expenses(): void
{
    if (load_json('ski/fix-split')) {
        return;
    }
    $all = array_keys(ski_members());
    ski_mutate('expenses', function (&$l) use ($all) {
        foreach ($l as $id => $x) {
            if (($x['type'] ?? 'expense') === 'expense' && count($x['split']) === 1 && $x['split'][0] === $x['by']) {
                $l[$id]['split'] = $all;
            }
        }
    });
    save_json('ski/fix-split', ['t' => date('c')]);
}

function ski_suggestions(array $member): array
{
    $first = mb_strtolower(explode(' ', trim($member['name']))[0]);
    foreach (SKI_BOOKING['travellers'] as $t) {
        if (mb_strtolower(explode(' ', $t['name'])[0]) === $first && ($t['gear'] ?? 'ski') === 'snowboard') {
            return SKI_SUGGEST_BOARD;
        }
    }
    return SKI_SUGGEST;
}

function ski_packing_fill(array $member): void
{
    $id = $member['id'];
    ski_packing_mutate($id, function (&$l) use ($member) {
        if (!empty($l['_filled'])) {
            if (empty($l['_v2'])) {
                $have = array_map('mb_strtolower', array_column(array_filter($l, 'is_array'), 'text'));
                if (!in_array('kondomer', $have, true)) {
                    $iid = ski_id();
                    $l[$iid] = ['id' => $iid, 'text' => 'Kondomer', 'done' => false, 'created' => date('c')];
                }
                $l['_v2'] = date('c');
            }
            return;
        }
        $l['_v2'] = date('c');
        $have = array_map('mb_strtolower', array_column(array_filter($l, 'is_array'), 'text'));
        $t = time();
        foreach (ski_suggestions($member) as $i => $text) {
            if (!in_array(mb_strtolower($text), $have, true)) {
                $iid = ski_id();
                $l[$iid] = ['id' => $iid, 'text' => $text, 'done' => false, 'created' => date('c', $t + $i)];
            }
        }
        $l['_filled'] = date('c');
    });
}

const SKI_PHOTO_SEED = [
    'Gustav' => 'https://scontent-cph2-1.xx.fbcdn.net/v/t39.30808-1/608191568_26715145121406732_8039333338369676891_n.jpg?stp=dst-jpg_tt6&cstp=mx1536x1537&ctp=s200x200&_nc_cat=108&ccb=1-7&_nc_sid=e99d92&_nc_ohc=olFmo5vwwN0Q7kNvwEl8IkW&_nc_oc=AdozsgOD-gpoedzBoV7T1y1e9NYSAwpdULWqEksGuTvXmfGqVY5e_YBBB8kpmx2OKfE&_nc_zt=24&_nc_ht=scontent-cph2-1.xx&_nc_gid=Fo2LmSX6iHOJOJLpGE74vg&_nc_ss=7b2a8&oh=00_AQSqiG-HAIK58mUmWytQzWiaGng0h0qPlhYuw24pakfDzw&oe=6AF698E3',
    'Jeppe' => 'https://scontent-cph2-1.xx.fbcdn.net/v/t39.30808-6/889652672_10232412919281385_9043823492813431313_n.jpg?stp=dst-jpg_tt6&cstp=mx960x957&ctp=s960x957&_nc_cat=107&ccb=1-7&_nc_sid=6ee11a&_nc_ohc=NkFHpr0pMdEQ7kNvwEeYooM&_nc_oc=Adqatk2qpfd6Pj_m95wW7G8nx5jk12hyliZtw_rC6TBSmIaxOqOpmkqqk9r1dZ0IK70&_nc_zt=23&_nc_ht=scontent-cph2-1.xx&_nc_gid=mu7KtzIZ5SR8lfG0hRHN8A&_nc_ss=7b2a8&oh=00_AQQ_ljsN53OL_v5JEt_b-ruquH5i_FacYtrpwAjICwYKvw&oe=6AF6A938',
    'Nikolaj Holm' => 'https://scontent-cph2-1.xx.fbcdn.net/v/t1.6435-9/79136604_3786825631331470_4661710644985200640_n.jpg?stp=dst-jpg_tt6&cstp=mx526x526&ctp=s526x526&_nc_cat=111&ccb=1-7&_nc_sid=6ee11a&_nc_ohc=_phXMJ0SI-oQ7kNvwHE2Ep5&_nc_oc=Adp2S82-P8dW2lLOz6_5ePQpzuFYljY4ydlSpMXK8TDO6u-y62MdgS5IxBmSMKbc_hw&_nc_zt=23&_nc_ht=scontent-cph2-1.xx&_nc_gid=0dhy1vRTMvoYGaIPuhjHSg&_nc_ss=7b2a8&oh=00_AQSVrBMADnDuzOa0mhcKymy5ibxRWNVcSUp0q2CDIxMbQA&oe=6B18354D',
];

function ski_photo_save(string $member, string $bytes): string
{
    if (strlen($bytes) < 200 || strlen($bytes) > 12000000) {
        return 'Billedet er for stort eller tomt.';
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        return 'Filen er ikke et billede.';
    }
    $dir = UPLOAD_DIR . '/ski';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = preg_replace('/[^a-z0-9-]/', '', $member) . '-' . bin2hex(random_bytes(5)) . '.jpg';
    $ok = false;
    if (function_exists('imagecreatefromstring') && ($src = @imagecreatefromstring($bytes))) {
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $out = imagecreatetruecolor(320, 320);
        imagecopyresampled($out, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), 320, 320, $side, $side);
        $ok = imagejpeg($out, $dir . '/' . $name, 86);
        imagedestroy($src);
        imagedestroy($out);
    }
    if (!$ok) {
        if ($info[2] !== IMAGETYPE_JPEG) {
            return 'Billedet kunne ikke behandles.';
        }
        file_put_contents($dir . '/' . $name, $bytes);
    }
    $old = '';
    ski_mutate('members', function (&$m) use ($member, $name, &$old) {
        if (isset($m[$member])) {
            $old = (string)($m[$member]['photo'] ?? '');
            $m[$member]['photo'] = 'ski/' . $name;
        }
    });
    if ($old !== '' && str_starts_with($old, 'ski/') && is_file(UPLOAD_DIR . '/' . $old)) {
        @unlink(UPLOAD_DIR . '/' . $old);
    }
    return '';
}

function ski_photo_fetch(string $member, string $url): string
{
    if (!preg_match('~^https://~i', $url) || !function_exists('curl_init')) {
        return 'Linket skal starte med https://';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15', CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $code !== 200) {
        return 'Billedet kunne ikke hentes (' . ($code ?: 'ingen forbindelse') . '). Linket er måske udløbet.';
    }
    return ski_photo_save($member, $body);
}

function ski_photo_seed(): void
{
    $done = load_json('ski/photo-seed');
    if (($done['_t'] ?? 0) > time() - 600) {
        return;
    }
    $done['_t'] = time();
    save_json('ski/photo-seed', $done);
    $members = ski_members();
    $changed = false;
    foreach (SKI_PHOTO_SEED as $prefix => $url) {
        if (!empty($done[$prefix]) && ($done[$prefix]['ok'] || $done[$prefix]['tries'] >= 5)) {
            continue;
        }
        $hit = null;
        foreach ($members as $m) {
            if (stripos($m['name'], $prefix) === 0) {
                $hit = $m;
                break;
            }
        }
        if (!$hit || !empty($hit['photo'])) {
            $done[$prefix] = ['ok' => (bool)$hit, 'tries' => ($done[$prefix]['tries'] ?? 0) + 1, 'msg' => ''];
            $changed = true;
            continue;
        }
        $err = ski_photo_fetch($hit['id'], $url);
        $done[$prefix] = ['ok' => $err === '', 'tries' => ($done[$prefix]['tries'] ?? 0) + 1, 'msg' => $err];
        $changed = true;
    }
    if ($changed) {
        save_json('ski/photo-seed', $done);
    }
}
