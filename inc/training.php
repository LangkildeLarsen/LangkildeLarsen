<?php

const TRAIN_TYPES = ['Træning', 'Styrke', 'Løb', 'Cykling', 'Gang', 'Svømning', 'Andet'];

function training_load(?array $user = null): array
{
    return load_json(ukey('training', $user)) + [
        'week_goal' => 3,
        'km_goal' => 30,
        'min_goal' => 600,
        'habits' => ['Udstrækning', '10.000 skridt', 'Protein', '2 L vand'],
        'log' => [],
        'checks' => [],
    ];
}

function training_save(array $t, ?array $user = null): void
{
    $t['log'] = array_slice(array_values($t['log']), -1000);
    $keep = date('Y-m-d', strtotime('-120 days'));
    $t['checks'] = array_filter($t['checks'], fn($k) => $k >= $keep, ARRAY_FILTER_USE_KEY);
    save_json(ukey('training', $user), $t);
}

function training_add(array $in, ?array $user = null): array
{
    $t = training_load($user);
    $type = in_array($in['type'] ?? '', TRAIN_TYPES, true) ? $in['type'] : 'Andet';
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : date('Y-m-d');
    $row = [
        'id' => bin2hex(random_bytes(5)),
        'date' => $date,
        'type' => $type,
        'min' => max(0, min(600, (int)($in['min'] ?? 0))),
        'km' => max(0, min(500, round((float)str_replace(',', '.', (string)($in['km'] ?? 0)), 2))),
        'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 200),
    ];
    $t['log'][] = $row;
    training_save($t, $user);
    return $row;
}

function training_week_start(int $ts): int
{
    return strtotime('monday this week', $ts);
}

function training_stats(array $t): array
{
    $now = time();
    $ws = training_week_start($now);
    $week = [];
    for ($i = 0; $i < 7; $i++) {
        $week[date('Y-m-d', strtotime("+$i day", $ws))] = 0;
    }
    $month = date('Y-m');
    $m = ['count' => 0, 'km' => 0.0, 'min' => 0];
    $byWeek = [];
    foreach ($t['log'] as $r) {
        if (isset($week[$r['date']])) {
            $week[$r['date']]++;
        }
        if (str_starts_with($r['date'], $month)) {
            $m['count']++;
            $m['min'] += $r['min'];
            if ($r['type'] === 'Løb') {
                $m['km'] += $r['km'];
            }
        }
        $wk = date('Y-m-d', training_week_start(strtotime($r['date'])));
        $byWeek[$wk] = ($byWeek[$wk] ?? 0) + 1;
    }
    $goal = max(1, (int)$t['week_goal']);
    $streak = 0;
    $cursor = $ws;
    if (($byWeek[date('Y-m-d', $cursor)] ?? 0) < $goal) {
        $cursor = strtotime('-7 days', $cursor);
    }
    while (($byWeek[date('Y-m-d', $cursor)] ?? 0) >= $goal) {
        $streak++;
        $cursor = strtotime('-7 days', $cursor);
    }
    $done = count(array_filter($week));
    $sessions = array_sum($week);
    $last = $t['log'] ? end($t['log']) : null;
    return [
        'week' => $week,
        'sessions' => $sessions,
        'goal' => $goal,
        'pct' => min(100, round($sessions / $goal * 100)),
        'streak' => $streak,
        'month' => $m,
        'today' => $t['checks'][date('Y-m-d')] ?? [],
        'last' => $last,
        'days_trained' => $done,
    ];
}

function training_nag(array $t, array $s): string
{
    if (!$t['log']) {
        return 'Du har ikke logget nogen træning endnu. Første tur venter!';
    }
    $last = max(array_column($t['log'], 'date'));
    $days = (int)round((strtotime('today') - strtotime($last)) / 86400);
    $left = max(0, $s['goal'] - $s['sessions']);
    if ($days === 0) {
        return $left ? "Du har trænet i dag – {$s['sessions']} af {$s['goal']} denne uge. Stærkt!" : "Trænet i dag, og ugens mål er nået. Maskine!";
    }
    if (!$left) {
        return "Ugens træningsmål er nået ({$s['sessions']}/{$s['goal']}). Nyd hvilen.";
    }
    if ($days === 1) {
        return 'Du trænede i går. ' . ($left === 1 ? 'Én træning mere' : "$left træninger mere") . ' i denne uge.';
    }
    return "Du har ikke trænet i $days dage. Kom igang xD";
}

function training_quip(array $s): string
{
    $left = $s['goal'] - $s['sessions'];
    $dow = (int)date('N');
    if ($left <= 0) return $s['sessions'] > $s['goal'] ? 'Over målet. Maskine!' : 'Ugens mål er nået. Godt gået!';
    if ($s['sessions'] === 0 && $dow >= 4) return 'Ugen er ikke slut endnu. En tur i dag?';
    if ($s['sessions'] === 0) return 'Ny uge, nye gains. Første træning venter.';
    if ($left === 1) return 'Kun én træning mere til ugens mål.';
    return "$left træninger mere til ugens mål.";
}
