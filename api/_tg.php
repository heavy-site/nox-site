<?php
// Telegram notification for the site's forms. The file written in rent.php is
// the record; this is how anyone hears about it.
//
// Configured in nox_config.php, outside the web root:
//   TG_TOKEN  bot token
//   TG_CHAT   chat id. A person is positive — 6535254719 — and has to send the
//             bot /start once, because a bot cannot write first. A group is
//             negative — -1004298991246 — and the bot has to be a member. A
//             minus in front of a personal id asks for a group that is not
//             there, and Telegram answers "chat not found".
//   TG_TOPIC  optional forum topic id; omit or 0 for the group's General
require_once __DIR__ . '/_config.php';

function tg_esc(string $v): string {
    // Telegram's HTML parse mode only forbids these three; anything else,
    // including a name in angle brackets, would otherwise break the message.
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $v);
}

// 2026-09-10 -> "10.09.2026, четвер". The weekday is half the decision for a
// venue: a Friday and a Tuesday are not the same request.
function tg_date(string $v): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return tg_esc($v);
    $days = ['неділя', 'понеділок', 'вівторок', 'середа', 'четвер', 'пʼятниця', 'субота'];
    $ts = mktime(12, 0, 0, (int)$m[2], (int)$m[3], (int)$m[1]);
    return $m[3] . '.' . $m[2] . '.' . $m[1] . ', ' . $days[(int)date('w', $ts)];
}

// The contact chooses its own icon, and a phone typed as bare digits is put
// into international form — Telegram only makes a number tappable once it
// looks like one. Usernames and addresses it links by itself.
function tg_contact(string $v): array {
    $v = trim($v);
    if (filter_var($v, FILTER_VALIDATE_EMAIL)) return ['✉️', tg_esc($v)];
    if ($v !== '' && ($v[0] === '@' || stripos($v, 't.me/') !== false)) return ['💬', tg_esc($v)];

    $d = preg_replace('/\D+/', '', $v);
    if (strlen($d) === 10 && $d[0] === '0') $d = '38' . $d;         // 063… -> 38063…
    if (strlen($d) === 12 && substr($d, 0, 3) === '380') {
        return ['📞', '+' . substr($d, 0, 3) . ' ' . substr($d, 3, 2) . ' '
                    . substr($d, 5, 3) . ' ' . substr($d, 8, 2) . ' ' . substr($d, 10, 2)];
    }
    return ['📞', tg_esc($v)];
}

/* A rental enquiry, laid out to be read on a phone: what the night is, then
   who is asking and how to reach them, then the detail. The date leads — it
   is the only field that can decide the answer on its own. */
function tg_enquiry(array $e): string {
    list($icon, $contact) = tg_contact($e['contact']);

    $out  = '<b>◆ ЗАЯВКА НА ОРЕНДУ</b>' . "\n\n";
    $out .= '📅 <b>' . tg_date($e['date']) . '</b>' . "\n";
    $out .= '👤 <b>' . tg_esc($e['name']) . '</b>' . "\n";
    $out .= $icon . ' ' . $contact;

    if (trim($e['event'])   !== '') $out .= "\n\n" . '🎧 ' . tg_esc($e['event']);
    if (trim($e['guests'])  !== '') $out .= "\n" . '👥 ' . tg_esc($e['guests']) . ' гостей';
    if (trim($e['comment']) !== '') {
        $out .= "\n\n" . '<blockquote>' . tg_esc($e['comment']) . '</blockquote>';
    }

    $now = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
    $out .= "\n\n" . '<i>' . $now->format('d.m, H:i') . ' · noxpl4ce.com</i>';
    return $out;
}

// Returns true only on Telegram's own ok:true. Never throws and never blocks
// the reply to the visitor: a form that worked must not look broken because
// a notification did not go out.
function tg_send(string $text): bool {
    if (TG_TOKEN === '' || TG_CHAT === '') { nox_log('TG skipped: not configured'); return false; }

    $params = [
        'chat_id'                  => TG_CHAT,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 'true',
    ];
    if (TG_TOPIC) $params['message_thread_id'] = TG_TOPIC;

    $url = 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage';
    $body = http_build_query($params);
    $res = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
    } else {
        // Shared hosts sometimes ship without curl; the stream wrapper is
        // always there, and @ keeps a refused connection out of the response.
        $res = @file_get_contents($url, false, stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $body,
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]));
    }

    $ok = is_string($res) && (json_decode($res, true)['ok'] ?? false) === true;
    if (!$ok) nox_log('TG failed: ' . substr((string)$res, 0, 300));
    return $ok;
}
