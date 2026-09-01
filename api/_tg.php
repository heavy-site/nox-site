<?php
// Telegram notification for the site's forms. The e-mail is the record; this
// is the nudge — a rental enquiry should reach a phone before it reaches an
// inbox nobody has open.
//
// Configured in nox_config.php, outside the web root:
//   TG_TOKEN  bot token
//   TG_CHAT   chat id (the nøx group is negative, a private chat is positive)
//   TG_TOPIC  optional forum topic id; omit or 0 for the group's General
require_once __DIR__ . '/_config.php';

function tg_esc(string $v): string {
    // Telegram's HTML parse mode only forbids these three; anything else,
    // including a name in angle brackets, would otherwise break the message.
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $v);
}

// [label => value]; empty values are dropped.
function tg_rows(string $title, array $fields): string {
    $out = '<b>' . tg_esc($title) . '</b>';
    foreach ($fields as $label => $value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        $out .= "\n" . tg_esc($label) . ': <b>' . tg_esc($value) . '</b>';
    }
    return $out;
}

// Returns true only on Telegram's own ok:true. Never throws and never blocks
// the reply to the visitor: a form that worked must not look broken because
// a notification did not go out.
function tg_send(string $text): bool {
    if (TG_TOKEN === '' || TG_CHAT === '') { nox_log('TG skipped: not configured'); return false; }

    $params = [
        'chat_id'                  => TG_CHAT,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => 'true',
    ];
    if (TG_TOPIC) $params['message_thread_id'] = TG_TOPIC;

    $url = 'https://api.telegram.org/bot' . TG_TOKEN . '/sendMessage';
    $body = http_build_query($params);
    $res = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
    } else {
        // Shared hosts sometimes ship without curl; the stream wrapper is
        // always there, and @ keeps a refused connection out of the response.
        $res = @file_get_contents($url, false, stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content'       => $body,
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]));
    }

    $ok = is_string($res) && (json_decode($res, true)['ok'] ?? false) === true;
    if (!$ok) nox_log('TG failed: ' . substr((string)$res, 0, 300));
    return $ok;
}
