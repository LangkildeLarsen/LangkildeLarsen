<?php

const BUDGET_FREQ = ['month' => ['Månedlig', 1], 'quarter' => ['Kvartalsvis', 3], 'half' => ['Halvårlig', 6], 'year' => ['Årlig', 12]];
const BUDGET_TYPES = ['need' => 'Behov', 'want' => 'Ønske', 'saving' => 'Opsparing'];
const BUDGET_CATEGORIES = ['Bolig', 'Forsyning', 'Transport', 'Mad & dagligvarer', 'Forsikring', 'Abonnementer', 'Lån', 'Opsparing', 'Fritid', 'Tøj & pleje', 'Andet'];

function parse_amount($v): float
{
    $v = preg_replace('/[^\d,.\-]/', '', (string)$v);
    if ($v === '' || $v === '-') {
        return 0.0;
    }
    if (str_contains($v, ',')) {
        $v = str_replace(['.', ','], ['', '.'], $v);
    } elseif (substr_count($v, '.') > 1 || preg_match('/\.\d{3}$/', $v)) {
        $v = str_replace('.', '', $v);
    }
    return round((float)$v, 2);
}

function kr(float $v, int $dec = 0): string
{
    return number_format($v, $dec, ',', '.') . ' kr.';
}

function monthly(array $row): float
{
    return $row['amount'] / (BUDGET_FREQ[$row['freq'] ?? 'month'][1] ?? 1);
}

function budget_summary(array $b): array
{
    $income = array_sum(array_map('monthly', $b['income'] ?? []));
    $byType = ['need' => 0.0, 'want' => 0.0, 'saving' => 0.0];
    $byCat = [];
    foreach ($b['expenses'] ?? [] as $x) {
        $m = monthly($x);
        $byType[$x['type'] ?? 'need'] = ($byType[$x['type'] ?? 'need'] ?? 0) + $m;
        $c = $x['category'] !== '' ? $x['category'] : 'Andet';
        $byCat[$c] = ($byCat[$c] ?? 0) + $m;
    }
    arsort($byCat);
    $expenses = array_sum($byType);
    return [
        'income' => $income,
        'expenses' => $expenses,
        'left' => $income - $expenses,
        'saving' => $byType['saving'],
        'saving_rate' => $income > 0 ? $byType['saving'] / $income * 100 : 0,
        'by_type' => $byType,
        'by_cat' => $byCat,
    ];
}

function budget_from_post(array $post): array
{
    $b = ['income' => [], 'expenses' => [], 'updated' => date('c')];
    foreach ($post['in_name'] ?? [] as $i => $name) {
        $amount = parse_amount($post['in_amount'][$i] ?? '');
        if (trim($name) === '' && $amount == 0) {
            continue;
        }
        $freq = $post['in_freq'][$i] ?? 'month';
        $b['income'][] = [
            'name' => trim($name) ?: 'Indtægt',
            'amount' => $amount,
            'freq' => isset(BUDGET_FREQ[$freq]) ? $freq : 'month',
        ];
    }
    foreach ($post['ex_name'] ?? [] as $i => $name) {
        $amount = parse_amount($post['ex_amount'][$i] ?? '');
        if (trim($name) === '' && $amount == 0) {
            continue;
        }
        $freq = $post['ex_freq'][$i] ?? 'month';
        $type = $post['ex_type'][$i] ?? 'need';
        $b['expenses'][] = [
            'name' => trim($name) ?: 'Udgift',
            'category' => trim((string)($post['ex_cat'][$i] ?? '')),
            'amount' => $amount,
            'freq' => isset(BUDGET_FREQ[$freq]) ? $freq : 'month',
            'type' => isset(BUDGET_TYPES[$type]) ? $type : 'need',
            'day' => max(0, min(31, (int)($post['ex_day'][$i] ?? 0))),
        ];
    }
    return $b;
}

function amount_value(float $v): string
{
    if ($v == 0) {
        return '';
    }
    return number_format($v, fmod($v, 1) ? 2 : 0, ',', '.');
}
