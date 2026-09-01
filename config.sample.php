<?php
// Copy to /home/noxplcec/nox_config.php (OUTSIDE public_html), chmod 600.
// Never commit the real file.

define('RESEND_API_KEY', 'REPLACE_WITH_YOUR_RESEND_API_KEY');

// Verified sender on the Resend account.
define('MAIL_FROM', 'nox <noreply@he4vy.com>');

// Where form submissions land.
define('MAIL_TO', 'e.pyvovar@gmail.com');

// Writable directory outside the web root for logs and rate limiting.
define('NOX_DATA_DIR', '/home/noxplcec/nox_data');

// Telegram notification for form submissions. Leave the token or the chat
// empty and the form still works — it simply sends nothing.
//   TG_CHAT : the nøx group is -1004298991246; a private chat is a positive id
//   TG_TOPIC: forum topic id inside that group; 0 posts to General
define('TG_TOKEN', 'REPLACE_WITH_BOT_TOKEN');
define('TG_CHAT',  '-1004298991246');
define('TG_TOPIC', 0);
