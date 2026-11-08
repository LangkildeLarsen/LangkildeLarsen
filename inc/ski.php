<?php

const SKI_COLORS = ['#5b9dff', '#3ecf6e', '#ff7a2f', '#f2c230', '#c77dff', '#ff5d8f', '#2ec4b6', '#ff4d4d'];

const SKI_CAMS = [
    ['name' => 'Penken', 'url' => 'https://webtv.feratel.com/webtv/?design=v5&cam=5635&c2=0&c1=0', 'embed' => true],
    ['name' => 'Ahorn', 'url' => 'https://webtv.feratel.com/webtv/?design=v5&cam=5636&c2=0&c1=0', 'embed' => true],
    ['name' => 'Paulerhof', 'url' => 'https://webtv.feratel.com/webtv/?design=v5&cam=5639&c2=0&c1=0', 'embed' => true],
    ['name' => 'Flyingcam', 'url' => 'https://webtv.feratel.com/webtv/?design=v5&cam=75635&c2=0&c1=0', 'embed' => true],
    ['name' => 'Zimmereben', 'url' => 'https://pano.mayrhofen.at/zimmereben', 'embed' => false],
    ['name' => 'Brindlingalm', 'url' => 'https://pano.mayrhofen.at/brindlingalm', 'embed' => false],
    ['name' => 'Ginzling', 'url' => 'https://pano.mayrhofen.at/ginzling', 'embed' => false],
    ['name' => 'Coolnest', 'url' => 'https://pano.mayrhofen.at/coolnest', 'embed' => false],
    ['name' => 'Unterberg', 'url' => 'https://pano.mayrhofen.at/unterberg', 'embed' => false],
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
        ['name' => 'Jeppe Smedegaard', 'role' => 'Rejsende', 'price' => 6888, 'extra' => []],
        ['name' => 'Gustav Langkilde O. Larsen', 'role' => 'Rejsende', 'price' => 6888, 'extra' => []],
        ['name' => 'Nikolaj Holm Iversen', 'role' => 'Rejsende', 'price' => 7326, 'extra' => ['Udendørsparkering, lør–ons', 'Udendørsparkering, ons–lør']],
        ['name' => 'Nicolaj Baun Christensen', 'role' => 'Rejsende', 'price' => 6888, 'extra' => []],
    ],
];

const SKI_SUGGEST = ['Ski eller snowboard', 'Skistøvler', 'Hjelm', 'Skibriller', 'Skijakke', 'Skibukser', 'Skiundertøj', 'Skisokker', 'Handsker eller luffer', 'Halsedisse', 'Solcreme og læbepomade', 'Pas og sygesikringskort', 'Liftkort', 'Oplader og powerbank'];

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
    ski_mutate('members', function (&$m) use ($id, $user) {
        if (!isset($m[$id])) {
            $m[$id] = ['id' => $id, 'name' => first_name($user), 'email' => $user['email'], 'role' => 'owner', 'hash' => '', 'tokens' => [], 'color' => SKI_COLORS[0], 'joined' => date('c')];
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
    return '<span class="sk-av' . ($class ? ' ' . $class : '') . '" style="--c: ' . e($m['color']) . '" title="' . e($m['name']) . '">' . e(ski_initials($m)) . '</span>';
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

function ski_join(string $name, string $email, string $password): array
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
    return ski_mutate('members', function (&$m) use ($name, $email, $password) {
        foreach ($m as $x) {
            if (mb_strtolower($x['email']) === $email) {
                return ['error' => 'Den mail er allerede med. Log ind i stedet.'];
            }
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
    return null;
}

function ski_runs(): array
{
    return load_json('ski/runs');
}

function ski_run_save(string $member, array $r): void
{
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
            'src' => ($r['src'] ?? '') === 'gps' ? 'gps' : 'manual',
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
        $rows[$m['id']] = ['m' => $m, 'km' => 0.0, 'max' => 0.0, 'descent' => 0, 'days' => [], 'live' => false];
    }
    foreach ($runs as $r) {
        if (!isset($rows[$r['member']])) {
            continue;
        }
        $row = &$rows[$r['member']];
        $row['km'] += $r['km'];
        $row['max'] = max($row['max'], $r['max']);
        $row['descent'] += $r['descent'];
        $row['days'][$r['date']] = true;
        if (!empty($r['live']) && strtotime($r['updated']) > time() - 300) {
            $row['live'] = true;
        }
        unset($row);
    }
    $rows = array_values($rows);
    usort($rows, fn($a, $b) => $b['km'] <=> $a['km'] ?: $b['max'] <=> $a['max']);
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
    unset($plan['_seeded']);
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
