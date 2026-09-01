<?php
// Venue rental enquiry from the "оренда" section.
require_once __DIR__ . '/_tg.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некоректний запит']);
    exit;
}

// Honeypot: a hidden field only a bot fills in. Answer 200 so it learns nothing.
if (!empty($body['website'])) {
    nox_log('RENT honeypot hit ip=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    echo json_encode(['ok' => true]);
    exit;
}

if (!nox_throttle('rent')) {
    http_response_code(429);
    echo json_encode(['error' => 'Забагато запитів. Спробуйте за кілька хвилин.']);
    exit;
}

$get = fn(string $k) => trim((string)($body[$k] ?? ''));

$entry = [
    'name'      => $get('name'),
    'contact'   => $get('contact'),
    'telegram'  => $get('telegram'),
    'event'     => $get('event'),
    'date'      => $get('date'),
    'time_from' => $get('time_from'),
    'time_to'   => $get('time_to'),
    'guests'    => $get('guests'),
    'artists'   => $get('artists'),
    'music'     => $get('music'),
    'social'    => $get('social'),
    'comment'   => $get('comment'),
    'ts'        => time(),
    'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
];

foreach (['name', 'contact', 'date'] as $f) {
    if ($entry[$f] === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Заповніть обовʼязкові поля']);
        exit;
    }
}
// Telegram is required by the form, not here: a cached copy of an older page
// must still be able to send an enquiry through rather than fail on a field
// it does not know about.
foreach (['name', 'contact', 'telegram', 'event', 'date', 'time_from', 'time_to',
          'guests', 'artists', 'music', 'social'] as $f) {
    if (mb_strlen($entry[$f]) > 300) { $entry[$f] = mb_substr($entry[$f], 0, 300); }
}
$entry['comment'] = mb_substr($entry['comment'], 0, 2000);

// Written down before anything is sent, so an enquiry survives the bot being
// down, misconfigured, or not made yet. This file is the record; Telegram is
// only how someone hears about it.
$dir = NOX_DATA_DIR . '/rent';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
@file_put_contents(
    $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json',
    json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
);

tg_send(tg_enquiry($entry));

// The enquiry is on disk either way, so the sender always gets a clean answer.
echo json_encode(['ok' => true]);
