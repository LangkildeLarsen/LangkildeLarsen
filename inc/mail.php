<?php

function mail_cfg(?array $user = null): array
{
    return load_json(ukey('mail', $user)) + ['user' => '', 'pass' => '', 'host' => 'imap.one.com', 'port' => 993, 'push' => true, 'link' => ''];
}

function mail_ready(array $cfg): bool
{
    return $cfg['user'] !== '' && $cfg['pass'] !== '';
}

function imap_q(string $s): string
{
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
}

final class MiniImap
{
    private $fp;
    private int $n = 0;

    public function __construct(string $host, int $port)
    {
        $target = getenv('MAIL_IMAP_OVERRIDE') ?: 'ssl://' . $host . ':' . $port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $fp = @stream_socket_client($target, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException('Kunne ikke forbinde til mailserveren.');
        }
        stream_set_timeout($fp, 10);
        $this->fp = $fp;
        $greet = fgets($fp);
        if ($greet === false || !str_starts_with($greet, '* OK')) {
            throw new RuntimeException('Mailserveren svarede ikke som forventet.');
        }
    }

    public function cmd(string $c): array
    {
        $tag = 'J' . (++$this->n);
        fwrite($this->fp, $tag . ' ' . $c . "\r\n");
        $out = [];
        while (true) {
            $line = fgets($this->fp);
            if ($line === false) {
                throw new RuntimeException('Forbindelsen til mailserveren blev afbrudt.');
            }
            if (preg_match('/\{(\d+)\}\r\n$/', $line, $m)) {
                $want = (int)$m[1];
                $lit = '';
                while (strlen($lit) < $want) {
                    $chunk = fread($this->fp, $want - strlen($lit));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $lit .= $chunk;
                }
                $out[] = ['line' => $line, 'lit' => $lit];
                continue;
            }
            if (str_starts_with($line, $tag . ' ')) {
                if (!preg_match('/^' . $tag . ' OK/i', $line)) {
                    throw new RuntimeException(trim(substr($line, strlen($tag) + 1)));
                }
                return $out;
            }
            $out[] = ['line' => $line, 'lit' => null];
        }
    }

    public function close(): void
    {
        if ($this->fp) {
            @fwrite($this->fp, 'J' . (++$this->n) . " LOGOUT\r\n");
            @fclose($this->fp);
            $this->fp = null;
        }
    }
}

function mail_decode(string $s): string
{
    $s = trim(preg_replace('/\r?\n[ \t]+/', ' ', $s));
    if (!str_contains($s, '=?')) {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }
    if (function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d !== false) {
            return $d;
        }
    }
    return mb_decode_mimeheader($s);
}

function mail_parse_headers(string $raw): array
{
    $raw = preg_replace('/\r?\n[ \t]+/', ' ', $raw);
    $h = [];
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if (preg_match('/^([A-Za-z-]+):\s*(.*)$/', $line, $m)) {
            $h[strtolower($m[1])] = $m[2];
        }
    }
    $from = mail_decode($h['from'] ?? '');
    $name = preg_match('/^"?([^"<]+?)"?\s*<([^>]+)>/', $from, $m) ? trim($m[1]) : trim($from, ' <>');
    return [
        'from' => $name !== '' ? $name : $from,
        'subject' => mail_decode($h['subject'] ?? '') ?: '(intet emne)',
        'ts' => strtotime($h['date'] ?? '') ?: time(),
    ];
}

function mail_fetch(array $cfg, int $limit = 6): array
{
    $imap = new MiniImap($cfg['host'], (int)$cfg['port']);
    try {
        $imap->cmd('LOGIN ' . imap_q($cfg['user']) . ' ' . imap_q($cfg['pass']));
        $imap->cmd('EXAMINE INBOX');
        $uids = [];
        foreach ($imap->cmd('UID SEARCH UNSEEN') as $r) {
            if (preg_match('/^\* SEARCH(.*)$/', trim($r['line']), $m)) {
                $uids = array_merge($uids, array_map('intval', preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY)));
            }
        }
        sort($uids);
        $items = [];
        $pick = array_slice($uids, -$limit);
        if ($pick) {
            foreach ($imap->cmd('UID FETCH ' . implode(',', $pick) . ' (UID BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)])') as $r) {
                if ($r['lit'] !== null && preg_match('/UID (\d+)/', $r['line'], $m)) {
                    $items[] = ['uid' => (int)$m[1]] + mail_parse_headers($r['lit']);
                }
            }
        }
        usort($items, fn($a, $b) => $b['uid'] <=> $a['uid']);
        return ['ok' => true, 'count' => count($uids), 'uids' => $uids, 'items' => $items, 't' => time()];
    } finally {
        $imap->close();
    }
}

function mail_get(?array $user = null, bool $fresh = false): ?array
{
    $cfg = mail_cfg($user);
    if (!mail_ready($cfg)) {
        return null;
    }
    $cache = load_json(ukey('mail-cache', $user));
    if (!$fresh && ($cache['t'] ?? 0) > time() - 120) {
        return $cache;
    }
    try {
        $d = mail_fetch($cfg);
    } catch (Throwable $e) {
        $d = ['ok' => false, 'error' => $e->getMessage(), 't' => time()] + array_intersect_key($cache, ['count' => 1, 'items' => 1, 'uids' => 1]);
    }
    save_json(ukey('mail-cache', $user), $d);
    return $d;
}

function mail_ago(int $ts): string
{
    $d = time() - $ts;
    if ($d < 60) return 'nu';
    if ($d < 3600) return floor($d / 60) . ' min';
    if ($d < 86400 && date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'i går';
    return date('j/n', $ts);
}

function mail_notify_all(): int
{
    if (!function_exists('push_send')) {
        return 0;
    }
    $sent = 0;
    foreach (users_all() as $u) {
        $cfg = mail_cfg($u);
        if (!mail_ready($cfg) || empty($cfg['push'])) {
            continue;
        }
        $d = mail_get($u, true);
        if (!$d || empty($d['ok'])) {
            continue;
        }
        $state = load_json(ukey('mail-seen', $u));
        $known = $state['uids'] ?? null;
        if ($known === null) {
            save_json(ukey('mail-seen', $u), ['uids' => array_slice($d['uids'], -500)]);
            continue;
        }
        $new = array_diff($d['uids'], $known);
        foreach ($d['items'] as $it) {
            if (in_array($it['uid'], $new, true)) {
                push_send($u, ['title' => 'Ny mail fra ' . $it['from'], 'body' => $it['subject'], 'url' => '/admin/', 'tag' => 'mail-' . $it['uid']]);
                $sent++;
            }
        }
        save_json(ukey('mail-seen', $u), ['uids' => array_slice(array_values(array_unique(array_merge($known, $d['uids']))), -500)]);
    }
    return $sent;
}
