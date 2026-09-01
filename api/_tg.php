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

/* A rental enquiry, laid out to be read on a phone: when the night is, who is
   asking and how to reach them, then what the night actually is. The date and
   the hours lead, because between them they decide most answers on their own.
   Every line below the head is optional and simply does not appear. */
function tg_enquiry(array $e): string {
    list($icon, $contact) = tg_contact($e['contact']);
    $v = function (string $k) use ($e) { return trim((string)($e[$k] ?? '')); };

    $out  = '<b>◆ ЗАЯВКА НА ОРЕНДУ</b>' . "\n\n";
    $out .= '📅 <b>' . tg_date($v('date')) . '</b>' . "\n";

    $hours = tg_hours($v('time_from'), $v('time_to'));
    if ($hours !== '') $out .= '🕘 <b>' . $hours . '</b>' . "\n";

    $out .= '👤 <b>' . tg_esc($v('name')) . '</b>' . "\n";
    $out .= $icon . ' ' . $contact;

    if ($v('event') !== '') $out .= "\n\n" . '🎧 ' . tg_esc($v('event'));

    // Music and the size of the line-up belong together — one is the shape of
    // the night, the other is how much of it has to be plugged in.
    $set = [];
    if ($v('music') !== '')   $set[] = tg_esc($v('music')) . ' музика';
    if ($v('artists') !== '') $set[] = tg_esc($v('artists')) . ' ' . tg_plural((int)$v('artists'), 'артист', 'артисти', 'артистів');
    if ($set) {
        $live = mb_stripos($v('music'), 'жив') === 0;
        $out .= "\n" . ($live ? '🎸 ' : '🎛 ') . implode(' · ', $set);
    }

    if ($v('guests') !== '') $out .= "\n" . '👥 ' . tg_esc($v('guests')) . ' гостей';
    if ($v('social') !== '') $out .= "\n" . '🔗 ' . tg_esc($v('social'));

    if ($v('comment') !== '') $out .= "\n\n" . '<blockquote>' . tg_esc($v('comment')) . '</blockquote>';

    $now = new DateTime('now', new DateTimeZone('Europe/Kyiv'));
    $out .= "\n\n" . '<i>' . $now->format('d.m, H:i') . ' · noxpl4ce.com</i>';
    return $out;
}

// 22:00 and 06:00 -> "22:00 — 06:00 · 8 год". Either end alone still reads.
function tg_hours(string $from, string $to): string {
    $ok = function ($t) { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); };
    if (!$ok($from) && !$ok($to)) return '';
    if (!$ok($to))   return tg_esc($from) . ' — ?';
    if (!$ok($from)) return '? — ' . tg_esc($to);

    $m = function ($t) { list($h, $i) = explode(':', $t); return (int)$h * 60 + (int)$i; };
    $len = $m($to) - $m($from);
    if ($len <= 0) $len += 24 * 60;                 // a night that crosses midnight
    $h = intdiv($len, 60); $i = $len % 60;
    $span = $h . ' год' . ($i ? ' ' . $i . ' хв' : '');
    return tg_esc($from) . ' — ' . tg_esc($to) . ' · ' . $span;
}

// 1 артист, 2 артисти, 5 артистів.
function tg_plural(int $n, string $one, string $few, string $many): string {
    $n = abs($n) % 100;
    if ($n >= 11 && $n <= 19) return $many;
    $n %= 10;
    if ($n === 1) return $one;
    if ($n >= 2 && $n <= 4) return $few;
    return $many;
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
