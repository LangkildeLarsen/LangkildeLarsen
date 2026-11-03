<?php

function http_get(string $url, int $timeout = 12): ?string
{
    if (!preg_match('~^https?://~i', $url)) {
        return null;
    }
    $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,image/avif,image/webp,image/*,*/*;q=0.8', 'Accept-Language: da,en;q=0.8'],
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code < 400 && $body !== '') ? $body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => $ua]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false || $body === '' ? null : $body;
}

function meta_image(string $html, string $base): ?string
{
    foreach (['og:image', 'twitter:image'] as $prop) {
        if (preg_match('~<meta[^>]+(?:property|name)=["\']' . preg_quote($prop, '~') . '["\'][^>]*content=["\']([^"\']+)~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']' . preg_quote($prop, '~') . '["\']~i', $html, $m)) {
            return abs_url(html_entity_decode($m[1]), $base);
        }
    }
    return null;
}

function abs_url(string $url, string $base): string
{
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }
    $p = parse_url($base);
    $root = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    if (str_starts_with($url, '//')) {
        return ($p['scheme'] ?? 'https') . ':' . $url;
    }
    return $root . '/' . ltrim($url, '/');
}

function save_image(string $bytes, string $dir, string $name, int $maxBytes = 12582912): ?string
{
    if (strlen($bytes) > $maxBytes) {
        return null;
    }
    $info = @getimagesizefromstring($bytes);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'][$info['mime'] ?? ''] ?? null;
    if (!$ext) {
        return null;
    }
    $path = UPLOAD_DIR . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    foreach (glob($path . '/' . $name . '.*') ?: [] as $old) {
        @unlink($old);
    }
    $file = $name . '.' . $ext;
    file_put_contents($path . '/' . $file, $bytes);
    return $dir . '/' . $file;
}

function save_upload(array $f, string $dir, string $name): ?string
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $bytes = (string)file_get_contents($f['tmp_name']);
    $info = @getimagesizefromstring($bytes);
    if (!in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        if (str_contains(substr($bytes, 0, 300), '<svg') && !preg_match('~<script|on\w+\s*=~i', $bytes)) {
            $path = UPLOAD_DIR . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
            foreach (glob($path . '/' . $name . '.*') ?: [] as $old) {
                @unlink($old);
            }
            file_put_contents($path . '/' . $name . '.svg', $bytes);
            return $dir . '/' . $name . '.svg';
        }
        return null;
    }
    return save_image($bytes, $dir, $name);
}

function slug(string $s): string
{
    $s = strtr(mb_strtolower($s), ['æ' => 'ae', 'ø' => 'oe', 'å' => 'aa']);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-') ?: bin2hex(random_bytes(3));
}

function domain_of(string $url): string
{
    $host = parse_url(preg_match('~^https?://~i', $url) ? $url : 'https://' . $url, PHP_URL_HOST) ?: '';
    return preg_replace('/^www\./i', '', strtolower($host));
}

function fetch_project_image(array $project): ?string
{
    if (empty($project['url'])) {
        return null;
    }
    $html = http_get($project['url']);
    $img = $html ? meta_image($html, $project['url']) : null;
    if (!$img) {
        return null;
    }
    $img = preg_replace('/[?&]resize=[^&]*/', '', $img);
    $img .= (str_contains($img, '?') ? '&' : '?') . 'resize=1200x900';
    $bytes = http_get($img, 20);
    return $bytes ? save_image($bytes, 'projects', slug($project['title'])) : null;
}

function fetch_logo(string $website): ?string
{
    $domain = domain_of($website);
    if ($domain === '') {
        return null;
    }
    $candidates = [];
    $html = http_get('https://' . $domain);
    if ($html) {
        if (preg_match_all('~<link[^>]+rel=["\'][^"\']*(?:apple-touch-icon|icon)[^"\']*["\'][^>]*>~i', $html, $links)) {
            $scored = [];
            foreach ($links[0] as $tag) {
                if (!preg_match('~href=["\']([^"\']+)~i', $tag, $h)) {
                    continue;
                }
                $size = preg_match('~sizes=["\'](\d+)~i', $tag, $s) ? (int)$s[1] : (stripos($tag, 'apple-touch') !== false ? 180 : 32);
                if (preg_match('~\.svg(\?|$)~i', $h[1])) {
                    continue;
                }
                $scored[abs_url(html_entity_decode($h[1]), 'https://' . $domain)] = $size;
            }
            arsort($scored);
            $candidates = array_keys($scored);
        }
    }
    $candidates[] = 'https://www.google.com/s2/favicons?domain=' . rawurlencode($domain) . '&sz=128';
    foreach ($candidates as $c) {
        $bytes = http_get($c, 10);
        if ($bytes) {
            $info = @getimagesizefromstring($bytes);
            if ($info && $info[0] >= 32) {
                $saved = save_image($bytes, 'logos', slug($domain));
                if ($saved) {
                    return $saved;
                }
            }
        }
    }
    return null;
}
