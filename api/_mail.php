<?php
// Resend sender shared by the site's forms.
require_once __DIR__ . '/_config.php';

function mail_esc($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

// Labelled rows from [label => value]; empty values are dropped.
function mail_rows(array $fields): string {
    $rows = '';
    foreach ($fields as $label => $value) {
        if ($value === '' || $value === null) continue;
        $rows .= '<tr>'
            . '<td style="padding:7px 18px 7px 0;color:#7C919A;font:600 13px Arial,sans-serif;white-space:nowrap;vertical-align:top">'
            . mail_esc($label) . '</td>'
            . '<td style="padding:7px 0;color:#111;font:14px Arial,sans-serif;vertical-align:top">'
            . mail_esc($value) . '</td></tr>';
    }
    return '<table style="border-collapse:collapse;width:100%;max-width:560px">' . $rows . '</table>';
}

function mail_shell(string $title, string $rowsHtml): string {
    return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:0 auto;color:#111">'
        . '<h1 style="font-size:24px;letter-spacing:.02em;margin:0 0 4px">nøx</h1>'
        . '<p style="color:#555;margin:0 0 18px;font-size:15px">' . mail_esc($title) . '</p>'
        . '<hr style="border:none;border-top:1px solid #eee;margin:0 0 18px"/>'
        . $rowsHtml . '</div>';
}

// Returns [ok(bool), httpCode(int), body(string)].
function resend_send(string $subject, string $html, string $replyTo = '', ?string $to = null): array {
    $to = $to ?: MAIL_TO;

    if (RESEND_API_KEY === '') {
        nox_log('MAIL ABORT: RESEND_API_KEY not configured (subject="' . $subject . '")');
        return [false, 0, 'RESEND_API_KEY not configured'];
    }

    $payload = [
        'from'    => MAIL_FROM !== '' ? MAIL_FROM : 'nox <onboarding@resend.dev>',
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['reply_to'] = $replyTo;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 25,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    nox_log('MAIL to=' . $to . ' subj="' . $subject . '" HTTP ' . $code
        . ($err ? ' curlerr=' . $err : '') . ' body=' . substr((string)$resp, 0, 300));

    return [$code >= 200 && $code < 300, (int)$code, (string)$resp];
}
