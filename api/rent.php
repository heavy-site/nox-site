<?php
// Venue rental enquiry from the "оренда" section.
require_once __DIR__ . '/_mail.php';
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
    'name'    => $get('name'),
    'contact' => $get('contact'),
    'event'   => $get('event'),
    'date'    => $get('date'),
    'guests'  => $get('guests'),
    'comment' => $get('comment'),
    'ts'      => time(),
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
];

foreach (['name', 'contact', 'date'] as $f) {
    if ($entry[$f] === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Заповніть обовʼязкові поля']);
        exit;
    }
}
foreach (['name', 'contact', 'event', 'date', 'guests'] as $f) {
    if (mb_strlen($entry[$f]) > 200) { $entry[$f] = mb_substr($entry[$f], 0, 200); }
}
$entry['comment'] = mb_substr($entry['comment'], 0, 2000);

// Keep a copy on disk even if the mail provider is down.
$dir = NOX_DATA_DIR . '/rent';
if (!is_dir($dir)) @mkdir($dir, 0750, true);
@file_put_contents(
    $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.json',
    json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    LOCK_EX
);

$replyTo = filter_var($entry['contact'], FILTER_VALIDATE_EMAIL) ? $entry['contact'] : '';

resend_send(
    'Заявка на оренду — ' . ($entry['date'] ?: $entry['name']),
    mail_shell('Заявка на оренду майданчика', mail_rows([
        'Організатор' => $entry['name'],
        'Контакт'     => $entry['contact'],
        'Подія'       => $entry['event'],
        'Дата'        => $entry['date'],
        'Гостей'      => $entry['guests'],
        'Коментар'    => $entry['comment'],
    ])),
    $replyTo
);

tg_send(tg_rows('Заявка на оренду', [
    'Організатор' => $entry['name'],
    'Контакт'     => $entry['contact'],
    'Дата'        => $entry['date'],
    'Подія'       => $entry['event'],
    'Гостей'      => $entry['guests'],
    'Коментар'    => $entry['comment'],
]));

// The enquiry is saved either way, so the sender always gets a clean answer.
echo json_encode(['ok' => true]);
