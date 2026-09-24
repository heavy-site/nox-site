<?php
// Where Telegram sends a press of a button under a booking. Connected from
// /admin, which points the bot here with a secret; a call without that secret,
// or a press from any chat but the venue's own, is ignored.
require_once __DIR__ . '/_tg.php';

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
$q = is_array($u) ? ($u['callback_query'] ?? null) : null;
if (!is_array($q)) $done();

$answer = function (string $text, bool $alert = false) use ($q) {
    tg_call('answerCallbackQuery', [
        'callback_query_id' => $q['id'],
        'text'              => $text,
        'show_alert'        => $alert ? 'true' : 'false',
    ]);
};

if ((string)($q['message']['chat']['id'] ?? '') !== (string)TG_CHAT) {
    $answer('Цей чат не керує бронями.');
    $done();
}

if (!preg_match('/^(new|confirmed|declined|cancelled):(\d+)$/', (string)($q['data'] ?? ''), $m)) $done();
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
$done();
