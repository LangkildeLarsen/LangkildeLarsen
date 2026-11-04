<?php

require_once __DIR__ . '/push.php';

function dm_all(): array
{
    return load_json('messages');
}

function dm_send(string $from, string $to, string $text, string $type = 'text'): array
{
    $all = dm_all();
    $m = [
        'id' => bin2hex(random_bytes(6)),
        'from' => $from,
        'to' => $to,
        'text' => mb_substr(trim($text), 0, 4000),
        'type' => $type,
        'created' => date('c'),
        'read' => false,
    ];
    $all[] = $m;
    if (count($all) > 2000) {
        $all = array_slice($all, -2000);
    }
    save_json('messages', $all);
    try {
        push_message($m);
    } catch (Throwable $e) {
    }
    return $m;
}

function dm_thread(string $a, string $b): array
{
    return array_values(array_filter(dm_all(), fn($m) => ($m['from'] === $a && $m['to'] === $b) || ($m['from'] === $b && $m['to'] === $a)));
}

function dm_unread(string $me, ?string $from = null): int
{
    return count(array_filter(dm_all(), fn($m) => $m['to'] === $me && empty($m['read']) && ($from === null || $m['from'] === $from)));
}

function dm_mark_read(string $me, string $from): void
{
    $all = dm_all();
    $changed = false;
    foreach ($all as &$m) {
        if ($m['to'] === $me && $m['from'] === $from && empty($m['read'])) {
            $m['read'] = true;
            $changed = true;
        }
    }
    unset($m);
    if ($changed) {
        save_json('messages', $all);
    }
}

function dm_public(array $m, string $me): array
{
    return [
        'id' => $m['id'],
        'mine' => $m['from'] === $me,
        'text' => $m['text'],
        'type' => $m['type'],
        'time' => date('H:i', strtotime($m['created'])),
        'day' => date('Y-m-d', strtotime($m['created'])),
        'label' => day_label(strtotime($m['created'])),
    ];
}
