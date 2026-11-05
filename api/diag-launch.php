<?php
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');
foreach (['https://www.efuture.dk/wp-content/uploads/2026/10/efuture-suv-elcykler.webp', 'https://www.efuture.dk/wp-content/uploads/2026/05/Cykelportal-icon.webp'] as $u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130 Safari/537.36']);
    $b = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    $img = $b ? @getimagesizefromstring($b) : null;
    echo basename($u), ': http=', $info['http_code'], ' bytes=', strlen((string)$b), ' type=', $info['content_type'], ' mime=', $img['mime'] ?? '-', ' err=', $err, "\n";
}
echo 'webp-support=', function_exists('imagecreatefromwebp') ? 'gd' : 'nogd', ' php=', PHP_VERSION, "\n";
echo 'uploads-writable=', is_writable(dirname(__DIR__) . '/uploads') ? 'yes' : 'no', "\n";
echo 'launch-dir=', is_dir(dirname(__DIR__) . '/uploads/launch') ? implode(',', array_map('basename', glob(dirname(__DIR__) . '/uploads/launch/*') ?: [])) : 'none', "\n";
