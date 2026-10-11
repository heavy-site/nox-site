<?php
// Where Telegram sends what happens with the venue's own bot.
//
// In the venue's group: enquiries arrive there with their buttons, and the
// buttons are pressed there. In an admin's private chat with the bot: the
// conversations with renters — open one, write, close it. Renters never write
// here; they have a bot of their own (api/renter.php). Anyone else who finds
// this bot is pointed to that one.
//
// Connected from /admin, which points the bot here with a secret; a call
// without that secret is ignored.
require_once __DIR__ . '/_relay.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Telegram retries anything but a 200, so every answer below is a 200 — a
// refusal included — and nothing is sent back that it would act on.
$done = function () { echo '{}'; exit; };

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || TG_TOKEN === '') $done();
$given = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals(tg_hook_secret(), $given)) {
    nox_log('TG hook: bad secret ip=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $done();
}

$u = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($u)) $done();

// A booking's message with its buttons, sent to one chat.
$card = function (string $to, int $id) {
    $b = nox_booking($id);
    if (!$b) return tg_call('sendMessage', ['chat_id' => $to, 'text' => 'Заявку #' . $id . ' не знайдено.']);
    $kb = tg_booking_keyboard($b);
    return tg_call('sendMessage', ['chat_id' => $to, 'text' => tg_booking_text($b), 'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true'] + ($kb ? ['reply_markup' => json_encode(['inline_keyboard' => $kb])] : []));
};

