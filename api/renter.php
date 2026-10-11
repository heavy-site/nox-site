<?php
// Where Telegram sends what renters write to the renters' bot. Pointed here
// from /admin with a secret worked out from that bot's token; anything
// without it is ignored. Only private chats count: the bot is not meant to
// sit in groups.
require_once __DIR__ . '/_relay.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Telegram retries anything but a 200, so every answer is a 200.
$done = function () { echo '{}'; exit; };

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || tg_renter_token() === '') $done();
$given = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if (!hash_equals(tg_renter_secret(), $given)) {
    nox_log('TG renter hook: bad secret ip=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $done();
}

$u = json_decode((string)file_get_contents('php://input'), true);
$m = is_array($u) ? ($u['message'] ?? null) : null;
if (is_array($m) && ($m['chat']['type'] ?? '') === 'private') tg_from_renter($m);
$done();
