<?php

function launch_defaults(): array
{
    $mk = fn($n, $u) => ['id' => bin2hex(random_bytes(4)), 'name' => $n, 'url' => $u, 'img' => ''];
    return [
        $mk('EFUTURE', 'https://efuture.dk/'),
        $mk('Bikedesk', 'https://app.c1st.com/da/repairs'),
        $mk('Cykelportal', 'https://portal.efuture.dk/'),
        $mk('Jarvis', 'https://langkildelarsen.dk/admin/'),
        $mk('YouTube', 'https://www.youtube.com/'),
    ];
}

function launch_load(?array $user = null): array
{
    $d = load_json(ukey('launch', $user));
    if (!isset($d['links'])) {
        $d = ['links' => launch_defaults()];
        save_json(ukey('launch', $user), $d);
    }
    $presets = [
        'v2_efuture_img' => ['efuture.dk', 'img', 'https://www.efuture.dk/wp-content/uploads/2026/10/efuture-suv-elcykler.webp'],
        'v2_cyk_img' => ['portal.efuture.dk', 'img', 'https://www.efuture.dk/wp-content/uploads/2026/05/din-cykelportal-efuture.webp'],
        'v2_cyk_icon' => ['portal.efuture.dk', 'icon', 'https://www.efuture.dk/wp-content/uploads/2026/05/Cykelportal-icon.webp'],
        'v2_bikedesk_icon' => ['app.c1st.com', 'icon', 'https://langkildelarsen.dk/assets/brand/bikedesk.svg'],
    ];
    $dirty = false;
    if (empty($d['mig']['v3_bikedesk_efuture_icon'])) {
        foreach ($d['links'] as &$l) {
            if (launch_host($l['url']) === 'app.c1st.com') {
                $l['icon'] = 'https://www.google.com/s2/favicons?domain=efuture.dk&sz=128';
            }
        }
        unset($l);
        $d['mig']['v3_bikedesk_efuture_icon'] = 1;
        $d['mig']['v2_bikedesk_icon'] = 1;
        $dirty = true;
    }
    if (empty($d['mig']['v6_center_jarvis'])) {
        foreach ($d['links'] as &$l) {
            if (launch_host($l['url']) === 'langkildelarsen.dk') {
                $l['center'] = true;
            }
        }
        unset($l);
        $d['mig']['v6_center_jarvis'] = 1;
        $dirty = true;
    }
    if (empty($d['mig']['v5_center_cykelportal'])) {
        foreach ($d['links'] as &$l) {
            if (launch_host($l['url']) === 'portal.efuture.dk') {
                $l['center'] = true;
            }
        }
        unset($l);
        $d['mig']['v5_center_cykelportal'] = 1;
        $dirty = true;
    }
    if (empty($d['mig']['v4_center_icons'])) {
        foreach ($d['links'] as &$l) {
            if (in_array(launch_host($l['url']), ['youtube.com', 'facebook.com', 'm.facebook.com'], true)) {
                $l['center'] = true;
            }
        }
        unset($l);
        $d['mig']['v4_center_icons'] = 1;
        $dirty = true;
    }
    foreach ($presets as $key => [$host, $field, $src]) {
        if (!empty($d['mig'][$key])) {
            continue;
        }
        foreach ($d['links'] as &$l) {
            if (launch_host($l['url']) === $host && (empty($l[$field]) || !launch_src($l[$field]))) {
                $l[$field] = $src;
            }
        }
        unset($l);
        $d['mig'][$key] = 1;
        $dirty = true;
    }
    if ($dirty) {
        save_json(ukey('launch', $user), $d);
    }
    return $d;
}

function launch_img_from_url(string $url, string $id): ?string
{
    $url = launch_norm_url($url);
    $bin = $url !== '' ? launch_http($url, 6000000) : null;
    return $bin ? save_image($bin['body'], 'launch', 'lp-' . $id, 6000000) : null;
}

