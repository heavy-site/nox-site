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
require_once __DIR__ . '/_db.php';

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

    // Telegram is a field of its own now — that is where the venue answers,
    // so it gets its own line even if the contact above is already a username.
    if ($v('telegram') !== '') $out .= "\n" . '💬 ' . tg_esc($v('telegram'));

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

    // When it came in — not when this text was drawn, since a booking's
    // message is drawn again every time its status changes.
    $now = new DateTime('@' . (int)($e['ts'] ?? $e['created_at'] ?? time()));
    $now->setTimezone(new DateTimeZone('Europe/Kyiv'));
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

// One call to the Bot API. The decoded result on Telegram's own ok:true, null
// on anything else. Never throws and never blocks the reply to the visitor: a
// form that worked must not look broken because a notification did not go out.
function tg_call(string $method, array $params) {
    if (TG_TOKEN === '') { nox_log('TG skipped: no token'); return null; }

    $url = 'https://api.telegram.org/bot' . TG_TOKEN . '/' . $method;
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

    $j = is_string($res) ? json_decode($res, true) : null;
    if (($j['ok'] ?? false) !== true) {
        nox_log('TG ' . $method . ' failed: ' . substr((string)$res, 0, 300));
        return null;
    }
    return $j['result'];
}

// A message to the venue's chat, with buttons under it when there are any.
// The sent message on success, null otherwise.
function tg_send(string $text, ?array $keyboard = null) {
    if (TG_TOKEN === '' || TG_CHAT === '') { nox_log('TG skipped: not configured'); return null; }
    $params = [
        'chat_id'                  => TG_CHAT,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 'true',
    ];
    if (TG_TOPIC) $params['message_thread_id'] = TG_TOPIC;
    if ($keyboard) $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    return tg_call('sendMessage', $params);
}

/* ── a booking in the chat ───────────────────────────────────────────────
   The enquiry message carries the booking's status at its foot and the
   buttons that move it on: confirm or decline a new one, cancel a confirmed
   one, reopen one that was turned down. Pressed in the chat or changed in
   /admin, the message is drawn again, so the chat never shows a status the
   database does not hold. The buttons exist only once the bot's webhook is
   connected from /admin — before that a press would go nowhere. */
const TG_STATE_LINE = [
    'new'       => '🟡 <b>Нова заявка</b>',
    'expired'   => '⚪️ <b>Без відповіді — дата минула</b>',
    'booked'    => '✅ <b>Підтверджено</b>',
    'past'      => '✔️ <b>Відбулося</b>',
    'declined'  => '✖️ <b>Відхилено</b>',
    'cancelled' => '↩️ <b>Скасовано</b>',
];

function tg_booking_text(array $b): string {
    $text = tg_enquiry($b);
    $state = nox_state($b);
    $line = TG_STATE_LINE[$state] ?? '';
    if ($state !== 'new' && $b['updated_at']) {
        $at = new DateTime('@' . (int)$b['updated_at']);
        $at->setTimezone(new DateTimeZone('Europe/Kyiv'));
        $line .= ' · ' . $at->format('d.m, H:i');
    }
    return $text . "\n" . $line . ' · #' . $b['id'];
}

function tg_booking_keyboard(array $b): ?array {
    if (nox_meta('tg_hook') !== '1') return null;
    $id = (int)$b['id'];
    $row = [];
    switch (nox_state($b)) {
        case 'new':
            $row[] = ['text' => '✅ Підтвердити', 'callback_data' => 'confirmed:' . $id];
            $row[] = ['text' => '✖️ Відхилити',  'callback_data' => 'declined:' . $id];
            break;
        case 'booked':
            $row[] = ['text' => '↩️ Скасувати бронь', 'callback_data' => 'cancelled:' . $id];
            break;
        case 'declined':
        case 'cancelled':
        case 'expired':
            $row[] = ['text' => '↺ Повернути в нові', 'callback_data' => 'new:' . $id];
            break;
    }
    $open = [['text' => 'Відкрити в адмінці', 'url' => NOX_SITE_URL . '/admin?id=' . $id]];
    return $row ? [$row, $open] : [$open];
}

// Draws the booking's message again after its status changed.
function tg_sync_booking(int $id): void {
    $b = nox_booking($id);
    if (!$b || !(int)$b['tg_message_id'] || TG_CHAT === '') return;
    $params = [
        'chat_id'                  => TG_CHAT,
        'message_id'               => (int)$b['tg_message_id'],
        'text'                     => tg_booking_text($b),
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 'true',
    ];
    $kb = tg_booking_keyboard($b);
    if ($kb) $params['reply_markup'] = json_encode(['inline_keyboard' => $kb]);
    tg_call('editMessageText', $params);
}

// The secret Telegram sends back with every webhook call, so the endpoint can
// tell the bot from anyone else posting to it. Worked out from the token, so
// there is nothing more to keep in the config.
function tg_hook_secret(): string {
    return substr(hash('sha256', 'nox-hook|' . TG_TOKEN), 0, 48);
}
