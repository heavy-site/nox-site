<?php
// The bookings: every enquiry from the form, what became of it, and the nights
// the site shows. One SQLite file outside the web root, next to the enquiry
// files it grew out of. It makes itself on first use — nothing to set up on
// the host.
//
// A booking has a status someone chose — new, confirmed, declined, cancelled —
// and a state the calendar works out: a confirmed night whose last day has
// gone is past, and a new enquiry nobody answered before its date is expired.
// Nothing moves a booking into the past by hand; the date does it.
require_once __DIR__ . '/_config.php';

const NOX_STATUSES = ['new', 'confirmed', 'declined', 'cancelled'];

// What the organiser sends, and what the venue adds for the listing.
const NOX_ENQUIRY_FIELDS = ['name', 'contact', 'telegram', 'event', 'date', 'date_end',
    'time_from', 'time_to', 'guests', 'artists', 'music', 'social', 'comment'];
const NOX_PUBLIC_FIELDS = ['title', 'promoter', 'lineup', 'tickets', 'poster', 'poster_small'];

// null when the host has no SQLite: then the site falls back to the calendar
// written in _venue.php and the form keeps writing files, as it always did.
function nox_db(): ?PDO {
    static $db = false;
    if ($db !== false) return $db;
    $db = null;
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        nox_log('DB off: no pdo_sqlite on this host');
        return null;
    }
    try {
        if (!is_dir(NOX_DATA_DIR)) @mkdir(NOX_DATA_DIR, 0750, true);
        $pdo = new PDO('sqlite:' . NOX_DATA_DIR . '/nox.sqlite', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec('PRAGMA journal_mode = WAL');
        nox_db_migrate($pdo);
        $db = $pdo;
    } catch (Throwable $e) {
        nox_log('DB error: ' . $e->getMessage());
    }
    return $db;
}

function nox_db_migrate(PDO $db): void {
    $v = (int)$db->query('PRAGMA user_version')->fetchColumn();
    if ($v >= 1) return;

    $db->beginTransaction();
    $db->exec("CREATE TABLE IF NOT EXISTS bookings (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        source        TEXT UNIQUE,                 -- enquiry file, seed:<id> or admin:<ts>
        status        TEXT NOT NULL DEFAULT 'new',
        date          TEXT NOT NULL,               -- YYYY-MM-DD
        date_end      TEXT NOT NULL DEFAULT '',    -- last day, when the night runs past midnight
        time_from     TEXT NOT NULL DEFAULT '',
        time_to       TEXT NOT NULL DEFAULT '',
        name          TEXT NOT NULL DEFAULT '',
        contact       TEXT NOT NULL DEFAULT '',
        telegram      TEXT NOT NULL DEFAULT '',
        event         TEXT NOT NULL DEFAULT '',
        guests        TEXT NOT NULL DEFAULT '',
        artists       TEXT NOT NULL DEFAULT '',
        music         TEXT NOT NULL DEFAULT '',
        social        TEXT NOT NULL DEFAULT '',
        comment       TEXT NOT NULL DEFAULT '',
        ip            TEXT NOT NULL DEFAULT '',
        published     INTEGER NOT NULL DEFAULT 0,  -- shown in the listing
        title         TEXT NOT NULL DEFAULT '',
        promoter      TEXT NOT NULL DEFAULT '',
        lineup        TEXT NOT NULL DEFAULT '',
        tickets       TEXT NOT NULL DEFAULT '',
        poster        TEXT NOT NULL DEFAULT '',
        poster_small  TEXT NOT NULL DEFAULT '',
        note          TEXT NOT NULL DEFAULT '',    -- for the venue only
        tg_message_id INTEGER NOT NULL DEFAULT 0,
        created_at    INTEGER NOT NULL,
        updated_at    INTEGER NOT NULL
    )");
    $db->exec('CREATE INDEX IF NOT EXISTS bookings_date ON bookings(date)');
    $db->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');

    // The nights that were written into the code become bookings: confirmed,
    // and shown, exactly as they were on the site.
    require_once __DIR__ . '/_venue.php';
    foreach (nox_events() as $e) {
        nox_booking_insert($db, [
            'status' => 'confirmed', 'published' => 1,
            'date' => $e['date'], 'date_end' => $e['dateEnd'] ?? '',
            'title' => $e['title'], 'event' => $e['title'],
            'promoter' => $e['promoter'] ?? '', 'name' => $e['promoter'] ?? '',
            'lineup' => $e['lineup'] ?? '', 'tickets' => $e['tickets'] ?? '',
            'poster' => $e['poster'] ?? '', 'poster_small' => $e['posterSmall'] ?? '',
        ], 'seed:' . $e['id']);
    }
    $db->exec('PRAGMA user_version = 1');
    $db->commit();

    nox_db_sync_files($db);
}

