<?php
// /admin — the bookings. Every enquiry from the form lands here as a new one;
// it is confirmed or declined here or from the buttons under it in Telegram,
// and a confirmed night becomes past on its own once its date has gone. A
// confirmed night can be put in the listing with its title, line-up, tickets
// and poster.
//
// Closed until NOX_ADMIN_PASS is set in nox_config.php, outside the web root.
require_once __DIR__ . '/api/_tg.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Sessions live in the data dir with a long life of their own: the host's
// shared defaults would sign a phone out every twenty-odd minutes.
// Lax, not Strict: a link from Telegram is a visit from another site, and a
// Strict cookie would stay behind and ask for the password every time. Every
// action is a POST with its own token, which Lax never carries across sites.
$sess = NOX_DATA_DIR . '/sessions';
if (!is_dir($sess)) @mkdir($sess, 0700, true);
if (is_dir($sess) && is_writable($sess)) session_save_path($sess);
ini_set('session.gc_maxlifetime', (string)(30 * 86400));
session_name('noxadm');
session_set_cookie_params([
    'lifetime' => 30 * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
    'secure'   => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
]);
session_start();

const ADM = '/admin';

$STATE = [
    'new'       => ['Нова', 'new'],
    'expired'   => ['Без відповіді', 'off'],
    'booked'    => ['Заброньовано', 'ok'],
    'past'      => ['Відбулося', 'past'],
    'declined'  => ['Відхилено', 'off'],
    'cancelled' => ['Скасовано', 'off'],
];
$TABS = [
    'new'     => ['Нові',         ['new']],
    'booked'  => ['Заброньовані', ['booked']],
    'past'    => ['Пройшли',      ['past']],
    'archive' => ['Архів',        ['expired', 'declined', 'cancelled']],
    'all'     => ['Усі',          array_keys($STATE)],
];
$STATUS_LABEL = ['new' => 'Нова заявка', 'confirmed' => 'Підтверджено', 'declined' => 'Відхилено',
                 'cancelled' => 'Скасовано'];

function adm_password_ok(string $p): bool {
    $s = NOX_ADMIN_PASS;
    if ($s === '' || $p === '') return false;
    if (preg_match('/^\$(2y|argon2)/', $s)) return password_verify($p, $s);
    return hash_equals($s, $p);
}

