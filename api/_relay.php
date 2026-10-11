<?php
// Two bots between the venue and whoever rents it. Renters only ever see the
// renters' bot; the venue's own bot, the one that brings enquiries in, stays
// in the venue's chat. What a renter writes to their bot is brought into the
// venue's chat by the venue's bot; a reply there goes back out through the
// renters' bot as its own message. Nobody on the renter's side sees who
// answers. Everything said either way is kept here, by booking, so a
// conversation can be handed to someone else and read from the start.
//
// A bot cannot write first. The renter opens the renters' bot from the link
// the site sends them to after the form (t.me/<bot>?start=b<id>_<sig>), and
// from then on it knows which chat belongs to which booking.
//
// The renters' bot's token is TG_RENTER_TOKEN in nox_config.php, or the one
// entered in /admin. Without it there is no relay and the site says so.
require_once __DIR__ . '/_tg.php';

// The tables are made on first use and need nothing from the migrations.
function relay_db(): ?PDO {
    static $ready = false;
    $db = nox_db();
    if (!$db || $ready) return $db;
    $db->exec('CREATE TABLE IF NOT EXISTS tg_people (
        chat       TEXT PRIMARY KEY,                -- the renter\'s private chat with the bot
        username   TEXT NOT NULL DEFAULT \'\',
        name       TEXT NOT NULL DEFAULT \'\',
        booking_id INTEGER NOT NULL DEFAULT 0,      -- the booking they last came in through
        created_at INTEGER NOT NULL,
        updated_at INTEGER NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tg_booking (
        booking_id INTEGER PRIMARY KEY,
        chat       TEXT NOT NULL
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS tg_msgs (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        chat       TEXT NOT NULL,
        booking_id INTEGER NOT NULL DEFAULT 0,
        dir        TEXT NOT NULL,                   -- in: from the renter, out: to them, sys: the bot itself
        who        TEXT NOT NULL DEFAULT \'\',
        body       TEXT NOT NULL DEFAULT \'\',
        created_at INTEGER NOT NULL
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS tg_msgs_chat ON tg_msgs(chat, id)');
    // A message in the venue's chat that stands for a renter: what the bot
    // brought in, a prompt to write. A reply to it goes to that renter.
    $db->exec('CREATE TABLE IF NOT EXISTS tg_map (
        admin_msg  INTEGER PRIMARY KEY,
        chat       TEXT NOT NULL,
        booking_id INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL
    )');
    $ready = true;
    return $db;
}

function tg_to_admin(array $params) {
    $params['chat_id'] = tg_admin_chat();
    if (tg_admin_topic()) $params['message_thread_id'] = tg_admin_topic();
    return $params;
}

/* ── the renters' bot ─────────────────────────────────────────────────────── */
function tg_renter_token(): string {
    if (defined('TG_RENTER_TOKEN') && TG_RENTER_TOKEN !== '') return (string)TG_RENTER_TOKEN;
    return nox_meta('tg_renter_token');
}

function tg_renter(string $method, array $params, ?array $file = null) {
    return tg_call_as(tg_renter_token(), $method, $params, $file);
}

// Its @name, asked of Telegram once per token and kept.
function tg_renter_name(): string {
    $token = tg_renter_token();
    if ($token === '') return '';
    $key = 'tg_renter_name:' . substr(hash('sha256', $token), 0, 12);
    $n = nox_meta($key);
    if ($n !== '') return $n;
    $me = tg_renter('getMe', []);
    $n = (string)($me['username'] ?? '');
    if ($n !== '') nox_meta($key, $n);
    return $n;
}

// Telegram sends it back with every call to /api/renter.
function tg_renter_secret(): string {
    return substr(hash('sha256', 'nox-renter|' . tg_renter_token()), 0, 48);
}

/* Bots cannot copy each other's messages, so a file is carried across: fetched
   with the bot that received it, uploaded with the one that sends it on. The
   sent message, or null when the kind cannot travel (a sticker, a location)
   or the file would not come. */
const TG_MEDIA = [
    'photo' => 'sendPhoto', 'video' => 'sendVideo', 'document' => 'sendDocument', 'voice' => 'sendVoice',
    'audio' => 'sendAudio', 'animation' => 'sendAnimation', 'video_note' => 'sendVideoNote',
];
function tg_carry(string $fromToken, array $m, string $toToken, array $params) {
    foreach (TG_MEDIA as $kind => $method) {
        if (!isset($m[$kind])) continue;
        $item = $kind === 'photo' ? end($m['photo']) : $m[$kind];
        $f = tg_call_as($fromToken, 'getFile', ['file_id' => (string)($item['file_id'] ?? '')]);
        if (!$f || empty($f['file_path'])) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'tg');
        $data = @file_get_contents(tg_api_base() . '/file/bot' . $fromToken . '/' . $f['file_path']);
        if ($data === false || $tmp === false || file_put_contents($tmp, $data) === false) return null;
        $name = (string)($item['file_name'] ?? basename((string)$f['file_path']));
        $r = tg_call_as($toToken, $method, $params, [$kind, $tmp, $name]);
        @unlink($tmp);
        return $r;
    }
    return null;
}

/* ── the link that ties a chat to a booking ───────────────────────────────── */
function tg_start_sig(int $id): string {
    return substr(hash_hmac('sha256', 'start|' . $id, TG_TOKEN), 0, 12);
}

function tg_start_link(int $id): string {
    $bot = tg_renter_name();
    return $bot === '' ? '' : 'https://t.me/' . $bot . '?start=b' . $id . '_' . tg_start_sig($id);
}

// "b12_abc…" -> 12, or 0 when the link was not one of ours.
function tg_start_booking(string $payload): int {
    if (!preg_match('/^b(\d+)_([0-9a-f]{12})$/', $payload, $m)) return 0;
    return hash_equals(tg_start_sig((int)$m[1]), $m[2]) ? (int)$m[1] : 0;
}

function tg_booking_chat(int $id): string {
    $db = relay_db();
    if (!$db || !$id) return '';
    $st = $db->prepare('SELECT chat FROM tg_booking WHERE booking_id = ?');
    $st->execute([$id]);
    return (string)($st->fetchColumn() ?: '');
}

function tg_person(string $chat): ?array {
    $db = relay_db();
    if (!$db) return null;
    $st = $db->prepare('SELECT * FROM tg_people WHERE chat = ?');
    $st->execute([$chat]);
    return $st->fetch() ?: null;
}

function tg_person_seen(string $chat, array $from, int $booking = -1): void {
    $db = relay_db();
    if (!$db) return;
    $name = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
    $user = (string)($from['username'] ?? '');
    $now = time();
    $db->prepare('INSERT INTO tg_people (chat, username, name, booking_id, created_at, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?)
                  ON CONFLICT(chat) DO UPDATE SET username = excluded.username, name = excluded.name,
                  updated_at = excluded.updated_at' . ($booking >= 0 ? ', booking_id = excluded.booking_id' : ''))
       ->execute([$chat, $user, $name, max(0, $booking), $now, $now]);
    if ($booking > 0) {
        $db->prepare('INSERT INTO tg_booking (booking_id, chat) VALUES (?, ?)
                      ON CONFLICT(booking_id) DO UPDATE SET chat = excluded.chat')->execute([$booking, $chat]);
    }
}

function tg_log_msg(string $chat, int $booking, string $dir, string $who, string $body): void {
    $db = relay_db();
    if (!$db) return;
    $db->prepare('INSERT INTO tg_msgs (chat, booking_id, dir, who, body, created_at) VALUES (?, ?, ?, ?, ?, ?)')
       ->execute([$chat, $booking, $dir, $who, $body, time()]);
}

function tg_map_put(int $adminMsg, string $chat, int $booking): void {
    $db = relay_db();
    if (!$db || !$adminMsg) return;
    $db->prepare('INSERT OR REPLACE INTO tg_map (admin_msg, chat, booking_id, created_at) VALUES (?, ?, ?, ?)')
       ->execute([$adminMsg, $chat, $booking, time()]);
}

// Which renter a message in the venue's chat stands for: one the bot mapped,
// or a booking's own card, whose renter is known once they have linked.
function tg_map_get(int $adminMsg): ?array {
    $db = relay_db();
    if (!$db || !$adminMsg) return null;
    $st = $db->prepare('SELECT chat, booking_id FROM tg_map WHERE admin_msg = ?');
    $st->execute([$adminMsg]);
    if ($r = $st->fetch()) return ['chat' => (string)$r['chat'], 'booking' => (int)$r['booking_id']];
    $st = $db->prepare('SELECT id FROM bookings WHERE tg_message_id = ?');
    $st->execute([$adminMsg]);
    $id = (int)$st->fetchColumn();
    $chat = tg_booking_chat($id);
    return $chat !== '' ? ['chat' => $chat, 'booking' => $id] : null;
}

function tg_history(int $booking, string $chat = ''): array {
    $db = relay_db();
    if (!$db) return [];
    if ($booking) {
        $st = $db->prepare('SELECT * FROM tg_msgs WHERE booking_id = ? ORDER BY id');
        $st->execute([$booking]);
    } else {
        $st = $db->prepare('SELECT * FROM tg_msgs WHERE chat = ? AND booking_id = 0 ORDER BY id');
        $st->execute([$chat]);
    }
    return $st->fetchAll();
}

/* ── words ────────────────────────────────────────────────────────────────── */

// "#12 · DOLLS RAVE · Олена (@olena)" — enough to know who it is at a glance.
function tg_who_line(string $chat, int $booking): string {
    $p = tg_person($chat);
    $bits = [];
    if ($booking && ($b = nox_booking($booking))) {
        $bits[] = '#' . $booking;
        $label = nox_booking_label($b);
        if ($label !== '') $bits[] = tg_esc($label);
        $bits[] = tg_esc(substr(tg_date($b['date']), 0, 5));
    } else {
        $bits[] = 'без заявки';
    }
    if ($p) {
        $name = tg_esc($p['name']) . ($p['username'] !== '' ? ' (@' . tg_esc($p['username']) . ')' : '');
        if (trim($name) !== '') $bits[] = $name;
    }
    return implode(' · ', $bits);
}

// What a message holds, as one line for the history.
function tg_msg_body(array $m): string {
    $text = (string)($m['text'] ?? $m['caption'] ?? '');
    $kinds = ['photo' => 'фото', 'video' => 'відео', 'document' => 'файл', 'voice' => 'голосове',
              'audio' => 'аудіо', 'sticker' => 'стікер', 'video_note' => 'кружечок', 'location' => 'локація',
              'contact' => 'контакт', 'animation' => 'gif'];
    foreach ($kinds as $k => $label) {
        if (isset($m[$k])) {
            $extra = $k === 'document' ? trim((string)($m['document']['file_name'] ?? '')) : '';
            return '[' . $label . ($extra !== '' ? ' ' . $extra : '') . ']' . ($text !== '' ? ' ' . $text : '');
        }
    }
    return $text;
}

/* ── the renter writes ────────────────────────────────────────────────────── */
function tg_from_renter(array $m): void {
    $chat = (string)$m['chat']['id'];
    $from = $m['from'] ?? [];
    $text = trim((string)($m['text'] ?? ''));

    if (preg_match('/^\/start(?:\s+(\S+))?$/', $text, $s)) {
        $id = isset($s[1]) ? tg_start_booking($s[1]) : 0;
        $b = $id ? nox_booking($id) : null;
        if ($b) {
            tg_person_seen($chat, $from, $id);
            $hello = 'Вітаємо! Це nøx 🖤' . "\n\n" .
                     'Вашу заявку на <b>' . tg_date($b['date']) . '</b> отримали. ' .
                     'Відповідь прийде сюди, в цей чат. Питання можна писати тут же.';
            tg_say($chat, $hello, $id);
            $sent = tg_call('sendMessage', tg_to_admin([
                'text' => '🔗 ' . tg_who_line($chat, $id) . "\n" . 'підключив Telegram — можна писати через бота.',
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => [tg_talk_row($id)]]),
            ]));
            if ($sent) tg_map_put((int)$sent['message_id'], $chat, $id);
        } else {
            tg_person_seen($chat, $from);
            tg_say($chat, 'Вітаємо! Це nøx 🖤' . "\n\n" .
                'Напишіть ваше питання тут — ми відповімо в цьому чаті. ' .
                'Щоб забронювати дату, залиште заявку на сайті: ' . NOX_SITE_URL . '/booking', 0);
        }
        return;
    }

    $p = tg_person($chat);
    $booking = $p ? (int)$p['booking_id'] : 0;
    tg_person_seen($chat, $from);
    $body = tg_msg_body($m);
    tg_log_msg($chat, $booking, 'in', trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')), $body);

    // Into the venue's chat under a header naming the booking: text as one
    // message, a file carried across with the header as its caption, and
    // anything that cannot travel as a line saying what it was.
    $head = '💬 <b>' . tg_who_line($chat, $booking) . '</b>';
    $kb = json_encode(['inline_keyboard' => [tg_talk_row($booking, $chat)]]);
    $sent = null;
    if (!isset($m['text'])) {
        $cap = (string)($m['caption'] ?? '');
        $sent = tg_carry(tg_renter_token(), $m, TG_TOKEN, tg_to_admin([
            'caption' => mb_substr($head . ($cap !== '' ? "\n\n" . tg_esc($cap) : ''), 0, 1000),
            'parse_mode' => 'HTML', 'reply_markup' => $kb,
        ]));
    }
    if (!$sent) {
        $sent = tg_call('sendMessage', tg_to_admin([
            'text' => $head . "\n\n" . tg_esc($body), 'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true', 'reply_markup' => $kb,
        ]));
    }
    if ($sent) tg_map_put((int)$sent['message_id'], $chat, $booking);
}

// The bot's own word to a renter, kept in the history like the rest.
function tg_say(string $chat, string $html, int $booking) {
    $r = tg_renter('sendMessage', ['chat_id' => $chat, 'text' => $html, 'parse_mode' => 'HTML',
                                   'disable_web_page_preview' => 'true']);
    if ($r) tg_log_msg($chat, $booking, 'sys', 'бот', strip_tags($html));
    return $r;
}

// The two buttons under anything that stands for a renter.
function tg_talk_row(int $booking, string $chat = ''): array {
    $key = $booking ? 'b' . $booking : 'c' . $chat;
    return [
        ['text' => '💬 Відкрити діалог', 'callback_data' => 'open:' . $key],
        ['text' => '📜 Уся історія',     'callback_data' => 'log:' . $key],
    ];
}

/* ── a conversation one admin is in ───────────────────────────────────────
   Opened from a button or /chats, it lasts until /end or until another one is
   opened. While it is open, whatever that admin writes in the venue's chat —
   no reply needed — goes to that renter. Each admin has their own, so in a
   group two people can talk to two renters at once. */
function tg_dlg_get(string $admin): ?array {
    $key = nox_meta('tg_dlg:' . $admin);
    if (!preg_match('/^(b(\d+)|c(-?\d+))$/', $key, $k)) return null;
    $booking = isset($k[2]) && $k[2] !== '' ? (int)$k[2] : 0;
    $chat = $booking ? tg_booking_chat($booking) : (string)($k[3] ?? '');
    return $chat !== '' ? ['chat' => $chat, 'booking' => $booking, 'key' => $key] : null;
}

function tg_dlg_set(string $admin, string $key): void {
    nox_meta('tg_dlg:' . $admin, $key);
}

// Opening says who it is, shows the last of what was said, and offers the
// way out. The renter must have opened their bot first.
function tg_dlg_open(string $admin, string $adminName, string $key): void {
    $booking = $key[0] === 'b' ? (int)substr($key, 1) : 0;
    $chat = $booking ? tg_booking_chat($booking) : substr($key, 1);
    if ($chat === '') {
        $link = tg_start_link($booking);
        tg_call('sendMessage', tg_to_admin(['text' => $link === ''
            ? 'Бот для орендарів ще не підключений — це робиться внизу /admin.'
            : '🔗 #' . $booking . ' ще не відкривав бота, тому написати йому бот не може.' . "\n\n" .
              'Надішліть йому один раз це посилання — після «Start» листування піде через бота:' . "\n" . $link,
            'disable_web_page_preview' => 'true']));
        return;
    }
    tg_dlg_set($admin, $key);
    $rows = array_slice(tg_history($booking, $booking ? '' : $chat), -10);
    $last = '';
    foreach ($rows as $r) {
        $mark = $r['dir'] === 'in' ? '⬅️' : ($r['dir'] === 'out' ? '➡️' : '🤖');
        $last .= "\n" . $mark . ' ' . tg_esc(mb_strimwidth((string)$r['body'], 0, 300, '…'));
    }
    $sent = tg_call('sendMessage', tg_to_admin([
        'text' => '💬 <b>' . tg_esc($adminName) . ', ви в діалозі:</b>' . "\n" . tg_who_line($chat, $booking) .
                  ($last !== '' ? "\n\n" . '<i>Останнє:</i>' . $last : '') . "\n\n" .
                  'Пишіть просто сюди — усе піде орендарю від імені бота. Вийти: /end',
        'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '⬅️ Вийти з діалогу', 'callback_data' => 'close']]]]),
    ]));
    if ($sent) tg_map_put((int)$sent['message_id'], $chat, $booking);
}

// /chats: the latest conversations, one button each.
function tg_chats_list(): void {
    $db = relay_db();
    if (!$db) return;
    $rows = $db->query('SELECT chat, booking_id FROM tg_people ORDER BY updated_at DESC LIMIT 12')->fetchAll();
    if (!$rows) {
        tg_call('sendMessage', tg_to_admin(['text' => 'Поки ніхто з орендарів не писав.']));
        return;
    }
    $kb = [];
    foreach ($rows as $r) {
        $key = (int)$r['booking_id'] ? 'b' . (int)$r['booking_id'] : 'c' . $r['chat'];
        $label = html_entity_decode(strip_tags(tg_who_line((string)$r['chat'], (int)$r['booking_id'])), ENT_QUOTES);
        $kb[] = [['text' => mb_strimwidth($label, 0, 60, '…'), 'callback_data' => 'open:' . $key]];
    }
    tg_call('sendMessage', tg_to_admin(['text' => 'Оберіть діалог:', 'reply_markup' => json_encode(['inline_keyboard' => $kb])]));
}

/* ── the venue writes ─────────────────────────────────────────────────────── */

// A reply in the venue's chat to something that stands for a renter goes to
// them through the renters' bot, as that bot's own message: text as text, a
// file carried across, so nothing of the venue's account goes with it.
function tg_from_admin(array $m): void {
    $reply = (int)($m['reply_to_message']['message_id'] ?? 0);
    $to = $reply ? tg_map_get($reply) : null;
    $text = trim((string)($m['text'] ?? ''));
    $admin = (string)($m['from']['id'] ?? '');

    // A reply goes where it points; anything else, to the conversation this
    // admin has open. A command is never a message to a renter.
    if (!$to && $text !== '' && $text[0] === '/') return;
    if (!$to) $to = tg_dlg_get($admin);

    if (!$to) {
        // In a group people talk among themselves; only a private chat with
        // the bot gets told how it works.
        if (($m['chat']['type'] ?? '') === 'private') {
            tg_call('sendMessage', ['chat_id' => $m['chat']['id'], 'reply_to_message_id' => $m['message_id'],
                'text' => 'Ви зараз не в діалозі. Відкрийте його кнопкою «💬 Відкрити діалог» під заявкою чи повідомленням, або командою /chats.']);
        }
        return;
    }

    $who = trim(($m['from']['first_name'] ?? '') . ' ' . ($m['from']['last_name'] ?? ''));
    $r = null;
    if (isset($m['text'])) {
        $r = tg_renter('sendMessage', ['chat_id' => $to['chat'], 'text' => (string)$m['text'],
                                       'disable_web_page_preview' => 'true']);
    } else {
        $cap = (string)($m['caption'] ?? '');
        $r = tg_carry(TG_TOKEN, $m, tg_renter_token(), ['chat_id' => $to['chat']] + ($cap !== '' ? ['caption' => $cap] : []));
        if (!$r && !array_intersect_key($m, TG_MEDIA)) {
            tg_call('sendMessage', ['chat_id' => $m['chat']['id'], 'reply_to_message_id' => $m['message_id'],
                'text' => 'Такий тип повідомлення бот передати не може — надішліть текстом, фото або файлом.']);
            return;
        }
    }
    if ($r) {
        tg_log_msg($to['chat'], $to['booking'], 'out', $who, tg_msg_body($m));
        tg_map_put((int)$m['message_id'], $to['chat'], $to['booking']);
        tg_call('setMessageReaction', ['chat_id' => $m['chat']['id'], 'message_id' => (int)$m['message_id'],
            'reaction' => json_encode([['type' => 'emoji', 'emoji' => '👌']])]);
    } else {
        tg_call('sendMessage', ['chat_id' => $m['chat']['id'], 'reply_to_message_id' => $m['message_id'],
            'text' => '⚠️ Не доставлено: орендар, мабуть, зупинив бота.']);
    }
}

// The whole conversation, newest last, cut to what one message can hold.
function tg_history_text(int $booking, string $chat): string {
    $rows = tg_history($booking, $chat);
    $head = '📜 <b>Діалог · ' . tg_who_line($chat !== '' ? $chat : tg_booking_chat($booking), $booking) . '</b>';
    if (!$rows) return $head . "\n\n" . '<i>Поки нічого не писали.</i>';
    $lines = [];
    foreach ($rows as $r) {
        $at = new DateTime('@' . (int)$r['created_at']);
        $at->setTimezone(new DateTimeZone('Europe/Kyiv'));
        $mark = $r['dir'] === 'in' ? '⬅️ Орендар' : ($r['dir'] === 'out' ? '➡️ ' . ($r['who'] !== '' ? $r['who'] : 'Ми') : '🤖 Бот');
        $lines[] = '<i>' . $at->format('d.m H:i') . '</i> · <b>' . tg_esc($mark) . '</b>' . "\n" . tg_esc($r['body']);
    }
    $out = '';
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $next = $lines[$i] . ($out !== '' ? "\n\n" . $out : '');
        if (mb_strlen($head . $next) > 3800) { $out = '…' . "\n\n" . $out; break; }
        $out = $next;
    }
    return $head . "\n\n" . $out;
}

/* ── a booking's status reaches the renter ─────────────────────────────────── */
function tg_tell_status(int $id, string $old, string $new): void {
    if ($old === $new) return;
    $chat = tg_booking_chat($id);
    $b = nox_booking($id);
    if ($chat === '' || !$b) return;
    $day = '<b>' . tg_date($b['date']) . '</b>';
    $say = [
        'confirmed' => '✅ Вашу бронь на ' . $day . ' підтверджено. Деталі обговоримо тут, у цьому чаті.',
        'declined'  => 'На жаль, на ' . $day . ' ми не зможемо прийняти ваш івент. Якщо хочете обрати іншу дату — напишіть тут.',
        'cancelled' => 'Бронь на ' . $day . ' скасовано. Якщо це помилка — напишіть тут.',
    ][$new] ?? '';
    if ($say !== '') tg_say($chat, $say, $id);
}

// A message from /admin, for whoever answers from the web: it goes to the
// renter and shows in the venue's chat too, so the chat stays the whole story.
function tg_send_from_web(int $id, string $text, string $who): bool {
    $chat = tg_booking_chat($id);
    if ($chat === '' || trim($text) === '') return false;
    $r = tg_renter('sendMessage', ['chat_id' => $chat, 'text' => $text, 'disable_web_page_preview' => 'true']);
    if (!$r) return false;
    tg_log_msg($chat, $id, 'out', $who, $text);
    $echo = tg_call('sendMessage', tg_to_admin([
        'text' => '➡️ <b>' . tg_who_line($chat, $id) . '</b> · з адмінки' . "\n\n" . tg_esc($text),
        'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
        'reply_markup' => json_encode(['inline_keyboard' => [tg_talk_row($id)]]),
    ]));
    if ($echo) tg_map_put((int)$echo['message_id'], $chat, $id);
    return true;
}
