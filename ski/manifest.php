<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/ski.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store');

$me = ski_current();
$start = '/ski/';
if ($me) {
    $start .= '?app=' . rawurlencode($me['id'] . '.' . ski_app_token($me['id']));
}

echo json_encode([
    'name' => 'Skiferie Mayrhofen',
    'short_name' => 'Skiferie',
    'description' => 'Nedtælling, program, sne, skitracker og huskelister til skiferien i Mayrhofen.',
    'id' => '/ski/',
    'start_url' => $start,
    'scope' => '/ski/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => '#07112a',
    'theme_color' => '#050505',
    'lang' => 'da',
    'icons' => [
        ['src' => '/assets/ski/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/assets/ski/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ['src' => '/assets/ski/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
