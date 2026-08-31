<?php
// Single source of truth for the venue and its calendar.
// Everything the site shows about nøx is read from here.

function nox_venue(): array {
    return [
        'name'      => 'nøx',
        'city'      => 'Київ',
        'address'   => 'Нижньоюрківська 31, Київ',
        'street'    => 'Нижньоюрківська 31',
        'geo'       => '50.466564192974495,30.499941806080255',
        'area'      => 215,          // m²
        'capacity'  => [300, 350],   // guests
        'age'       => '18+',
        'phone'     => '+380 63 309 8621',
        'email'     => 'e.pyvovar@gmail.com',
        'contact'   => 'Євген Пивовар',
    ];
}

// Rentable specification shown on the "оренда" section.
// Keep to facts that are verified — an empty value is better than a guess.
function nox_spec(): array {
    return [
        ['label' => 'Площа',        'value' => '215 м²',            'note' => 'зал 18,00 × 12,00 м'],
        ['label' => 'Місткість',    'value' => '300–350 гостей',    'note' => ''],
        ['label' => 'Бар',          'value' => '9,6 м стійки',      'note' => 'три секції фронту, робоча лінія'],
        ['label' => 'Санвузол',     'value' => '7 кабін',           'note' => ''],
        ['label' => 'Гардероб',     'value' => 'є',                 'note' => ''],
        ['label' => 'Тераса',       'value' => 'є',                 'note' => ''],
        ['label' => 'Парковка',     'value' => 'є',                 'note' => ''],
        ['label' => 'Вік',          'value' => '18+',               'note' => ''],
    ];
}

// Calendar. Add a new entry on top; `date` drives the upcoming/past split.
//
//   id        slug used in the URL
//   title     event name as the organiser writes it
//   promoter  who runs the night
//   date      ISO start date (Y-m-d)
//   dateEnd   ISO end date for multi-night events, or null
//   dateText  how the date is printed on the page
//   time      doors — leave '' if not confirmed
//   lineup    list of names, may be empty
//   poster    path to the poster image, or '' for the typographic fallback
//   tickets   external ticket URL, or '' when the promoter sells elsewhere
function nox_events(): array {
    return [
        [
            'id'       => 'insane-rave',
            'title'    => 'Insane Rave',
            'promoter' => 'HEAVY',
            'date'     => '2026-08-29',
            'dateEnd'  => '2026-08-30',
            'dateText' => '29–30 серпня 2026',
            'time'     => '',
            'lineup'   => [],
            'poster'   => '',
            'tickets'  => 'https://he4vy.com/tickets',
        ],
    ];
}

// Split the calendar around today, Kyiv time.
function nox_split_events(): array {
    $tz    = new DateTimeZone('Europe/Kyiv');
    $today = (new DateTime('now', $tz))->format('Y-m-d');

    $upcoming = [];
    $past     = [];
    foreach (nox_events() as $e) {
        $end = $e['dateEnd'] ?: $e['date'];
        if ($end >= $today) { $upcoming[] = $e; } else { $past[] = $e; }
    }
    usort($upcoming, fn($a, $b) => strcmp($a['date'], $b['date']));
    usort($past,     fn($a, $b) => strcmp($b['date'], $a['date']));

    return ['upcoming' => $upcoming, 'past' => $past];
}
