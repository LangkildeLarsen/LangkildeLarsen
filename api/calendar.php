<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/events.php';

users_migrate();
$k = (string)($_GET['k'] ?? '');
$user = null;
if ($k !== '' && preg_match('/^[a-f0-9]{32}$/', $k)) {
    foreach (users_all() as $u) {
        $feed = load_json(ukey('events-feed', $u));
        if (!empty($feed['key']) && hash_equals($feed['key'], $k)) {
            $user = $u;
            break;
        }
    }
}
if (!$user) {
    http_response_code(404);
    exit;
}
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="jarvis.ics"');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo ev_ics($user);