function flash(?string $text = null, string $kind = 'ok') {
    if ($text !== null) { $_SESSION['flash'] = [$kind, $text]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function go(string $to): void { header('Location: ' . $to, true, 303); exit; }

function csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">'; }

/* ── the page frame ─────────────────────────────────────────────────── */
function page(string $title, string $body): void {
    ?><!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($title) ?> · nøx</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<style>
:root{
  --void:#06070A; --pit:#0A0C10; --slab:#0E1116; --bone:#E9E4D8; --ash:#9A9486; --dust:#6B665C;
  --gas:#2E9BF0; --hot:#7FD4FF; --pale:#B9E2FF; --line:#191C22; --line2:#262A32;
  --ok:#5FD08A; --warn:#F0B429; --bad:#F06A5A;
  --hud:ui-monospace,"SF Mono",Menlo,Consolas,monospace;
  --sans:-apple-system,BlinkMacSystemFont,"Segoe UI",system-ui,sans-serif;
}
*{ box-sizing:border-box; }
body{ margin:0; background:var(--void); color:var(--bone); font:15px/1.5 var(--sans); }
a{ color:var(--pale); }
.wrap{ max-width:1080px; margin:0 auto; padding:18px 16px 60px; }
header.top{ display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between;
  padding-bottom:16px; border-bottom:1px solid var(--line2); margin-bottom:18px; }
.brand{ font:600 18px var(--hud); letter-spacing:.06em; color:var(--bone); text-decoration:none; }
.brand span{ color:var(--dust); font-weight:400; }
.row{ display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.btn{ display:inline-block; font:12px var(--hud); letter-spacing:.1em; text-transform:uppercase;
  color:var(--bone); background:transparent; border:1px solid var(--line2); padding:9px 13px;
  cursor:pointer; text-decoration:none; border-radius:2px; }
.btn:hover{ border-color:var(--dust); }
.btn.main{ border-color:var(--gas); background:rgba(46,155,240,.14); }
.btn.good{ border-color:rgba(95,208,138,.6); color:var(--ok); }
.btn.bad{ border-color:rgba(240,106,90,.5); color:var(--bad); }
.btn.small{ padding:6px 10px; font-size:11px; }
.tabs{ display:flex; flex-wrap:wrap; gap:4px; margin-bottom:16px; }
.tabs a{ font:12px var(--hud); letter-spacing:.08em; text-transform:uppercase; text-decoration:none;
  color:var(--ash); padding:9px 12px; border:1px solid transparent; }
.tabs a.on{ color:var(--bone); border-color:var(--gas); }
.tabs b{ color:var(--pale); font-weight:400; margin-left:6px; }
.flash{ padding:12px 14px; margin-bottom:16px; border:1px solid var(--line2); font-size:14px; }
.flash.ok{ border-color:rgba(95,208,138,.5); color:var(--ok); }
.flash.err{ border-color:rgba(240,106,90,.5); color:var(--bad); }
.list{ display:grid; gap:8px; }
.card{ display:grid; grid-template-columns:150px 1fr auto; gap:14px; align-items:start;
  background:var(--slab); border:1px solid var(--line); padding:14px; }
.card:hover{ border-color:var(--line2); }
.card .d b{ display:block; font:600 16px var(--hud); }
.card .d span{ display:block; color:var(--ash); font-size:13px; }
.card .t{ font-weight:600; font-size:16px; }
.card .t a{ color:var(--bone); text-decoration:none; }
.card .t a:hover{ text-decoration:underline; }
.card .m{ color:var(--ash); font-size:13px; margin-top:2px; word-break:break-word; }
.card .side{ display:grid; gap:8px; justify-items:end; }
.tag{ display:inline-block; font:11px var(--hud); letter-spacing:.08em; text-transform:uppercase;
  padding:3px 8px; border:1px solid var(--line2); color:var(--ash); white-space:nowrap; }
.tag.new{ color:var(--warn); border-color:rgba(240,180,41,.5); }
.tag.ok{ color:var(--ok); border-color:rgba(95,208,138,.5); }
.tag.past{ color:var(--pale); border-color:rgba(185,226,255,.35); }
.tag.pub{ color:var(--hot); border-color:rgba(127,212,255,.45); }
.warn{ color:var(--warn); font-size:13px; margin-top:6px; }
.empty{ color:var(--dust); padding:30px 0; text-align:center; font:13px var(--hud); letter-spacing:.08em; }
form.inline{ display:inline; }
fieldset{ border:1px solid var(--line2); margin:0 0 14px; padding:14px; }
legend{ font:12px var(--hud); letter-spacing:.12em; text-transform:uppercase; color:var(--pale); padding:0 6px; }
.grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
label{ display:grid; gap:5px; font-size:13px; color:var(--ash); }
label.wide{ grid-column:1 / -1; }
label.check{ display:flex; align-items:center; gap:8px; color:var(--bone); font-size:15px; }
input,select,textarea{ font:15px var(--sans); color:var(--bone); background:var(--pit);
  border:1px solid var(--line2); padding:9px 10px; width:100%; border-radius:2px; }
input[type=checkbox]{ width:18px; height:18px; accent-color:var(--gas); }
textarea{ min-height:90px; resize:vertical; }
input:focus,select:focus,textarea:focus{ outline:1px solid var(--gas); border-color:var(--gas); }
.hint{ color:var(--dust); font-size:12px; }
.foot{ margin-top:34px; padding-top:16px; border-top:1px solid var(--line2); color:var(--ash); font-size:13px; }
.login{ max-width:340px; margin:14vh auto 0; display:grid; gap:12px; }
.m a{ color:var(--pale); text-decoration:none; }
.m a:hover{ text-decoration:underline; }
.chips{ display:flex; flex-wrap:wrap; gap:6px; margin-top:14px; }
.chip{ display:inline-flex; align-items:stretch; border:1px solid var(--line2); background:var(--pit); }
.chip > a, .chip > span{ padding:7px 10px; color:var(--bone); text-decoration:none; font-size:14px; }
.chip > a:hover{ color:var(--hot); }
.chip.sub > a{ font-size:12px; color:var(--ash); padding:7px 9px; }
.cp{ border:0; border-left:1px solid var(--line2); background:transparent; color:var(--ash);
  padding:0 10px; cursor:pointer; font:13px var(--hud); }
.cp:hover{ color:var(--hot); }
.cp.ok, .btn.ok{ color:var(--ok); }
details.mv summary{ list-style:none; }
details.mv summary::-webkit-details-marker{ display:none; }
details.mv form{ display:flex; gap:6px; margin-top:6px; }
details.mv input{ width:auto; padding:6px 8px; font-size:14px; }
@media (max-width:720px){
  .card{ grid-template-columns:1fr; }
  .card .side{ justify-items:start; }
}
</style>
</head>
<body><div class="wrap"><?= $body ?></div>
<script>
(function () {
  // Copy: navigator.clipboard where the page may use it, a hidden textarea
  // where it may not (some in-app browsers, Telegram's among them).
  function fallback(t) {
    var a = document.createElement("textarea");
    a.value = t; a.setAttribute("readonly", ""); a.style.position = "fixed"; a.style.opacity = "0";
    document.body.appendChild(a); a.select();
    var ok = false;
    try { ok = document.execCommand("copy"); } catch (e) {}
    a.remove();
    return ok;
  }
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-copy]");
    if (!b) return;
    e.preventDefault();
    var t = b.getAttribute("data-copy");
    var done = function () {
      var was = b.textContent;
      b.textContent = b.classList.contains("cp") ? "✓" : "✓ Скопійовано";
      b.classList.add("ok");
      setTimeout(function () { b.textContent = was; b.classList.remove("ok"); }, 1400);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(t).then(done, function () { if (fallback(t)) done(); });
    } else if (fallback(t)) done();
  });

  // Moving the start of a night moves its end with it, so a two-day night
  // stays two days long.
  var start = document.querySelector("form.edit input[name=date]"),
      end = document.querySelector("form.edit input[name=date_end]");
  if (start && end) {
    var was = start.value;
    start.addEventListener("change", function () {
      if (was && end.value && start.value) {
        var d = new Date(Date.parse(end.value) + Date.parse(start.value) - Date.parse(was));
        end.value = d.toISOString().slice(0, 10);
      }
      was = start.value;
    });
  }
})();
</script>
</body>
</html><?php
    exit;
}

