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
    $db->exec('CREATE TABLE IF NOT EXISTS tg_map2 (
        admin_chat TEXT NOT NULL,                   -- an admin\'s private chat with the venue\'s bot, or the group
        admin_msg  INTEGER NOT NULL,
        chat       TEXT NOT NULL,
        booking_id INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (admin_chat, admin_msg)
    )');
    // A renter's conversation as a topic in an admin's private chat with the
    // venue's bot: one per admin and conversation.
    $db->exec('CREATE TABLE IF NOT EXISTS tg_topics (
        admin      TEXT NOT NULL,
        conv       TEXT NOT NULL,                   -- b<booking> or c<renter chat>
        thread     INTEGER NOT NULL,
        created_at INTEGER NOT NULL,
        PRIMARY KEY (admin, conv)
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
// The kind of file a message carries, in the order above: an animation also
// comes with a document, and is sent as what it is.
function tg_media_kind(array $m): string {
    foreach (array_reverse(TG_MEDIA) as $kind => $method) if (isset($m[$kind])) return $kind;
    return '';
}

// The file_id a sent message got, to send the same file to someone else
// without carrying it again.
function tg_file_id($sent, string $kind): string {
    if (!is_array($sent) || !isset($sent[$kind])) return '';
    $item = $kind === 'photo' ? end($sent['photo']) : $sent[$kind];
    return (string)($item['file_id'] ?? '');
}

function tg_carry(string $fromToken, array $m, string $toToken, array $params) {
    $kind = tg_media_kind($m);
    if ($kind !== '') {
        $method = TG_MEDIA[$kind];
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

/* ── who answers ──────────────────────────────────────────────────────────
   Enquiries and their buttons stay in the venue's group. Conversations with
   renters happen in each admin's private chat with the venue's bot, and an
   admin is anyone in that group (or the person TG_CHAT names, when it is a
   person). Telegram is asked once a day per admin. */
function tg_is_admin(string $uid): bool {
    if ($uid === '' || $uid[0] === '-') return false;
    if ($uid === (string)TG_CHAT || $uid === tg_admin_chat()) return true;
    $group = tg_admin_chat();
    if ($group === '' || $group[0] !== '-') return false;
    $seen = (int)nox_meta('tg_adm:' . $uid);
    if ($seen && time() - $seen < 86400) return true;
    $r = tg_call('getChatMember', ['chat_id' => $group, 'user_id' => $uid]);
    $st = (string)($r['status'] ?? '');
    $ok = in_array($st, ['creator', 'administrator', 'member'], true) || ($st === 'restricted' && !empty($r['is_member']));
    nox_meta('tg_adm:' . $uid, $ok ? (string)time() : '');
    if (!$ok) nox_meta('tg_pm:' . $uid, '');
    return $ok;
}

// The admins who pressed Start in the venue's bot, so it can write to them.
function tg_admins(): array {
    $db = relay_db();
    if (!$db) return [];
    $rows = $db->query("SELECT k FROM meta WHERE k LIKE 'tg_pm:%' AND v = '1'")->fetchAll(PDO::FETCH_COLUMN);
    return array_map(function ($k) { return substr($k, 6); }, $rows);
}

// Of those, the ones who have this conversation open right now.
function tg_admins_in(string $key): array {
    $db = relay_db();
    if (!$db) return [];
    $st = $db->prepare("SELECT k FROM meta WHERE k LIKE 'tg_dlg:%' AND v = ?");
    $st->execute([$key]);
    $open = array_map(function ($k) { return substr($k, 7); }, $st->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_intersect($open, tg_admins()));
}

// The venue's bot's own @name, for links that open it.
function tg_admin_bot_name(): string {
    $n = nox_meta('tg_bot');
    if ($n !== '') return $n;
    $me = tg_call('getMe', []);
    $n = (string)($me['username'] ?? '');
    if ($n !== '') nox_meta('tg_bot', $n);
    return $n;
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

function tg_map_put(string $adminChat, int $adminMsg, string $chat, int $booking): void {
    $db = relay_db();
    if (!$db || !$adminMsg) return;
    $db->prepare('INSERT OR REPLACE INTO tg_map2 (admin_chat, admin_msg, chat, booking_id, created_at) VALUES (?, ?, ?, ?, ?)')
       ->execute([$adminChat, $adminMsg, $chat, $booking, time()]);
}

// Which renter a message stands for: one the bot mapped in that chat, or a
// booking's card in the group, whose renter is known once they have linked.
function tg_map_get(string $adminChat, int $adminMsg): ?array {
    $db = relay_db();
    if (!$db || !$adminMsg) return null;
    $st = $db->prepare('SELECT chat, booking_id FROM tg_map2 WHERE admin_chat = ? AND admin_msg = ?');
    $st->execute([$adminChat, $adminMsg]);
    if ($r = $st->fetch()) return ['chat' => (string)$r['chat'], 'booking' => (int)$r['booking_id']];
    if ($adminChat !== tg_admin_chat()) return null;
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

/* ── topics in the admins' private chats ──────────────────────────────────
   When the venue's bot has topics turned on in @BotFather, every renter is a
   topic of their own in each admin's private chat with it — "DOLLS RAVE ·
   Олена" — and the admin just writes in that topic. Nothing to open or close.
   Telegram added this only recently, so anything it refuses sends the bot
   back to the conversations opened with a button, for a few hours, before it
   tries topics again. */
function tg_topics_on(): bool {
    if ((int)nox_meta('tg_topics_off_until') > time()) return false;
    if (time() - (int)nox_meta('tg_topics_at') > 3600) {
        $me = tg_call('getMe', []);
        if ($me) {
            nox_meta('tg_topics', !empty($me['has_topics_enabled']) ? '1' : '0');
            nox_meta('tg_topics_at', (string)time());
        }
    }
    return nox_meta('tg_topics') === '1';
}

function tg_topics_fail(string $why): void {
    nox_log('TG topics off for 6h: ' . $why);
    nox_meta('tg_topics_off_until', (string)(time() + 6 * 3600));
}

function tg_topic_name(string $chat, int $booking): string {
    $p = tg_person($chat);
    $b = $booking ? nox_booking($booking) : null;
    $event = $b ? nox_booking_label($b) : 'без заявки';
    $who = $p && $p['name'] !== '' ? $p['name'] : 'Орендар';
    return mb_substr(($event !== '' ? $event : '#' . $booking) . ' · ' . $who, 0, 120);
}

function tg_topic_key(string $admin, int $thread): string {
    $db = relay_db();
    if (!$db || !$thread) return '';
    $st = $db->prepare('SELECT conv FROM tg_topics WHERE admin = ? AND thread = ?');
    $st->execute([$admin, $thread]);
    return (string)($st->fetchColumn() ?: '');
}

function tg_topic_forget(string $admin, string $key): void {
    if ($db = relay_db()) $db->prepare('DELETE FROM tg_topics WHERE admin = ? AND conv = ?')->execute([$admin, $key]);
}

// The admin's topic for a conversation, made the first time it is needed;
// a new one opens with the last of what was said, so whoever comes in late
// reads from there. 0 when Telegram would not make it.
function tg_topic_for(string $admin, string $key, string $chat, int $booking, int $skip = 0): int {
    $db = relay_db();
    if (!$db) return 0;
    $st = $db->prepare('SELECT thread FROM tg_topics WHERE admin = ? AND conv = ?');
    $st->execute([$admin, $key]);
    if ($th = (int)$st->fetchColumn()) return $th;

    $t = tg_call('createForumTopic', ['chat_id' => $admin, 'name' => tg_topic_name($chat, $booking)]);
    $th = (int)($t['message_thread_id'] ?? 0);
    if (!$th) { tg_topics_fail('createForumTopic: ' . ($GLOBALS['tg_last_error'] ?? '')); return 0; }
    $db->prepare('INSERT OR REPLACE INTO tg_topics (admin, conv, thread, created_at) VALUES (?, ?, ?, ?)')
       ->execute([$admin, $key, $th, time()]);

    $rows = tg_history($booking, $booking ? '' : $chat);
    if ($skip) $rows = array_slice($rows, 0, -$skip);
    $last = '';
    foreach (array_slice($rows, -10) as $r) {
        $mark = $r['dir'] === 'in' ? '⬅️' : ($r['dir'] === 'out' ? '➡️ ' . tg_esc($r['who'] !== '' ? $r['who'] : 'Ми') . ':' : '🤖');
        $last .= "\n" . $mark . ' ' . tg_esc(mb_strimwidth((string)$r['body'], 0, 300, '…'));
    }
    tg_call('sendMessage', ['chat_id' => $admin, 'message_thread_id' => $th, 'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
        'text' => '💬 <b>' . tg_who_line($chat, $booking) . '</b>' . ($last !== '' ? "\n\n<i>Що було раніше:</i>" . $last : '') .
                  "\n\nПишіть у цій темі — усе піде орендарю від імені бота для орендарів."]);
    return $th;
}

// One message into an admin's topic: a file (once carried, then by its
// file_id), or text. A topic the admin deleted is made again, once. Null
// when topics did not work out, so the caller falls back.
function tg_topic_post(string $admin, string $key, string $chat, int $booking, string $html, ?array $m, string &$fid, int $skip = 0) {
    $kind = $m ? tg_media_kind($m) : '';
    for ($try = 0; $try < 2; $try++) {
        $th = tg_topic_for($admin, $key, $chat, $booking, $skip);
        if (!$th) return null;
        $base = ['chat_id' => $admin, 'message_thread_id' => $th, 'parse_mode' => 'HTML'];
        $r = null;
        if ($kind !== '') {
            $cap = mb_substr($html, 0, 1000);
            $r = $fid !== '' ? tg_call(TG_MEDIA[$kind], $base + ['caption' => $cap, $kind => $fid])
                             : tg_carry(tg_renter_token(), $m, TG_TOKEN, $base + ['caption' => $cap]);
            if ($r && $fid === '') $fid = tg_file_id($r, $kind);
        } else {
            $r = tg_call('sendMessage', $base + ['text' => $html !== '' ? $html : '…', 'disable_web_page_preview' => 'true']);
        }
        if ($r) return $r;
        if (stripos($GLOBALS['tg_last_error'] ?? '', 'thread') === false) break;
        tg_topic_forget($admin, $key);
    }
    tg_topics_fail('send: ' . ($GLOBALS['tg_last_error'] ?? ''));
    return null;
}

/* ── into the admins' private chats ───────────────────────────────────────
   Whoever has this conversation open gets it plainly, as the next line of
   the dialog; if nobody has, every admin who started the bot gets it with
   the buttons to step in. If no admin has started the bot yet, it goes to
   the group so it is not lost, with a word on how to take it private. */
function tg_deliver(string $chat, int $booking, string $bodyHtml, ?array $m = null, int $skip = 0): void {
    $key = $booking ? 'b' . $booking : 'c' . $chat;
    // With topics every admin gets it in their topic; without, whoever has
    // the conversation open, or everyone with the button to step in.
    $topics = tg_topics_on();
    $open = $topics ? [] : tg_admins_in($key);
    $targets = $open ?: tg_admins();
    $p = tg_person($chat);
    // Inside an open conversation: who, and which night it is about.
    $b = $booking ? nox_booking($booking) : null;
    $event = $b ? nox_booking_label($b) : '';
    $short = '⬅️ <b>' . tg_esc($p && $p['name'] !== '' ? $p['name'] : 'Орендар') . '</b>' .
             ($event !== '' ? ' · ' . tg_esc($event) : ($booking ? '' : ' · <i>без заявки</i>'));
    $full = '💬 <b>' . tg_who_line($chat, $booking) . '</b>';
    $kb = json_encode(['inline_keyboard' => [tg_talk_row($booking, $chat)]]);
    $kind = $m ? tg_media_kind($m) : '';
    $fid = '';

    if (!$targets) {
        $bot = tg_admin_bot_name();
        $hint = $bot !== '' ? "\n\n" . '<i>Щоб листуватися в особистих, відкрийте @' . tg_esc($bot) . ' і натисніть Start.</i>' : '';
        $r = tg_call('sendMessage', tg_to_admin(['text' => $full . "\n\n" . $bodyHtml . $hint, 'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true', 'reply_markup' => $kb]));
        if ($r) tg_map_put(tg_admin_chat(), (int)$r['message_id'], $chat, $booking);
        return;
    }

    foreach ($targets as $uid) {
        if ($topics && tg_topics_on()) {
            $r = tg_topic_post($uid, $key, $chat, $booking, $bodyHtml, $kind !== '' ? $m : null, $fid, $skip);
            if ($r) { tg_map_put($uid, (int)$r['message_id'], $chat, $booking); continue; }
        }
        $inDlg = in_array($uid, $open, true);
        $head = $inDlg ? $short : $full;
        $base = ['chat_id' => $uid, 'parse_mode' => 'HTML'] + ($inDlg ? [] : ['reply_markup' => $kb]);
        $r = null;
        if ($kind !== '') {
            $cap = mb_substr($head . ($bodyHtml !== '' ? "\n" . $bodyHtml : ''), 0, 1000);
            if ($fid !== '') {
                $r = tg_call(TG_MEDIA[$kind], $base + ['caption' => $cap, $kind => $fid]);
            } else {
                $r = tg_carry(tg_renter_token(), $m, TG_TOKEN, $base + ['caption' => $cap]);
                $fid = tg_file_id($r, $kind);
            }
        }
        if (!$r) {
            $r = tg_call('sendMessage', $base + ['text' => $head . "\n" . $bodyHtml, 'disable_web_page_preview' => 'true']);
        }
        if ($r) tg_map_put($uid, (int)$r['message_id'], $chat, $booking);
    }
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
            tg_deliver($chat, $id, '🔗 <i>відкрив бота — можна писати.</i>');
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

    // To the admins: text as it is, a file carried across with the caption,
    // anything that cannot travel as a line saying what it was.
    $html = isset($m['text']) ? tg_esc((string)$m['text'])
          : (tg_media_kind($m) !== '' ? tg_esc((string)($m['caption'] ?? '')) : tg_esc($body));
    tg_deliver($chat, $booking, $html, isset($m['text']) ? null : $m, 1);
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
        tg_call('sendMessage', ['chat_id' => $admin, 'text' => $link === ''
            ? 'Бот для орендарів ще не підключений — це робиться внизу /admin.'
            : '🔗 #' . $booking . ' ще не відкривав бота, тому написати йому бот не може.' . "\n\n" .
              'Надішліть йому один раз це посилання — після «Start» листування піде через бота:' . "\n" . $link,
            'disable_web_page_preview' => 'true']);
        return;
    }
    // With topics, opening is going to the topic: it is made if it is not
    // there yet, and a line lands in it so it comes up in the list.
    if (tg_topics_on()) {
        $db = relay_db();
        $had = false;
        if ($db) {
            $st = $db->prepare('SELECT 1 FROM tg_topics WHERE admin = ? AND conv = ?');
            $st->execute([$admin, $key]);
            $had = (bool)$st->fetchColumn();
        }
        $th = tg_topic_for($admin, $key, $chat, $booking);
        if ($th) {
            if ($had) tg_call('sendMessage', ['chat_id' => $admin, 'message_thread_id' => $th,
                'text' => '👇 Тут. Пишіть у цій темі — усе піде орендарю.']);
            return;
        }
    }
    tg_dlg_set($admin, $key);
    $rows = array_slice(tg_history($booking, $booking ? '' : $chat), -10);
    $last = '';
    foreach ($rows as $r) {
        $mark = $r['dir'] === 'in' ? '⬅️' : ($r['dir'] === 'out' ? '➡️' : '🤖');
        $last .= "\n" . $mark . ' ' . tg_esc(mb_strimwidth((string)$r['body'], 0, 300, '…'));
    }
    $sent = tg_call('sendMessage', [
        'chat_id' => $admin,
        'text' => '💬 <b>' . tg_esc($adminName) . ', ви в діалозі:</b>' . "\n" . tg_who_line($chat, $booking) .
                  ($last !== '' ? "\n\n" . '<i>Останнє:</i>' . $last : '') . "\n\n" .
                  'Пишіть просто сюди — усе піде орендарю від імені бота. Вийти: /end',
        'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true',
        'reply_markup' => json_encode(['inline_keyboard' => [[['text' => '⬅️ Вийти з діалогу', 'callback_data' => 'close']]]]),
    ]);
    if ($sent) tg_map_put($admin, (int)$sent['message_id'], $chat, $booking);
}

// /chats: the latest conversations, one button each.
function tg_chats_list(string $to): void {
    $db = relay_db();
    if (!$db) return;
    $rows = $db->query('SELECT chat, booking_id FROM tg_people ORDER BY updated_at DESC LIMIT 12')->fetchAll();
    if (!$rows) {
        tg_call('sendMessage', ['chat_id' => $to, 'text' => 'Поки ніхто з орендарів не писав.']);
        return;
    }
    $kb = [];
    foreach ($rows as $r) {
        $key = (int)$r['booking_id'] ? 'b' . (int)$r['booking_id'] : 'c' . $r['chat'];
        $label = html_entity_decode(strip_tags(tg_who_line((string)$r['chat'], (int)$r['booking_id'])), ENT_QUOTES);
        $kb[] = [['text' => mb_strimwidth($label, 0, 60, '…'), 'callback_data' => 'open:' . $key]];
    }
    tg_call('sendMessage', ['chat_id' => $to, 'text' => 'Оберіть діалог:', 'reply_markup' => json_encode(['inline_keyboard' => $kb])]);
}

/* ── the venue writes ─────────────────────────────────────────────────────── */

// What an admin writes in their private chat with the venue's bot goes to the
// renter of the conversation they have open — or of the message they reply
// to — through the renters' bot, as that bot's own message: text as text, a
// file carried across, so nothing of the venue's account goes with it.
function tg_from_admin(array $m): void {
    $text = trim((string)($m['text'] ?? ''));
    $admin = (string)($m['from']['id'] ?? '');

    // Written in a renter's topic: to that renter, whatever else is open.
    $to = null;
    $thread = !empty($m['is_topic_message']) ? (int)($m['message_thread_id'] ?? 0) : 0;
    if ($thread && ($conv = tg_topic_key($admin, $thread)) !== '') {
        if ($text !== '' && $text[0] === '/') return;
        $bk = $conv[0] === 'b' ? (int)substr($conv, 1) : 0;
        $ch = $bk ? tg_booking_chat($bk) : substr($conv, 1);
        if ($ch !== '') $to = ['chat' => $ch, 'booking' => $bk];
    }
    $reply = (int)($m['reply_to_message']['message_id'] ?? 0);
    if (!$to && $reply) $to = tg_map_get((string)$m['chat']['id'], $reply);

    // A reply goes where it points; anything else, to the conversation this
    // admin has open. A command is never a message to a renter.
    if (!$to && $text !== '' && $text[0] === '/') return;
    if (!$to) $to = tg_dlg_get($admin);

    if (!$to) {
        // In a group people talk among themselves; only a private chat with
        // the bot gets told how it works.
        if (($m['chat']['type'] ?? '') === 'private') {
            tg_call('sendMessage', ['chat_id' => $m['chat']['id'], 'reply_to_message_id' => $m['message_id'],
                'text' => tg_topics_on()
                    ? 'Пишіть у темі потрібного орендаря — кожен орендар тут окрема тема. Список: /chats'
                    : 'Ви зараз не в діалозі. Відкрийте його кнопкою «💬 Відкрити діалог» під заявкою чи повідомленням, або командою /chats.']);
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
        tg_map_put((string)$m['chat']['id'], (int)$m['message_id'], $to['chat'], $to['booking']);
        tg_echo_out($admin, $who, $m, $to['chat'], $to['booking']);
        tg_call('setMessageReaction', ['chat_id' => $m['chat']['id'], 'message_id' => (int)$m['message_id'],
            'reaction' => json_encode([['type' => 'emoji', 'emoji' => '👌']])]);
    } else {
        tg_call('sendMessage', ['chat_id' => $m['chat']['id'], 'reply_to_message_id' => $m['message_id'],
            'text' => '⚠️ Не доставлено: орендар, мабуть, зупинив бота.']);
    }
}

// What one admin sent shows in the other admins' topics too, so each of them
// reads the whole conversation and anyone can take it over.
function tg_echo_out(string $admin, string $who, array $m, string $chat, int $booking): void {
    if (!tg_topics_on()) return;
    $key = $booking ? 'b' . $booking : 'c' . $chat;
    $head = '➡️ <b>' . tg_esc($who !== '' ? $who : 'Адмін') . '</b>';
    foreach (tg_admins() as $uid) {
        if ($uid === $admin) continue;
        $th = tg_topic_for($uid, $key, $chat, $booking, 1);
        if (!$th) return;
        if (isset($m['text'])) {
            tg_call('sendMessage', ['chat_id' => $uid, 'message_thread_id' => $th, 'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true', 'text' => $head . "\n" . tg_esc((string)$m['text'])]);
        } else {
            $cap = (string)($m['caption'] ?? '');
            tg_call('copyMessage', ['chat_id' => $uid, 'message_thread_id' => $th, 'from_chat_id' => $admin,
                'message_id' => (int)$m['message_id'], 'parse_mode' => 'HTML',
                'caption' => mb_substr($head . ($cap !== '' ? "\n" . tg_esc($cap) : ''), 0, 1000)]);
        }
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
    // Whoever has this conversation open in Telegram sees it there too.
    if (tg_topics_on()) {
        $fid = '';
        foreach (tg_admins() as $uid) tg_topic_post($uid, 'b' . $id, $chat, $id, '➡️ <i>з адмінки:</i>' . "\n" . tg_esc($text), null, $fid, 1);
        return true;
    }
    foreach (tg_admins_in('b' . $id) as $uid) {
        tg_call('sendMessage', ['chat_id' => $uid, 'text' => '➡️ <i>з адмінки:</i>' . "\n" . tg_esc($text),
            'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
    }
    return true;
}
