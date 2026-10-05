<?php
// Single source of truth for the venue, what it rents out, and its calendar.

function nox_venue(): array {
    return [
        'name'     => 'nøx',
        'address'  => 'Нижньоюрківська 31, Київ',
        'street'   => 'Нижньоюрківська 31',
        'geo'      => '50.466861,30.500806',
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
        ['value' => '4,1 м',    'label' => 'барна стійка'],
        // Танцпол — окреме приміщення, 7,35 × 12 м (обміри у вікі, PRODUCT.md).
        ['value' => '84,1 м²',  'label' => 'танцпол, окреме приміщення'],
    ];
}

// The ground outside, for the plan's second view. Capacities are estimates:
// a standing guest takes about half a square metre, a parked car about 25
// with its share of the drive.
function nox_outside(): array {
    return [
        ['value' => '28 м²',   'label' => 'тераса, 4 × 7 м'],
        ['value' => '≈ 50',    'label' => 'гостей на терасі'],
        ['value' => '203 м²',  'label' => 'парковка, 14 × 14,5 м'],
        ['value' => '≈ 8',     'label' => 'авто на парковці'],
    ];
}

// What the organiser gets. Split into what is here and what is arranged.
function nox_included(): array {
    return [
        'included' => [
            ['title' => 'Зал 215 м²',        'note' => '18,00 × 12,00 м, шість колон по периметру танцполу'],
            ['title' => 'Бар 4,1 м',         'note' => 'три секції фронту, робоча лінія за стійкою, холодильники'],
            ['title' => 'Гардероб',          'note' => 'окрема зона біля входу'],
            ['title' => 'Санвузол на 6 кабін','note' => 'два умивальники, чотири пісуари'],
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

// The calendar as it was written before the bookings had a database. It seeds
// the database once, on its first run, and it is what the site shows on a host
// without SQLite. New nights are added in /admin, not here.
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
        [
            'id'          => 'dolls-rave',
            'title'       => 'DOLLS RAVE',
            'promoter'    => '',
            'genre'       => 'Hard Techno',
            'date'        => '2026-10-17',
            'dateEnd'     => '',
            'dateText'    => '17 жовтня',
            'year'        => '2026',
            'time'        => '16:00–22:00',
            'tickets'     => '',
            'post'        => 'https://www.instagram.com/p/DeHTbgUMODh/',
            'lineup'      => 'NORDEIL × VITALI TASH × FOAVAS × D3ADW1RE × ATRK × PTERODACTYL × NIKOLIETTA × 2SIDE × TSURA × SVZST NPS × PUSSY KILLER',
            'poster'      => '/assets/dolls-poster.jpg',
            'posterSmall' => '/assets/dolls-poster-720.jpg',
        ],
        [
            'id'          => 'heavy-oct',
            'title'       => 'HEAVY',
            'promoter'    => '',
            'genre'       => 'Heavy EDM',
            'date'        => '2026-10-24',
            'dateEnd'     => '',
            'dateText'    => '24 жовтня',
            'year'        => '2026',
            'time'        => '18:00–22:00',
            'tickets'     => 'https://he4vy.com/tickets',
            'post'        => 'https://www.instagram.com/reel/DeCShr_szA7/',
            'lineup'      => '18:00–20:00 Mad Cult b2b Artem' . "\n"
                           . '20:00–21:00 Toxic Killer' . "\n"
                           . '21:00–22:00 Smolyakov',
            'poster'      => '/assets/heavy-poster.jpg',
            'posterSmall' => '/assets/heavy-poster-720.jpg',
        ],
        [
            'id'          => 'mysterium-2',
            'title'       => 'Mysterium · Episode II',
            'promoter'    => '',
            'genre'       => 'Hardbass, Breakcore, Neotrance, Hard Trance, Melodic Hard Techno',
            'date'        => '2026-10-31',
            'dateEnd'     => '2026-11-01',
            'dateText'    => '31 жовтня – 1 листопада',
            'year'        => '2026',
            'time'        => '16:00–22:00',
            'tickets'     => 'https://asura.company/b/bcae524ae6',
            'post'        => 'https://www.instagram.com/p/Dd9NEhOqDfQ/',
            'lineup'      => 'День 1: ASURA NBLCK, QKI, MILLAREN, SVZHST nps, 1240+, AUDIOVOVA' . "\n"
                           . 'День 2: ASURA NBLCK, SKY MAVKA, VERARTUM, TEMP3R, DINASTIA',
            'poster'      => '/assets/mysterium-poster.jpg',
            'posterSmall' => '/assets/mysterium-poster-720.jpg',
        ],
    ];
}

// Confirmed nights marked for the listing, from the database; the list above
// only when there is no database to read.
function nox_split_events(): array {
    require_once __DIR__ . '/_db.php';
    $today = nox_today();
    $upcoming = $past = [];
    foreach (nox_public_events() ?? nox_events() as $e) {
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
