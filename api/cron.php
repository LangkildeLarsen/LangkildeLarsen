<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/calendar.php';
require dirname(__DIR__) . '/inc/messages.php';
require dirname(__DIR__) . '/inc/reminders.php';
require dirname(__DIR__) . '/inc/budget.php';
require dirname(__DIR__) . '/inc/training.php';
require dirname(__DIR__) . '/inc/watch.php';
require dirname(__DIR__) . '/inc/claude.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

users_migrate();
@set_time_limit(120);
$out = ['rem' => rem_dispatch()];
try {
    $out['watch'] = watch_run();
} catch (Throwable $e) {
    $out['watch'] = 'fejl';
}
echo 'ok ' . json_encode($out);
