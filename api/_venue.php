<?php
// Single source of truth for the venue, what it rents out, and its calendar.

function nox_venue(): array {
    return [
        'name'     => 'nøx',
        'address'  => 'Нижньоюрківська 31, Київ',
        'street'   => 'Нижньоюрківська 31',
        'geo'      => '50.466564192974495,30.499941806080255',
        'phone'    => '+380 63 309 8621',
        'tel'      => '+380633098621',
        'email'    => 'e.pyvovar@gmail.com',
        'contact'  => 'Євген Пивовар',
        'telegram' => '',
        'instagram'=> '',
    ];
}

// The numbers an organiser scans first.
function nox_headline(): array {
    return [
        ['value' => '215 м²',   'label' => 'зал'],
        ['value' => '300–350',  'label' => 'гостей'],
        ['value' => '9,6 м',    'label' => 'барна стійка'],
        ['value' => '18 × 12',  'label' => 'метрів, без колон по центру'],
    ];
}

// What the organiser gets. Split into what is here and what is arranged.
function nox_included(): array {
    return [
        'included' => [
            ['title' => 'Зал 215 м²',        'note' => '18,00 × 12,00 м, шість колон по периметру танцполу'],
            ['title' => 'Бар 9,6 м',         'note' => 'три секції фронту, робоча лінія за стійкою, холодильники'],
            ['title' => 'Гардероб',          'note' => 'окрема зона біля входу'],
            ['title' => 'Санвузол на 7 кабін','note' => 'умивальники, пісуари'],
            ['title' => 'Тераса',            'note' => 'вихід просто із залу'],
            ['title' => 'Парковка',          'note' => 'своя, біля входу'],
        ],
        'arranged' => [
            ['title' => 'Звук і світло',     'note' => 'привозите своє або орендуємо — підкажемо, з ким працюємо'],
            ['title' => 'Бармени',           'note' => 'наша команда, кількість — під ваш прогноз'],
            ['title' => 'Охорона',           'note' => 'на вході й у залі'],
        ],
    ];
}

// Calendar. Newest first; the upcoming/past split is computed from the dates.
function nox_events(): array {
    return [
        [
            'id'          => 'insane-rave',
            'title'       => 'Insane Rave',
            'promoter'    => 'HEAVY',
            'date'        => '2026-08-29',
            'dateEnd'     => '2026-08-30',
            'dateText'    => '29–30 серпня',
            'year'        => '2026',
            'time'        => '',
            'tickets'     => 'https://he4vy.com/tickets',
            'lineup'      => 'Mr.bilich, kaplini, MRX, mad cult, secret guest',
            // Афіша вечора. Два розрізи: широкий і той, що віддається малим
            // екранам. Кладіть у /assets, до 500 КБ, вертикальні 9:16.
            'poster'      => '/assets/insane-poster.jpg',
            'posterSmall' => '/assets/insane-poster-720.jpg',
        ],
    ];
}

function nox_split_events(): array {
    $today = (new DateTime('now', new DateTimeZone('Europe/Kyiv')))->format('Y-m-d');
    $upcoming = $past = [];
    foreach (nox_events() as $e) {
        if (($e['dateEnd'] ?: $e['date']) >= $today) { $upcoming[] = $e; } else { $past[] = $e; }
    }
    usort($upcoming, fn($a, $b) => strcmp($a['date'], $b['date']));
    usort($past,     fn($a, $b) => strcmp($b['date'], $a['date']));
    return ['upcoming' => $upcoming, 'past' => $past];
}

// Photos are read from /media, not from a list in code: drop a file in and it
// shows up. Order is by filename, so 01-…, 02-… controls the sequence.
// A matching .txt next to an image becomes its caption.
function nox_media(): array {
    $dir = dirname(__DIR__) . '/media';
    if (!is_dir($dir)) return [];

    $out = [];
    foreach (glob($dir . '/*.{jpg,jpeg,png,webp,avif}', GLOB_BRACE) ?: [] as $file) {
        $base    = pathinfo($file, PATHINFO_FILENAME);
        $capFile = $dir . '/' . $base . '.txt';
        $out[] = [
            'src'     => '/media/' . rawurlencode(basename($file)),
            'caption' => is_readable($capFile) ? trim((string)file_get_contents($capFile)) : '',
            'alt'     => 'nøx — ' . str_replace(['-', '_'], ' ', preg_replace('/^\d+[-_]?/', '', $base)),
        ];
    }
    return $out;
}
