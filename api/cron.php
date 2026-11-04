<?php
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/calendar.php';
require dirname(__DIR__) . '/inc/messages.php';
require dirname(__DIR__) . '/inc/reminders.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

users_migrate();
echo 'ok ' . rem_dispatch();
