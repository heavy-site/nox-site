<?php
/* ── posters for the site ─────────────────────────────────────────────────
   Uploaded on a booking's page, or taken from an image a renter sent the bot.
   Kept in /uploads/posters under the web root: the deploy copies the code
   over the site and never touches that folder, so an upload outlives every
   deploy. A file is taken only if it really is a JPEG, PNG or WebP, and is
   drawn again as two JPEGs — 1080 and 720 px wide — under a name made here,
   so nothing the browser sent, name or bytes, ends up on the site as it was.
   Without GD on the host the image is kept as it came, under the same rules. */
const NOX_POSTER_DIR = __DIR__ . '/../uploads/posters';
const NOX_POSTER_URL = '/uploads/posters';

function nox_bytes(string $v): int {
    $n = (int)$v;
    switch (strtolower(substr(trim($v), -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return $n;
}

function nox_upload_limit(): int {
    $l = array_filter([nox_bytes((string)ini_get('upload_max_filesize')), nox_bytes((string)ini_get('post_max_size'))]);
    return $l ? min($l) : 0;
}

function nox_mb(int $bytes): string {
    return rtrim(rtrim(number_format($bytes / 1048576, 1, ',', ''), '0'), ',') . ' МБ';
}

// A file sent from the booking page's form. [poster, poster_small] on
// success; a string saying what went wrong otherwise.
function nox_poster_upload(array $f) {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE)
        return 'Файл завеликий — максимум ' . nox_mb(nox_upload_limit()) . '.';
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name']))
        return 'Файл не завантажився — спробуйте ще раз.';
    return nox_poster_make((string)$f['tmp_name'], true);
}

// Any image file on the server — an upload, or one a renter sent the bot —
// made into the two posters. The source is left where it is unless $move.
function nox_poster_make(string $src, bool $move = false) {
    $f = ['tmp_name' => $src];
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) return 'Це не схоже на картинку. Підходять JPG, PNG або WebP.';

    if (!is_dir(NOX_POSTER_DIR) && !@mkdir(NOX_POSTER_DIR, 0755, true)) return 'Не вдалося створити папку для афіш на сервері.';
    // Nothing in this folder is ever run, whatever its name.
    $guard = NOX_POSTER_DIR . '/.htaccess';
    if (!is_file($guard)) @file_put_contents($guard, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n");

    $base = date('Ymd') . '-' . bin2hex(random_bytes(5));
    $load = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$types[$info[2]]];
    $img = function_exists($load) ? @$load($f['tmp_name']) : null;

    if (!$img) {
        // No GD (or it could not read the file): kept as it came.
        $name = $base . '.' . $types[$info[2]];
        $saved = $move ? move_uploaded_file($f['tmp_name'], NOX_POSTER_DIR . '/' . $name) : copy($f['tmp_name'], NOX_POSTER_DIR . '/' . $name);
        if (!$saved) return 'Не вдалося зберегти файл на сервері.';
        @chmod(NOX_POSTER_DIR . '/' . $name, 0644);
        return [NOX_POSTER_URL . '/' . $name, NOX_POSTER_URL . '/' . $name];
    }

    // A phone photo stands the way it was held.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
        $turn = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($turn) { $r = imagerotate($img, $turn, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }

    $out = [];
    foreach (['' => 1080, '-720' => 720] as $suffix => $w) {
        $sw = imagesx($img); $sh = imagesy($img);
        $tw = min($w, $sw); $th = (int)round($sh * $tw / $sw);
        $dst = imagecreatetruecolor($tw, $th);
        // A transparent PNG lands on the site's own black, not on white.
        imagefill($dst, 0, 0, imagecolorallocate($dst, 6, 7, 10));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $sw, $sh);
        $name = $base . $suffix . '.jpg';
        $ok = imagejpeg($dst, NOX_POSTER_DIR . '/' . $name, 86);
        imagedestroy($dst);
        if (!$ok) return 'Не вдалося зберегти файл на сервері.';
        @chmod(NOX_POSTER_DIR . '/' . $name, 0644);
        $out[] = NOX_POSTER_URL . '/' . $name;
    }
    imagedestroy($img);
    return $out;
}

// An upload that was replaced goes, so the folder holds only what is in use.
function nox_poster_drop(string $path): void {
    if (strpos($path, NOX_POSTER_URL . '/') !== 0) return;
    $file = NOX_POSTER_DIR . '/' . basename($path);
    if (is_file($file)) @unlink($file);
}


// A booking's poster replaced by these two, the old uploads cleared away.
function nox_poster_set(int $id, array $paths): void {
    $b = nox_booking($id);
    if (!$b) return;
    nox_booking_update($id, ['poster' => $paths[0], 'poster_small' => $paths[1]]);
    foreach (['poster', 'poster_small'] as $pf) {
        if (!in_array($b[$pf], $paths, true)) nox_poster_drop($b[$pf]);
    }
}
