<?php

function b64u_enc(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

function push_ec_raw(array $details): string
{
    return "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

function push_new_key(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$key) {
        return [];
    }
    openssl_pkey_export($key, $pem);
    return ['key' => $key, 'pem' => $pem, 'pub' => push_ec_raw(openssl_pkey_get_details($key))];
}

function push_vapid(): array
{
    $v = load_json('push-vapid');
    if (empty($v['pem']) || empty($v['pub'])) {
        $k = push_new_key();
        if (!$k) {
            return [];
        }
        $v = ['pem' => $k['pem'], 'pub' => b64u_enc($k['pub'])];
        save_json('push-vapid', $v);
    }
    return $v;
}

function push_public_key(): string
{
    return push_vapid()['pub'] ?? '';
}

function push_raw_to_pem(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function push_der_to_raw(string $der): string
{
    $pos = 2;
    if (ord($der[1]) & 0x80) {
        $pos += ord($der[1]) & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function push_jwt(string $endpoint, array $vapid): string
{
    $p = parse_url($endpoint);
    $head = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $body = b64u_enc(json_encode([
        'aud' => $p['scheme'] . '://' . $p['host'],
        'exp' => time() + 43200,
        'sub' => 'mailto:emil@langkildelarsen.dk',
    ], JSON_UNESCAPED_SLASHES));
    openssl_sign("$head.$body", $sig, $vapid['pem'], OPENSSL_ALGO_SHA256);
    return "$head.$body." . b64u_enc(push_der_to_raw($sig));
}

function push_encrypt(string $payload, string $p256dh, string $auth): ?string
{
    $uaPub = b64u_dec($p256dh);
    $authSecret = b64u_dec($auth);
    if (strlen($uaPub) !== 65 || strlen($authSecret) < 16) {
        return null;
    }
    $eph = push_new_key();
    if (!$eph) {
        return null;
    }
    $shared = openssl_pkey_derive(openssl_pkey_get_public(push_raw_to_pem($uaPub)), $eph['key'], 32);
    if ($shared === false) {
        return null;
    }
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $eph['pub'], $authSecret);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) {
        return null;
    }
    return $salt . pack('N', 4096) . chr(65) . $eph['pub'] . $cipher . $tag;
}

function push_subs(array $user): array
{
    return load_json(ukey('push', $user));
}

function push_subscribe(array $user, array $sub): bool
{
    $endpoint = (string)($sub['endpoint'] ?? '');
    $keys = $sub['keys'] ?? [];
    if (!preg_match('~^https://~', $endpoint) || empty($keys['p256dh']) || empty($keys['auth'])) {
        return false;
    }
    $subs = push_subs($user);
    $subs[hash('sha256', $endpoint)] = [
        'endpoint' => $endpoint,
        'p256dh' => (string)$keys['p256dh'],
        'auth' => (string)$keys['auth'],
        'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        'added' => date('c'),
    ];
    save_json(ukey('push', $user), $subs);
    return true;
}

function push_unsubscribe(array $user, string $endpoint): void
{
    $subs = push_subs($user);
    unset($subs[hash('sha256', $endpoint)]);
    save_json(ukey('push', $user), $subs);
}

function push_send(array $user, array $data): int
{
    $subs = push_subs($user);
    $vapid = push_vapid();
    if (!$subs || !$vapid || !function_exists('curl_init')) {
        return 0;
    }
    $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sent = 0;
    $dead = [];
    foreach ($subs as $id => $s) {
        $body = push_encrypt($payload, $s['p256dh'], $s['auth']);
        if ($body === null) {
            continue;
        }
        $ch = curl_init($s['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400',
                'Urgency: high',
                'Authorization: vapid t=' . push_jwt($s['endpoint'], $vapid) . ', k=' . $vapid['pub'],
            ],
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 404 || $code === 410) {
            $dead[] = $id;
        } elseif ($code >= 200 && $code < 300) {
            $sent++;
        }
    }
    if ($dead) {
        foreach ($dead as $id) {
            unset($subs[$id]);
        }
        save_json(ukey('push', $user), $subs);
    }
    return $sent;
}

function push_message(array $m): void
{
    $users = users_all();
    $to = $users[$m['to']] ?? null;
    $from = $users[$m['from']] ?? null;
    if (!$to || !$from) {
        return;
    }
    $text = preg_replace('/\s+/u', ' ', $m['text']);
    $text = mb_strlen($text) > 140 ? mb_substr($text, 0, 137) . '…' : $text;
    push_send($to, [
        'title' => ($m['type'] === 'request' ? 'Ændringsønske fra ' : '') . first_name($from),
        'body' => $text,
        'url' => '/admin/?view=messages&with=' . rawurlencode($m['from']),
        'tag' => 'dm-' . user_slug($m['from']),
        'badge' => dm_unread($m['to']),
    ]);
}