function launch_icon_from_url(string $url, string $id): ?string
{
    $url = launch_norm_url($url);
    if ($url === '') {
        return null;
    }
    $bin = launch_http($url, 3000000);
    if (!$bin) {
        return null;
    }
    if (str_contains(substr($bin['body'], 0, 400), '<svg') && !preg_match('~<script|on\w+\s*=~i', $bin['body'])) {
        $dir = UPLOAD_DIR . '/launch';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/ic-' . $id . '.svg', $bin['body']);
        return 'launch/ic-' . $id . '.svg';
    }
    return save_image($bin['body'], 'launch', 'ic-' . $id, 3000000);
}

function launch_save(array $d, ?array $user = null): void
{
    $d['links'] = array_values(array_slice($d['links'], 0, 30));
    save_json(ukey('launch', $user), $d);
}

function launch_norm_url(string $u): string
{
    $u = trim($u);
    if ($u === '') {
        return '';
    }
    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $u)) {
        $u = 'https://' . $u;
    }
    return preg_match('~^https?://~i', $u) ? $u : '';
}

function launch_host(string $u): string
{
    return preg_replace('/^www\./', '', (string)parse_url($u, PHP_URL_HOST));
}

function launch_http(string $url, int $max): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    $buf = '';
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: da,en;q=0.8'],
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$buf, $max) {
            $buf .= $chunk;
            return strlen($buf) > $max ? 0 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($buf === '' || $code >= 400) {
        return null;
    }
    return ['body' => $buf, 'url' => $final ?: $url];
}

function launch_fetch_image(string $pageUrl, string $id): ?string
{
    $page = launch_http($pageUrl, 1500000);
    if (!$page) {
        return null;
    }
    $img = null;
    foreach (['og:image', 'twitter:image', 'og:image:url'] as $prop) {
        if (preg_match('~<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '~') . '["\'][^>]*content=["\']([^"\']+)["\']~i', $page['body'], $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']' . preg_quote($prop, '~') . '["\']~i', $page['body'], $m)) {
            $img = html_entity_decode($m[1]);
            break;
        }
    }
    if (!$img) {
        return null;
    }
    if (str_starts_with($img, '//')) {
        $img = 'https:' . $img;
    } elseif (!preg_match('~^https?://~i', $img)) {
        $p = parse_url($page['url']);
        $img = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . '/' . ltrim($img, '/');
    }
    $bin = launch_http($img, 6000000);
    return $bin ? save_image($bin['body'], 'launch', 'lp-' . $id, 6000000) : null;
}

function launch_hue(string $s): int
{
    return hexdec(substr(md5($s), 0, 4)) % 360;
}

function launch_engines(): array
{
    return [
        'google' => ['Google', 'https://www.google.com/search'],
        'duckduckgo' => ['DuckDuckGo', 'https://duckduckgo.com/'],
        'bing' => ['Bing', 'https://www.bing.com/search'],
        'perplexity' => ['Perplexity', 'https://www.perplexity.ai/search'],
    ];
}

function launch_opts(array $lp): array
{
    $o = $lp['opt'] ?? [];
    return [
        'search' => isset(launch_engines()[$o['search'] ?? '']) ? $o['search'] : 'google',
        'greet' => $o['greet'] ?? true,
        'cards' => ($o['cards'] ?? []) + ['jarvis' => true, 'weather' => true, 'event' => true, 'rem' => true],
    ];
}

function launch_src(?string $v): string
{
    $v = (string)$v;
    if ($v === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $v)) {
        return $v;
    }
    return is_file(UPLOAD_DIR . '/' . $v) ? '../uploads/' . $v . '?v=' . filemtime(UPLOAD_DIR . '/' . $v) : '';
}

function launch_drop_file(?string $v): void
{
    $v = (string)$v;
    if ($v !== '' && !preg_match('~^https?://~i', $v)) {
        @unlink(UPLOAD_DIR . '/' . $v);
    }
}

function launch_pos(?string $v): string
{
    return preg_match('/^(\d{1,3})% (\d{1,3})%$/', (string)$v, $m) ? min(100, (int)$m[1]) . '% ' . min(100, (int)$m[2]) . '%' : '50% 50%';
}
