<?php

function users_all(): array
{
    return load_json('users');
}

function users_save(array $users): void
{
    save_json('users', $users);
}

function users_migrate(): void
{
    if (users_all()) {
        return;
    }
    $auth = load_json('auth');
    if (empty($auth['hash'])) {
        return;
    }
    $p = get_profile();
    $email = mb_strtolower(trim($p['email'] ?: 'emil@langkildelarsen.dk'));
    users_save([$email => [
        'email' => $email,
        'name' => $p['name'],
        'role' => 'owner',
        'hash' => $auth['hash'],
        'tokens' => $auth['tokens'] ?? [],
        'created' => date('c'),
    ]]);
}

function current_user(): ?array
{
    if (isset($GLOBALS['ctx_user'])) {
        return $GLOBALS['ctx_user'];
    }
    $id = $_SESSION['uid'] ?? null;
    return $id ? (users_all()[$id] ?? null) : null;
}

function is_owner(?array $user = null): bool
{
    $user = $user ?? current_user();
    return ($user['role'] ?? '') === 'owner';
}

function user_slug(string $email): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($email)), '-');
}

function ukey(string $name, ?array $user = null): string
{
    $user = $user ?? current_user();
    if (!$user || is_owner($user)) {
        return $name;
    }
    return 'users/' . user_slug($user['email']) . '/' . $name;
}

function first_name(array $user): string
{
    return explode(' ', trim($user['name'] ?: $user['email']))[0];
}

function initials(array $user): string
{
    $parts = preg_split('/\s+/', trim($user['name'] ?: $user['email']));
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
}