// Every enquiry file that is not in the table yet is taken in. The files are
// written first and always, so this is also how an enquiry that reached the
// disk but not the database — a locked file, a host without SQLite for a
// while — finds its way back.
function nox_db_sync_files(PDO $db): int {
    $dir = NOX_DATA_DIR . '/rent';
    $files = glob($dir . '/*.json') ?: [];
    if (!$files) return 0;
    $known = array_flip($db->query("SELECT source FROM bookings WHERE source LIKE 'rent/%'")
        ->fetchAll(PDO::FETCH_COLUMN));
    $n = 0;
    foreach ($files as $f) {
        $src = 'rent/' . basename($f);
        if (isset($known[$src])) continue;
        $e = json_decode((string)@file_get_contents($f), true);
        if (!is_array($e) || !nox_valid_date((string)($e['date'] ?? ''))) continue;
        $e['created_at'] = (int)($e['ts'] ?? filemtime($f));
        nox_booking_insert($db, $e, $src);
        $n++;
    }
    return $n;
}

function nox_meta(string $k, ?string $set = null): string {
    $db = nox_db();
    if (!$db) return '';
    if ($set !== null) {
        $db->prepare('INSERT INTO meta(k, v) VALUES(?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')
           ->execute([$k, $set]);
        return $set;
    }
    $st = $db->prepare('SELECT v FROM meta WHERE k = ?');
    $st->execute([$k]);
    return (string)($st->fetchColumn() ?: '');
}

function nox_valid_date(string $d): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function nox_today(): string {
    return (new DateTime('now', new DateTimeZone('Europe/Kyiv')))->format('Y-m-d');
}

function nox_last_day(array $b): string {
    return ($b['date_end'] ?? '') !== '' ? $b['date_end'] : $b['date'];
}

// What the calendar makes of a booking today.
//   new       an enquiry waiting for an answer
//   expired   an enquiry whose date came and went without one
//   booked    confirmed, still ahead (or tonight)
//   past      confirmed, and over
//   declined / cancelled
function nox_state(array $b, ?string $today = null): string {
    $today = $today ?? nox_today();
    switch ($b['status']) {
        case 'new':       return $b['date'] < $today ? 'expired' : 'new';
        case 'confirmed': return nox_last_day($b) < $today ? 'past' : 'booked';
        default:          return $b['status'];
    }
}