/* ── a message ─────────────────────────────────────────────────────────── */
if (is_array($m = $u['message'] ?? null)) {
    $chat = (string)($m['chat']['id'] ?? '');
    $type = (string)($m['chat']['type'] ?? '');
    $text = trim((string)($m['text'] ?? ''));
    $from = (string)($m['from']['id'] ?? '');
    $name = trim((string)($m['from']['first_name'] ?? ''));

    if ($type !== 'private') {
        // /here, in a group, from the person the bot was set up for: the
        // enquiries move into that group, and its members become the admins.
        if (preg_match('/^\/here(@\w+)?$/', $text)) {
            if ($from === (string)TG_CHAT || $chat === tg_admin_chat()) {
                $old = tg_admin_chat();
                nox_meta('tg_admin_chat', $chat);
                if ($db = relay_db()) {
                    $db->exec('UPDATE bookings SET tg_message_id = 0');
                    $db->prepare('DELETE FROM tg_map2 WHERE admin_chat = ?')->execute([$old]);
                }
                nox_log('TG admin chat -> ' . $chat . ' by tg:' . $from);
                $bot = tg_admin_bot_name();
                tg_call('sendMessage', ['chat_id' => $chat, 'text' =>
                    "Готово: заявки з кнопками тепер ідуть сюди.\n\n" .
                    'Листування з орендарями — в особистих з ' . ($bot !== '' ? '@' . $bot : 'ботом') .
                    ': кожен учасник цієї групи може відкрити його й натиснути Start.']);
            }
            $done();
        }
        // /b 12 in the group: the booking's card, here and now.
        if ($chat === tg_admin_chat() && preg_match('/^\/b(?:@\w+)?\s*#?(\d+)$/', $text, $bm)) {
            $sent = $card($chat, (int)$bm[1]);
            if ($sent && nox_booking((int)$bm[1])) nox_booking_update((int)$bm[1], ['tg_message_id' => (int)$sent['message_id']]);
        }
        // Nothing else in a group is for the bot: people talk among themselves.
        $done();
    }

    // A private chat from someone who is not an admin: pointed to the renters' bot.
    if (!tg_is_admin($from)) {
        $bot = tg_renter_name();
        tg_call('sendMessage', ['chat_id' => $chat, 'text' => 'Це службовий бот nøx. ' .
            ($bot !== '' ? 'Написати нам можна тут: @' . $bot : 'Залиште заявку на сайті: ' . NOX_SITE_URL . '/booking')]);
        $done();
    }

    // An admin. Pressing Start is what lets the bot write to them first.
    nox_meta('tg_pm:' . $from, '1');

    if (preg_match('/^\/start(?:\s+(\S+))?$/', $text, $s)) {
        // Only brought here by a button that already did its work.
        if (($s[1] ?? '') === 'go') $done();
        // A link from a button in the group opens that conversation here.
        if (isset($s[1]) && preg_match('/^(open|log)_(b\d+|c-?\d+)$/', $s[1], $o)) {
            if ($o[1] === 'open') {
                tg_dlg_open($from, $name, $o[2]);
            } else {
                $bk = $o[2][0] === 'b' ? (int)substr($o[2], 1) : 0;
                tg_call('sendMessage', ['chat_id' => $chat, 'text' => tg_history_text($bk, $bk ? '' : substr($o[2], 1)),
                    'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
            }
            $done();
        }
        tg_call('sendMessage', ['chat_id' => $chat, 'text' =>
            'Вітаю' . ($name !== '' ? ', ' . $name : '') . "! Тут ви листуєтеся з орендарями.\n\n" .
            "Нові повідомлення від них приходять сюди. «💬 Відкрити діалог» — і пишіть просто в цей чат: " .
            "усе піде орендарю від імені бота для орендарів, ваших контактів він не побачить.\n\n" .
            "/chats — останні діалоги\n/end — вийти з діалогу\n/b 12 — заявка #12"]);
        $done();
    }
    if (preg_match('/^\/chats(@\w+)?$/', $text)) { tg_chats_list($chat); $done(); }
    if (preg_match('/^\/end(@\w+)?$/', $text)) {
        $was = tg_dlg_get($from);
        tg_dlg_set($from, '');
        tg_call('sendMessage', ['chat_id' => $chat, 'text' => $was
            ? 'Ви вийшли з діалогу. Повідомлення більше нікому не йдуть. Відкрити інший: /chats'
            : 'Ви й так не в діалозі. Відкрити: /chats']);
        $done();
    }
    if (preg_match('/^\/b(?:@\w+)?\s*#?(\d+)$/', $text, $bm)) { $card($chat, (int)$bm[1]); $done(); }

    tg_from_admin($m);
    $done();
}

/* ── a button ──────────────────────────────────────────────────────────── */
$q = $u['callback_query'] ?? null;
if (!is_array($q)) $done();

$answer = function (string $text, bool $alert = false, string $url = '') use ($q) {
    tg_call('answerCallbackQuery', [
        'callback_query_id' => $q['id'],
        'text'              => $text,
        'show_alert'        => $alert ? 'true' : 'false',
    ] + ($url !== '' ? ['url' => $url] : []));
};

$chat = (string)($q['message']['chat']['id'] ?? '');
$admin = (string)($q['from']['id'] ?? '');
$adminName = trim((string)($q['from']['first_name'] ?? ''));
$inGroup = $chat === tg_admin_chat();
if (!$inGroup && !($chat === $admin && tg_is_admin($admin))) {
    $answer('Цей чат не керує бронями.');
    $done();
}
$data = (string)($q['data'] ?? '');

// ⬅️ Вийти з діалогу.
if ($data === 'close') {
    tg_dlg_set($admin, '');
    $answer('Ви вийшли з діалогу');
    tg_call('sendMessage', ['chat_id' => $admin, 'text' => 'Ви вийшли з діалогу. Відкрити інший: /chats']);
    $done();
}

// 💬 Відкрити діалог (and ✍️ Написати under older messages) and 📜 Уся
// історія, for a booking (b12) or a chat with no booking (c123). Both happen
// in the admin's private chat with the bot; pressed in the group by someone
// who has not started the bot yet, the press opens the bot for them first.
if (preg_match('/^(open|write|log):(b(\d+)|c(-?\d+))$/', $data, $t)) {
    $key = $t[2];
    $booking = isset($t[3]) && $t[3] !== '' ? (int)$t[3] : 0;
    $verb = $t[1] === 'log' ? 'log' : 'open';

    if (nox_meta('tg_pm:' . $admin) !== '1') {
        $bot = tg_admin_bot_name();
        $answer('', false, $bot !== '' ? 'https://t.me/' . $bot . '?start=' . $verb . '_' . $key : '');
        $done();
    }

    $to = $booking ? tg_booking_chat($booking) : (string)($t[4] ?? '');
    if ($verb === 'log') {
        tg_call('sendMessage', ['chat_id' => $admin, 'text' => tg_history_text($booking, $booking ? '' : $to),
            'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']);
    } else {
        tg_dlg_open($admin, $adminName, $key);
    }
    // From the group, the press also takes them to that private chat.
    $bot = $inGroup ? tg_admin_bot_name() : '';
    $answer($inGroup ? 'Відкрито в особистих з ботом' : '', false, $bot !== '' ? 'https://t.me/' . $bot . '?start=go' : '');
    $done();
}

if (!preg_match('/^(new|confirmed|declined|cancelled):(\d+)$/', $data, $m)) $done();
$status = $m[1];
$id = (int)$m[2];

$b = nox_booking($id);
if (!$b) { $answer('Бронь #' . $id . ' не знайдено.', true); $done(); }

// The group's message the press came from is the one kept in step from now on.
$msg = (int)($q['message']['message_id'] ?? 0);
if ($inGroup && $msg && (int)$b['tg_message_id'] !== $msg) nox_booking_update($id, ['tg_message_id' => $msg]);

list($ok, $why) = nox_set_status($id, $status);
if (!$ok) {
    $answer($why, true);
    tg_sync_booking($id);
    $done();
}

$who = trim(($q['from']['first_name'] ?? '') . ' ' . ($q['from']['last_name'] ?? ''));
nox_log('BOOKING #' . $id . ' -> ' . $status . ' by tg:' . ($q['from']['id'] ?? '?') . ' ' . $who);

$said = ['new' => 'Повернуто в нові', 'confirmed' => 'Підтверджено', 'declined' => 'Відхилено',
         'cancelled' => 'Бронь скасовано'];
$answer($said[$status]);
tg_sync_booking($id);
// A card in a private chat is drawn again there too.
if (!$inGroup && $msg) {
    $nb = nox_booking($id);
    $kb = tg_booking_keyboard($nb);
    tg_call('editMessageText', ['chat_id' => $chat, 'message_id' => $msg, 'text' => tg_booking_text($nb),
        'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']
        + ($kb ? ['reply_markup' => json_encode(['inline_keyboard' => $kb])] : []));
}
tg_tell_status($id, $b['status'], $status);
$done();