/* ── closed, or not signed in ───────────────────────────────────────── */
if (NOX_ADMIN_PASS === '') {
    page('Адмінка', '<div class="login"><div class="brand">nøx <span>/ адмінка</span></div>' .
        '<p>Адмінку вимкнено: у <code>nox_config.php</code> не задано <code>NOX_ADMIN_PASS</code>.</p></div>');
}

$action = $_POST['action'] ?? '';

if ($action === 'login') {
    if (!nox_throttle('login', 10, 900)) {
        flash('Забагато спроб. Спробуйте за 15 хвилин.', 'err');
    } elseif (adm_password_ok((string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['adm'] = 1;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        nox_log('ADMIN login ip=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        go(ADM);
    } else {
        nox_log('ADMIN bad password ip=' . ($_SERVER['REMOTE_ADDR'] ?? ''));
        flash('Невірний пароль.', 'err');
    }
    go(ADM);
}

if (empty($_SESSION['adm'])) {
    $f = flash();
    page('Вхід', '<form class="login" method="post" action="' . ADM . '">' .
        '<div class="brand">nøx <span>/ адмінка</span></div>' .
        ($f ? '<div class="flash ' . h($f[0]) . '">' . h($f[1]) . '</div>' : '') .
        '<input type="hidden" name="action" value="login">' .
        '<label>Пароль<input type="password" name="password" autocomplete="current-password" autofocus required></label>' .
        '<button class="btn main" type="submit">Увійти</button></form>');
}

$db = nox_db();
if (!$db) {
    page('Адмінка', '<p>На хостингу немає SQLite (<code>pdo_sqlite</code>), тож бази броней немає. ' .
        'Заявки й далі зберігаються файлами в <code>' . h(NOX_DATA_DIR) . '/rent</code> і надходять у Telegram.</p>');
}

/* ── actions ─────────────────────────────────────────────────────────── */
if ($action !== '') {
    if (!hash_equals(csrf(), (string)($_POST['csrf'] ?? ''))) {
        flash('Сторінка застаріла — спробуйте ще раз.', 'err');
        go(ADM);
    }
    $id = (int)($_POST['id'] ?? 0);
    $back = (string)($_POST['back'] ?? ADM);
    if (strpos($back, ADM) !== 0) $back = ADM;

    switch ($action) {
        case 'logout':
            $_SESSION = [];
            session_destroy();
            go(ADM);

        case 'status':
            list($ok, $why) = nox_set_status($id, (string)($_POST['status'] ?? ''));
            if ($ok) {
                tg_sync_booking($id);
                nox_log('BOOKING #' . $id . ' -> ' . $_POST['status'] . ' by admin');
                flash('#' . $id . ': ' . $STATUS_LABEL[$_POST['status']] . '.');
            } else {
                flash($why, 'err');
            }
            go($back);

        case 'save':
            $in = [];
            foreach (array_merge(NOX_ENQUIRY_FIELDS, NOX_PUBLIC_FIELDS, ['note']) as $f) {
                $in[$f] = mb_substr(trim((string)($_POST[$f] ?? '')), 0, $f === 'comment' || $f === 'note' ? 4000 : 500);
            }
            $status = (string)($_POST['status'] ?? 'new');
            $in['published'] = (int)(!empty($_POST['published']) && $status === 'confirmed');
            $err = '';
            if (!nox_valid_date($in['date'])) $err = 'Вкажіть дату.';
            elseif ($in['date_end'] !== '' && (!nox_valid_date($in['date_end']) || $in['date_end'] < $in['date']))
                $err = 'Дата закінчення має бути не раніше за дату початку.';
            elseif (!in_array($status, NOX_STATUSES, true)) $err = 'Невідомий статус.';
            elseif ($status === 'confirmed' && ($c = nox_conflict($in['date'], $in['date_end'], $id)))
                $err = 'Дата вже зайнята: #' . $c['id'] . ' ' . nox_booking_label($c) . '.';
            if ($err !== '') {
                $_SESSION['draft'] = $in + ['status' => $status, 'id' => $id];
                flash($err, 'err');
                go(ADM . '?id=' . ($id ?: 'new'));
            }
            if (!$id) {
                $id = nox_booking_insert($db, ['status' => 'new'] + $in, 'admin:' . time() . '-' . bin2hex(random_bytes(3)));
            }
            $before = nox_booking($id);
            nox_booking_update($id, $in + ['status' => $status]);
            if ($before && $before['status'] !== $status) {
                nox_log('BOOKING #' . $id . ' -> ' . $status . ' by admin');
            }
            tg_sync_booking($id);
            flash('Збережено.');
            go(ADM . '?id=' . $id);

        // A new date from the list. The end moves with the start, a confirmed
        // night still may not land on a held day, and the listing and the
        // Telegram message follow on their own.
        case 'move':
            $b = nox_booking($id);
            $to = (string)($_POST['date'] ?? '');
            if (!$b || !nox_valid_date($to)) { flash('Вкажіть дату.', 'err'); go($back); }
            $end = '';
            if ($b['date_end'] !== '') {
                $days = (new DateTime($b['date']))->diff(new DateTime($b['date_end']))->days;
                $end = (new DateTime($to))->modify('+' . $days . ' days')->format('Y-m-d');
            }
            if ($b['status'] === 'confirmed' && ($c = nox_conflict($to, $end, $id))) {
                flash('Дата вже зайнята: #' . $c['id'] . ' ' . nox_booking_label($c) . '.', 'err');
                go($back);
            }
            nox_booking_update($id, ['date' => $to, 'date_end' => $end]);
            tg_sync_booking($id);
            nox_log('BOOKING #' . $id . ' moved ' . $b['date'] . ' -> ' . $to . ' by admin');
            $shown = $b['status'] === 'confirmed' && (int)$b['published'];
            flash('#' . $id . ' перенесено на ' . tg_date($to) . ($shown ? ' — в афіші теж.' : '.'));
            go($back);

        case 'delete':
            nox_booking_delete($id);
            nox_log('BOOKING #' . $id . ' deleted by admin');
            flash('Бронь #' . $id . ' видалено.');
            go(ADM);

        case 'hook_on':
        case 'hook_off':
            if (TG_TOKEN === '') { flash('Бот не налаштований: немає TG_TOKEN.', 'err'); go(ADM); }
            if ($action === 'hook_on') {
                $r = tg_call('setWebhook', [
                    'url'                  => NOX_SITE_URL . '/api/telegram',
                    'secret_token'         => tg_hook_secret(),
                    'allowed_updates'      => json_encode(['callback_query']),
                    'drop_pending_updates' => 'true',
                ]);
                if ($r) { nox_meta('tg_hook', '1'); flash('Кнопки в Telegram підключено. Вони зʼявляться під новими заявками.'); }
                else flash('Telegram не прийняв вебхук — подробиці в site.log.', 'err');
            } else {
                tg_call('deleteWebhook', []);
                nox_meta('tg_hook', '0');
                flash('Кнопки в Telegram вимкнено.');
            }
            go(ADM);
    }
    go(ADM);
}

/* ── CSV ─────────────────────────────────────────────────────────────── */
if (($_GET['export'] ?? '') === 'csv') {
    $cols = ['id', 'status', 'state', 'date', 'date_end', 'time_from', 'time_to', 'name', 'contact',
             'telegram', 'event', 'guests', 'artists', 'music', 'social', 'comment', 'published',
             'title', 'promoter', 'lineup', 'tickets', 'note', 'created_at'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nox-bookings-' . nox_today() . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");               // so Excel reads the Cyrillic
    fputcsv($out, $cols, ',', '"', '');
    foreach ($db->query('SELECT * FROM bookings ORDER BY date DESC, id DESC') as $b) {
        $b['state'] = nox_state($b);
        $b['created_at'] = date('Y-m-d H:i', (int)$b['created_at']);
        $row = [];
        // A cell that starts like a formula is kept as text; a bare Telegram
        // handle is not a formula and stays as it is.
        foreach ($cols as $c) {
            $v = (string)$b[$c];
            $formula = preg_match('/^[=+\-@\t\r]/', $v) && !preg_match('/^@\w{3,32}$/', $v);
            $row[] = $formula ? "'" . $v : $v;
        }
        fputcsv($out, $row, ',', '"', '');
    }
    exit;
}

/* ── views ───────────────────────────────────────────────────────────── */
nox_db_sync_files($db);
$today = nox_today();
$all = $db->query('SELECT * FROM bookings ORDER BY date, id')->fetchAll();
foreach ($all as &$b) $b['state'] = nox_state($b, $today);
unset($b);

$top = '<header class="top"><a class="brand" href="' . ADM . '">nøx <span>/ броні</span></a>' .
    '<div class="row"><a class="btn main" href="' . ADM . '?id=new">+ Нова бронь</a>' .
    '<a class="btn" href="' . ADM . '?export=csv">CSV</a>' .
    '<form class="inline" method="post" action="' . ADM . '">' . csrf_field() .
    '<input type="hidden" name="action" value="logout"><button class="btn" type="submit">Вийти</button></form></div></header>';
$f = flash();
if ($f) $top .= '<div class="flash ' . h($f[0]) . '">' . h($f[1]) . '</div>';

function hours(array $b): string {
    if ($b['time_from'] === '' && $b['time_to'] === '') return '';
    return ($b['time_from'] ?: '?') . '–' . ($b['time_to'] ?: '?');
}

/* ── contacts that open where they came from ─────────────────────────
   A Telegram handle opens the chat, a phone number the dialler — and Viber,
   WhatsApp and Telegram by that number — an address the mail app, a link its
   page. Only https, tel, mailto and viber come out of here, whatever was
   typed into the form. */
function phone_digits(string $v): string {
    if (preg_match('/[^\d\s()+\-.]/', $v)) return '';
    $d = preg_replace('/\D+/', '', $v);
    if (strlen($d) === 10 && $d[0] === '0') $d = '38' . $d;        // 063… -> 38063…
    return strlen($d) >= 10 && strlen($d) <= 15 ? $d : '';
}

function phone_label(string $d): string {
    if (strlen($d) === 12 && substr($d, 0, 3) === '380') {
        return '+' . substr($d, 0, 3) . ' ' . substr($d, 3, 2) . ' ' . substr($d, 5, 3) . ' ' .
            substr($d, 8, 2) . ' ' . substr($d, 10, 2);
    }
    return '+' . $d;
}

function tg_handle(string $v): string {
    $v = preg_replace('#^(https?://)?(www\.)?(t\.me|telegram\.me)/#i', '', trim($v));
    $v = preg_replace('#[/?].*$#', '', ltrim($v, '@'));
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/', $v) ? $v : '';
}

// [kind, label, href, what the copy button copies]; kind "sub" is a second
// way into the same contact and carries no copy button of its own.
function contact_links(array $b): array {
    $out = [];
    $seen = [];
    $tg = function (string $v) use (&$out, &$seen) {
        if ($v === '' || isset($seen['tg:' . strtolower($v)])) return;
        $seen['tg:' . strtolower($v)] = 1;
        $out[] = ['tg', '✈ @' . $v, 'https://t.me/' . $v, '@' . $v];
    };
    $phone = function (string $d) use (&$out, &$seen) {
        if (isset($seen['ph:' . $d])) return;
        $seen['ph:' . $d] = 1;
        $out[] = ['tel', '📞 ' . phone_label($d), 'tel:+' . $d, '+' . $d];
        $out[] = ['sub', 'Viber', 'viber://chat?number=%2B' . $d, ''];
        $out[] = ['sub', 'WhatsApp', 'https://wa.me/' . $d, ''];
        $out[] = ['sub', 'Telegram', 'https://t.me/+' . $d, ''];
    };
    foreach (['telegram', 'contact'] as $f) {
        $v = trim((string)($b[$f] ?? ''));
        if ($v === '') continue;
        if (filter_var($v, FILTER_VALIDATE_EMAIL)) { $out[] = ['mail', '✉ ' . $v, 'mailto:' . $v, $v]; continue; }
        if (($d = phone_digits($v)) !== '') { $phone($d); continue; }
        if (($h = tg_handle($v)) !== '' && ($f === 'telegram' || $v[0] === '@' || stripos($v, 't.me/') !== false)) { $tg($h); continue; }
        $out[] = ['txt', $v, '', $v];
    }
    foreach (preg_split('/[\s,]+/', trim((string)($b['social'] ?? ''))) as $t) {
        if ($t === '' || strpos($t, '.') === false) continue;
        $url = preg_match('#^https?://#i', $t) ? $t : 'https://' . $t;
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) continue;
        $label = preg_replace('#^(https?://)?(www\.)?#i', '', rtrim($t, '/'));
        if (mb_strlen($label) > 40) $label = mb_substr($label, 0, 38) . '…';
        $out[] = ['url', '🔗 ' . $label, $url, $url];
    }
    return $out;
}

function contact_chips(array $b): string {
    $html = '';
    foreach (contact_links($b) as list($kind, $label, $href, $copy)) {
        $web = strpos($href, 'https://') === 0;
        $html .= '<span class="chip' . ($kind === 'sub' ? ' sub' : '') . '">' .
            ($href !== '' ? '<a href="' . h($href) . '"' . ($web ? ' target="_blank" rel="noopener"' : '') . '>' . h($label) . '</a>'
                          : '<span>' . h($label) . '</span>') .
            ($copy !== '' ? '<button type="button" class="cp" data-copy="' . h($copy) . '" title="Скопіювати">⧉</button>' : '') .
            '</span>';
    }
    return $html;
}

// The name, then each contact as a link, for a line in the list.
function contact_line(array $b): string {
    $bits = trim((string)$b['name']) !== '' ? [h($b['name'])] : [];
    foreach (contact_links($b) as list($kind, $label, $href)) {
        if ($kind === 'sub' || $kind === 'url') continue;
        $text = preg_replace('/^\S+ /u', '', $label);             // no icon in a line of text
        $bits[] = $href !== '' ? '<a href="' . h($href) . '"' . (strpos($href, 'https://') === 0 ? ' target="_blank" rel="noopener"' : '') . '>' . h($text) . '</a>' : h($text);
    }
    return implode(' · ', $bits);
}

// A booking as text, to paste into a chat or a note.
function booking_summary(array $b, string $stateLabel): string {
    $lines = ['#' . $b['id'] . ' · ' . nox_booking_label($b)];
    $when = '📅 ' . tg_date($b['date']);
    if ($b['date_end'] !== '' && $b['date_end'] !== $b['date']) $when .= ' → ' . tg_date($b['date_end']);
    if (hours($b) !== '') $when .= ' · ' . hours($b);
    $lines[] = $when;
    if (trim($b['name']) !== '') $lines[] = '👤 ' . $b['name'];
    foreach (contact_links($b) as list($kind, $label, , $copy)) {
        if ($kind !== 'sub') $lines[] = $kind === 'txt' ? $copy : preg_replace('/^(\S+) .*/u', '$1 ', $label) . $copy;
    }
    $set = array_filter([$b['guests'] !== '' ? $b['guests'] . ' гостей' : '', $b['music'],
        $b['artists'] !== '' ? $b['artists'] . ' ' . tg_plural((int)$b['artists'], 'артист', 'артисти', 'артистів') : '']);
    if ($set) $lines[] = '👥 ' . implode(' · ', $set);
    if (trim($b['event']) !== '' && trim($b['title']) !== '') $lines[] = '🎧 ' . $b['event'];
    if (trim($b['comment']) !== '') $lines[] = '💬 ' . $b['comment'];
    $lines[] = 'Статус: ' . $stateLabel . ((int)$b['published'] && $b['status'] === 'confirmed' ? ' · в афіші' : '');
    return implode("\n", $lines);
}

function status_button(array $b, string $to, string $label, string $cls, string $back): string {
    return '<form class="inline" method="post" action="' . ADM . '">' . csrf_field() .
        '<input type="hidden" name="action" value="status"><input type="hidden" name="id" value="' . (int)$b['id'] . '">' .
        '<input type="hidden" name="status" value="' . h($to) . '"><input type="hidden" name="back" value="' . h($back) . '">' .
        '<button class="btn small ' . $cls . '" type="submit">' . h($label) . '</button></form>';
}

/* one booking */
if (isset($_GET['id'])) {
    $isNew = $_GET['id'] === 'new';
    $b = $isNew ? null : nox_booking((int)$_GET['id']);
    if (!$isNew && !$b) { flash('Бронь не знайдено.', 'err'); go(ADM); }
    $blank = array_fill_keys(array_merge(NOX_ENQUIRY_FIELDS, NOX_PUBLIC_FIELDS, ['note']), '');
    $b = $b ?? ($blank + ['id' => 0, 'status' => 'confirmed', 'published' => 0, 'source' => '', 'created_at' => time(),
                          'updated_at' => time(), 'tg_message_id' => 0]);
    $draft = $_SESSION['draft'] ?? null;
    unset($_SESSION['draft']);
    if ($draft && (int)$draft['id'] === (int)$b['id']) $b = array_merge($b, $draft);
    $state = $isNew ? '' : nox_state($b, $today);

    $field = function (string $name, string $label, string $type = 'text', string $cls = '', string $extra = '') use ($b) {
        $v = h($b[$name] ?? '');
        $input = $type === 'textarea'
            ? '<textarea name="' . $name . '"' . $extra . '>' . $v . '</textarea>'
            : '<input type="' . $type . '" name="' . $name . '" value="' . $v . '"' . $extra . '>';
        return '<label class="' . $cls . '">' . h($label) . $input . '</label>';
    };

    $statusSel = '<label>Статус<select name="status">';
    foreach ($STATUS_LABEL as $k => $l) {
        $statusSel .= '<option value="' . $k . '"' . ($b['status'] === $k ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    $statusSel .= '</select></label>';

    $clash = ($b['date'] ?? '') !== '' ? nox_conflict($b['date'], $b['date_end'], (int)$b['id']) : null;
    $others = 0;
    foreach ($all as $o) {
        if ((int)$o['id'] !== (int)$b['id'] && $o['date'] === $b['date'] && $o['state'] === 'new') $others++;
    }

    $head = '<div class="row" style="justify-content:space-between;margin-bottom:14px">' .
        '<a href="' . ADM . '">← Усі броні</a>' .
        ($isNew ? '' : '<span class="tag ' . $STATE[$state][1] . '">' . h($STATE[$state][0]) . '</span>') . '</div>' .
        '<h1 style="margin:0 0 6px;font-size:24px">' . ($isNew ? 'Нова бронь' : '#' . (int)$b['id'] . ' · ' . h(nox_booking_label($b))) . '</h1>' .
        ($isNew ? '' : '<p class="hint">Надійшла ' . h(date('d.m.Y H:i', (int)$b['created_at'])) .
            (strpos((string)$b['source'], 'rent/') === 0 ? ' з форми на сайті' : (strpos((string)$b['source'], 'seed:') === 0 ? ' з афіші, що була в коді' : ' вручну')) .
            ' · змінена ' . h(date('d.m.Y H:i', (int)$b['updated_at'])) . '</p>') .
        ($clash ? '<div class="warn">⚠ На цю дату вже підтверджено #' . (int)$clash['id'] . ' ' . h(nox_booking_label($clash)) . '</div>' : '') .
        ($others ? '<div class="warn">На цю дату є ще ' . $others . ' ' . tg_plural($others, 'нова заявка', 'нові заявки', 'нових заявок') . '</div>' : '') .
        ($isNew ? '' : '<div class="chips">' . contact_chips($b) .
            '<button type="button" class="btn small" data-copy="' . h(booking_summary($b, $STATE[$state][0])) . '">⧉ Скопіювати все</button></div>');
    $shown = !$isNew && $b['status'] === 'confirmed' && (int)$b['published'];

    $form = '<form class="edit" method="post" action="' . ADM . '" style="margin-top:16px">' . csrf_field() .
        '<input type="hidden" name="action" value="save"><input type="hidden" name="id" value="' . (int)$b['id'] . '">' .
        '<fieldset><legend>Коли</legend><div class="grid">' . $statusSel .
            $field('date', 'Дата', 'date', '', ' required') .
            $field('date_end', 'Закінчується (якщо після півночі чи кілька днів)', 'date') .
            $field('time_from', 'Початок', 'time') . $field('time_to', 'Кінець', 'time') .
            ($shown ? '<p class="hint" style="grid-column:1/-1;margin:0">Бронь в афіші: нова дата зʼявиться на сторінці «Афіші» одразу після збереження.</p>' : '') .
        '</div></fieldset>' .
        '<fieldset><legend>Організатор</legend><div class="grid">' .
            $field('name', 'Хто', 'text') . $field('contact', 'Телефон або пошта') .
            $field('telegram', 'Telegram') . $field('social', 'Соцмережі події') .
        '</div></fieldset>' .
        '<fieldset><legend>Вечір</legend><div class="grid">' .
            $field('event', 'Що за вечір', 'text', 'wide') .
            $field('guests', 'Гостей') . $field('artists', 'Артистів') . $field('music', 'Музика') .
            $field('comment', 'Що потрібно від нас', 'textarea', 'wide') .
        '</div></fieldset>' .
        '<fieldset><legend>Афіша на сайті</legend><div class="grid">' .
            '<label class="check wide"><input type="checkbox" name="published" value="1"' . ((int)$b['published'] ? ' checked' : '') . '> Показати в афіші</label>' .
            '<p class="hint wide" style="grid-column:1/-1;margin:0">Показується тільки підтверджена бронь. Без назви в афіші стоятиме «Що за вечір».</p>' .
            $field('title', 'Назва', 'text', 'wide') . $field('promoter', 'Промоутер') .
            $field('tickets', 'Квитки (посилання)', 'url') .
            $field('lineup', 'Лайнап', 'text', 'wide') .
            $field('poster', 'Афіша, 1080 px (шлях або посилання)') . $field('poster_small', 'Афіша, 720 px') .
        '</div></fieldset>' .
        '<fieldset><legend>Нотатка для своїх</legend>' . $field('note', 'Не показується нікому, крім адмінки', 'textarea', 'wide') . '</fieldset>' .
        '<div class="row"><button class="btn main" type="submit">Зберегти</button></div></form>';

    $del = $isNew ? '' : '<form method="post" action="' . ADM . '" style="margin-top:28px" onsubmit="return confirm(\'Видалити бронь #' . (int)$b['id'] . ' назавжди?\')">' .
        csrf_field() . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . (int)$b['id'] . '">' .
        '<button class="btn bad small" type="submit">Видалити бронь</button> <span class="hint">Краще відхилити: видалену не повернути.</span></form>';

    page($isNew ? 'Нова бронь' : '#' . $b['id'], $top . $head . $form . $del);
}

/* the list */
$counts = [];
foreach ($TABS as $k => $t) {
    $counts[$k] = count(array_filter($all, fn($b) => in_array($b['state'], $t[1], true)));
}
$tab = $_GET['v'] ?? ($counts['new'] ? 'new' : 'booked');
if (!isset($TABS[$tab])) $tab = 'new';

$rows = array_values(array_filter($all, fn($b) => in_array($b['state'], $TABS[$tab][1], true)));
// Ahead reads forward, behind reads back.
if (in_array($tab, ['past', 'archive', 'all'], true)) $rows = array_reverse($rows);

$tabs = '<nav class="tabs">';
foreach ($TABS as $k => $t) {
    $tabs .= '<a href="' . ADM . '?v=' . $k . '"' . ($k === $tab ? ' class="on"' : '') . '>' . h($t[0]) .
        '<b>' . $counts[$k] . '</b></a>';
}
$tabs .= '</nav>';

$back = ADM . '?v=' . $tab;
$cards = '';
foreach ($rows as $b) {
    $st = $STATE[$b['state']];
    $when = tg_date($b['date']);
    if ($b['date_end'] !== '' && $b['date_end'] !== $b['date']) $when .= ' → ' . date('d.m', strtotime($b['date_end']));
    list($dmy, $wd) = array_pad(explode(', ', $when, 2), 2, '');
    $who = contact_line($b);
    $what = array_filter([$b['guests'] !== '' ? $b['guests'] . ' гостей' : '', $b['music'],
        $b['artists'] !== '' ? $b['artists'] . ' арт.' : '']);

    $warn = '';
    if ($b['state'] === 'new') {
        $c = nox_conflict($b['date'], $b['date_end'], (int)$b['id']);
        if ($c) $warn .= '<div class="warn">⚠ Дата зайнята: #' . (int)$c['id'] . ' ' . h(nox_booking_label($c)) . '</div>';
        $same = count(array_filter($all, fn($o) => $o['id'] !== $b['id'] && $o['date'] === $b['date'] && $o['state'] === 'new'));
        if ($same) $warn .= '<div class="warn">Ще ' . $same . ' ' . tg_plural($same, 'заявка', 'заявки', 'заявок') . ' на цю дату</div>';
    }

    $acts = '';
    // Moving a date is for nights still ahead; a past one is history.
    $move = in_array($b['state'], ['new', 'booked', 'expired'], true)
        ? '<details class="mv"><summary class="btn small">Перенести</summary>' .
          '<form method="post" action="' . ADM . '">' . csrf_field() .
          '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . (int)$b['id'] . '">' .
          '<input type="hidden" name="back" value="' . h($back) . '">' .
          '<input type="date" name="date" value="' . h($b['date']) . '" required>' .
          '<button class="btn small main" type="submit">OK</button></form></details>'
        : '';
    $copy = '<button type="button" class="btn small" data-copy="' . h(booking_summary($b, $st[0])) . '">⧉ Копіювати</button>';
    switch ($b['state']) {
        case 'new':
            $acts = status_button($b, 'confirmed', 'Підтвердити', 'good', $back) . ' ' .
                    status_button($b, 'declined', 'Відхилити', 'bad', $back);
            break;
        case 'booked':
            $acts = status_button($b, 'cancelled', 'Скасувати', 'bad', $back);
            break;
    }

    $cards .= '<div class="card">' .
        '<div class="d"><b>' . h($dmy) . '</b><span>' . h($wd) . '</span>' .
            (hours($b) !== '' ? '<span>' . h(hours($b)) . '</span>' : '') . '</div>' .
        '<div><div class="t"><a href="' . ADM . '?id=' . (int)$b['id'] . '">' . h(nox_booking_label($b)) . '</a></div>' .
            ($who !== '' ? '<div class="m">' . $who . '</div>' : '') .
            ($what ? '<div class="m">' . h(implode(' · ', $what)) . '</div>' : '') . $warn . '</div>' .
        '<div class="side"><div class="row">' .
            ((int)$b['published'] && $b['status'] === 'confirmed' ? '<span class="tag pub">в афіші</span>' : '') .
            '<span class="tag ' . $st[1] . '">' . h($st[0]) . '</span></div>' .
            ($acts ? '<div class="row">' . $acts . '</div>' : '') .
            '<div class="row">' . $move . $copy . '</div></div>' .
        '</div>';
}
if ($cards === '') $cards = '<div class="empty">Тут поки порожньо</div>';

// Telegram, at the foot: whether the buttons under enquiries work.
$hook = nox_meta('tg_hook') === '1';
$tg = '<div class="foot">';
if (TG_TOKEN === '') {
    $tg .= 'Telegram-бот не налаштований (<code>TG_TOKEN</code> у nox_config.php).';
} else {
    $tg .= '<form class="inline" method="post" action="' . ADM . '">' . csrf_field() .
        '<input type="hidden" name="action" value="' . ($hook ? 'hook_off' : 'hook_on') . '">' .
        ($hook
            ? 'Кнопки «Підтвердити / Відхилити» в Telegram підключені. <button class="btn small" type="submit">Вимкнути</button>'
            : 'Кнопки «Підтвердити / Відхилити» під заявками в Telegram не підключені. <button class="btn small main" type="submit">Підключити</button>') .
        '</form>';
}
$tg .= '</div>';

page('Броні', $top . $tabs . '<div class="list">' . $cards . '</div>' . $tg);
