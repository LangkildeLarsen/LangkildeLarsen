<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/ski.php';
require dirname(__DIR__) . '/inc/strava.php';

header('X-Robots-Tag: noindex');

$me = ski_current();
if (!$me || !strava_ready()) {
    redirect('./');
}

if (isset($_GET['connect'])) {
    $_SESSION['strava_state'] = bin2hex(random_bytes(12));
    redirect(strava_auth_url($_SESSION['strava_state']));
}

$back = function (string $msg) {
    $_SESSION['ski_flash_strava'] = $msg;
    redirect('./#tracker');
};

if (isset($_GET['error'])) {
    $back('Du afviste adgangen i Strava. Prøv igen, hvis det var en fejl.');
}

$state = (string)($_GET['state'] ?? '');
if ($state === '' || !hash_equals((string)($_SESSION['strava_state'] ?? ''), $state)) {
    $back('Forbindelsen til Strava udløb. Prøv igen.');
}
unset($_SESSION['strava_state']);

$scope = (string)($_GET['scope'] ?? '');
if (!str_contains($scope, 'activity:read')) {
    $back('Sæt flueben ved adgang til dine aktiviteter i Strava, ellers kan turene ikke hentes.');
}

$t = strava_exchange((string)($_GET['code'] ?? ''));
if (isset($t['error'])) {
    $back('Strava-forbindelsen fejlede: ' . $t['error']);
}

strava_save_token($me['id'], $t);
$res = strava_sync_member($me['id']);
$back('Strava er forbundet' . ($res['n'] ? ' og ' . $res['n'] . ' ' . ($res['n'] === 1 ? 'tur' : 'ture') . ' er hentet.' : '. Dine skiture kommer herind, når du gemmer dem i Strava.'));
