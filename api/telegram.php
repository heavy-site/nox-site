<?php
// Where Telegram sends what happens with the venue's own bot: a press of a
// button under a booking, and messages in the venue's chat. Renters never
// write here — they have a bot of their own (api/renter.php). Connected from
// /admin, which points the bot here with a secret; a call without that secret
// is ignored.
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

/* ── a message ─────────────────────────────────────────────────────────── */
if (is_array($m = $u['message'] ?? null)) {
    $chat = (string)($m['chat']['id'] ?? '');
    $type = (string)($m['chat']['type'] ?? '');
    $text = trim((string)($m['text'] ?? ''));
    $from = (string)($m['from']['id'] ?? '');

    // /here, in a group, from the person the bot was set up for: the venue's
    // side moves into that group, so whoever is in it reads and answers. Old
    // booking messages stay where they were and are no longer tied to anyone.
    if (preg_match('/^\/here(@\w+)?$/', $text) && $type !== 'private') {
        if ($from === (string)TG_CHAT || $chat === tg_admin_chat()) {
            nox_meta('tg_admin_chat', $chat);
            if ($db = relay_db()) { $db->exec('UPDATE bookings SET tg_message_id = 0'); $db->exec('DELETE FROM tg_map'); }
            nox_log('TG admin chat -> ' . $chat . ' by tg:' . $from);
            tg_call('sendMessage', ['chat_id' => $chat, 'text' =>
                "Готово: заявки й переписка з орендарями тепер ідуть сюди.\n\n" .
                "Відповідайте (Reply) на повідомлення орендаря — бот перешле від свого імені.\n" .
                "/b 12 — показати заявку #12 з кнопками."]);
        }
        $done();
    }

    if ($chat === tg_admin_chat()) {
        // /b 12 — a booking's message with its buttons, here and now: how
        // someone who takes over finds a conversation that started without them.
        if (preg_match('/^\/b(?:@\w+)?\s*#?(\d+)$/', $text, $bm)) {
            $b = nox_booking((int)$bm[1]);
            if (!$b) {
                tg_call('sendMessage', ['chat_id' => $chat, 'text' => 'Заявку #' . (int)$bm[1] . ' не знайдено.']);
            } else {
                $sent = tg_send(tg_booking_text($b), tg_booking_keyboard($b));
                if ($sent) nox_booking_update((int)$b['id'], ['tg_message_id' => (int)$sent['message_id']]);
            }
            $done();
        }
        tg_from_admin($m);
        $done();
    }

    // The owner's own private chat, once the venue's side has moved to a group.
    if ($type === 'private' && $from === (string)TG_CHAT) {
        tg_call('sendMessage', ['chat_id' => $chat, 'text' => 'Заявки й переписка тепер у групі — відповідайте там.']);
        $done();
    }

    // Anyone else who finds this bot is sent to the one meant for them.
    if ($type === 'private') {
        $bot = tg_renter_name();
        tg_call('sendMessage', ['chat_id' => $chat, 'text' => 'Це службовий бот nøx. ' .
            ($bot !== '' ? 'Написати нам можна тут: @' . $bot : 'Залиште заявку на сайті: ' . NOX_SITE_URL . '/booking')]);
    }
    $done();
}

/* ── a button ──────────────────────────────────────────────────────────── */
$q = $u['callback_query'] ?? null;
if (!is_array($q)) $done();

$answer = function (string $text, bool $alert = false) use ($q) {
    tg_call('answerCallbackQuery', [
        'callback_query_id' => $q['id'],
        'text'              => $text,
        'show_alert'        => $alert ? 'true' : 'false',
    ]);
};

$chat = (string)($q['message']['chat']['id'] ?? '');
if ($chat !== tg_admin_chat()) {
    $answer('Цей чат не керує бронями.');
    $done();
}
$data = (string)($q['data'] ?? '');

// ✍️ Написати and 📜 Діалог, for a booking (b12) or a chat with no booking (c123).
if (preg_match('/^(write|log):(b(\d+)|c(-?\d+))$/', $data, $t)) {
    $booking = isset($t[3]) && $t[3] !== '' ? (int)$t[3] : 0;
    $to = $booking ? tg_booking_chat($booking) : (string)($t[4] ?? '');

    if ($t[1] === 'log') {
        $answer('');
        $sent = tg_call('sendMessage', tg_to_admin(['text' => tg_history_text($booking, $booking ? '' : $to),
            'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true']));
        if ($sent && $to !== '') tg_map_put((int)$sent['message_id'], $to, $booking);
        $done();
    }

    if ($to === '') {
        // They have not opened the bot yet, so it cannot write to them: the
        // link is posted here to be sent to them once, any way that works.
        $answer('Орендар ще не підключив Telegram.', false);
        $link = tg_start_link($booking);
        if ($link === '') {
            tg_call('sendMessage', tg_to_admin(['text' => 'Бот для орендарів ще не підключений — це робиться внизу /admin.']));
            $done();
        }
        tg_call('sendMessage', tg_to_admin(['text' =>
            '🔗 #' . $booking . ' ще не відкривав бота, тому написати йому бот не може.' . "\n\n" .
            'Надішліть йому один раз це посилання — після «Start» листування піде через бота:' . "\n" . $link,
            'disable_web_page_preview' => 'true']));
        $done();
    }

    $answer('');
    $prompt = tg_call('sendMessage', tg_to_admin([
        'text' => '✍️ <b>' . tg_who_line($to, $booking) . '</b>' . "\n" . 'Напишіть відповідь на це повідомлення — бот передасть її від свого імені.',
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode(['force_reply' => true, 'selective' => true,
                                       'input_field_placeholder' => 'Повідомлення орендарю…']),
    ]));
    if ($prompt) tg_map_put((int)$prompt['message_id'], $to, $booking);
    $done();
}

if (!preg_match('/^(new|confirmed|declined|cancelled):(\d+)$/', $data, $m)) $done();
$status = $m[1];
$id = (int)$m[2];

$b = nox_booking($id);
if (!$b) { $answer('Бронь #' . $id . ' не знайдено.', true); $done(); }

// The message the press came from is the one kept in step from now on.
$msg = (int)($q['message']['message_id'] ?? 0);
if ($msg && (int)$b['tg_message_id'] !== $msg) nox_booking_update($id, ['tg_message_id' => $msg]);

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
tg_tell_status($id, $b['status'], $status);
$done();