function nox_booking_insert(PDO $db, array $e, string $source): int {
    $now = time();
    $row = ['source' => $source, 'status' => in_array($e['status'] ?? '', NOX_STATUSES, true) ? $e['status'] : 'new',
            'published' => (int)!empty($e['published']), 'ip' => (string)($e['ip'] ?? ''),
            'note' => (string)($e['note'] ?? ''),
            'created_at' => (int)($e['created_at'] ?? $now), 'updated_at' => $now];
    foreach (array_merge(NOX_ENQUIRY_FIELDS, NOX_PUBLIC_FIELDS) as $f) {
        $row[$f] = trim((string)($e[$f] ?? ''));
    }
    $cols = array_keys($row);
    $db->prepare('INSERT OR IGNORE INTO bookings (' . implode(', ', $cols) . ') VALUES (' .
        implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
    return (int)$db->lastInsertId();
}

function nox_booking(int $id): ?array {
    $db = nox_db();
    if (!$db) return null;
    $st = $db->prepare('SELECT * FROM bookings WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

// Only the listed columns can be written, whatever the caller hands in.
function nox_booking_update(int $id, array $fields): void {
    $allowed = array_merge(NOX_ENQUIRY_FIELDS, NOX_PUBLIC_FIELDS,
        ['status', 'published', 'note', 'tg_message_id']);
    $set = []; $vals = [];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $set[] = $k . ' = ?';
        $vals[] = is_int($v) ? $v : trim((string)$v);
    }
    if (!$set) return;
    $set[] = 'updated_at = ?';
    $vals[] = time();
    $vals[] = $id;
    nox_db()->prepare('UPDATE bookings SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
}

function nox_booking_delete(int $id): void {
    nox_db()->prepare('DELETE FROM bookings WHERE id = ?')->execute([$id]);
}

// A confirmed booking that shares a day with the given span, other than the
// booking itself. Nights that run past midnight hold both of their days.
function nox_conflict(string $from, string $to, int $except = 0): ?array {
    $db = nox_db();
    if (!$db) return null;
    $to = $to !== '' ? $to : $from;
    $st = $db->prepare("SELECT * FROM bookings
        WHERE status = 'confirmed' AND id != ?
          AND date <= ? AND (CASE WHEN date_end != '' THEN date_end ELSE date END) >= ?
        ORDER BY date LIMIT 1");
    $st->execute([$except, $to, $from]);
    return $st->fetch() ?: null;
}

// Moving a booking between statuses. Confirming refuses a day that is already
// held by another confirmed night, and says whose it is.
function nox_set_status(int $id, string $status): array {
    $b = nox_booking($id);
    if (!$b) return [false, 'Бронь не знайдено.'];
    if (!in_array($status, NOX_STATUSES, true)) return [false, 'Невідомий статус.'];
    if ($status === 'confirmed') {
        $c = nox_conflict($b['date'], $b['date_end'], $id);
        if ($c) return [false, 'Дата вже зайнята: #' . $c['id'] . ' ' . nox_booking_label($c) . '.'];
    }
    $fields = ['status' => $status];
    // A night that is not happening leaves the listing with its status.
    if ($status !== 'confirmed') $fields['published'] = 0;
    nox_booking_update($id, $fields);
    return [true, ''];
}

function nox_booking_label(array $b): string {
    foreach (['title', 'event', 'name'] as $k) {
        if (trim((string)$b[$k]) !== '') return trim((string)$b[$k]);
    }
    return 'без назви';
}

// Confirmed days from today on, one entry per day, for the form to refuse.
// Only the days: who holds them is nobody else's business.
function nox_busy_dates(): array {
    $db = nox_db();
    if (!$db) return [];
    $today = nox_today();
    $st = $db->prepare("SELECT date, date_end FROM bookings WHERE status = 'confirmed'
        AND (CASE WHEN date_end != '' THEN date_end ELSE date END) >= ?");
    $st->execute([$today]);
    $days = [];
    foreach ($st->fetchAll() as $b) {
        $d = new DateTime($b['date']);
        $end = nox_last_day($b);
        for ($i = 0; $i < 14 && $d->format('Y-m-d') <= $end; $i++, $d->modify('+1 day')) {
            if ($d->format('Y-m-d') >= $today) $days[$d->format('Y-m-d')] = true;
        }
    }
    ksort($days);
    return array_keys($days);
}

// "29–30 серпня", "5 жовтня", "31 жовтня – 1 листопада": the way the listing
// has always written its dates, with the year carried separately.
function nox_date_text(string $from, string $to): string {
    $months = ['', 'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня',
               'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];
    $a = explode('-', $from);
    $one = (int)$a[2] . ' ' . $months[(int)$a[1]];
    if ($to === '' || $to === $from) return $one;
    $b = explode('-', $to);
    if ($a[1] === $b[1]) return (int)$a[2] . '–' . (int)$b[2] . ' ' . $months[(int)$b[1]];
    return $one . ' – ' . (int)$b[2] . ' ' . $months[(int)$b[1]];
}

// The listing: confirmed nights someone chose to show, in the shape the page
// has always been given.
function nox_public_events(): ?array {
    $db = nox_db();
    if (!$db) return null;
    $rows = $db->query("SELECT * FROM bookings WHERE status = 'confirmed' AND published = 1 ORDER BY date")
        ->fetchAll();
    return array_map(function ($b) {
        $time = $b['time_from'] !== '' ? $b['time_from'] . ($b['time_to'] !== '' ? '–' . $b['time_to'] : '') : '';
        return [
            'id'          => 'b' . $b['id'],
            'title'       => nox_booking_label(['title' => $b['title'], 'event' => $b['event'], 'name' => '']),
            'promoter'    => $b['promoter'],
            'date'        => $b['date'],
            'dateEnd'     => $b['date_end'],
            'dateText'    => nox_date_text($b['date'], $b['date_end']),
            'year'        => substr(nox_last_day($b), 0, 4),
            'time'        => $time,
            'tickets'     => $b['tickets'],
            'lineup'      => $b['lineup'],
            'poster'      => $b['poster'],
            'posterSmall' => $b['poster_small'],
        ];
    }, $rows);
}
