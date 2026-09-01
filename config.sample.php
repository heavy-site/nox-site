<?php
// Copy to /home/noxplcec/nox_config.php (OUTSIDE public_html), chmod 600.
// Never commit the real file.

// Writable directory outside the web root for the enquiries, the log and rate
// limiting. Created on the first submission.
define('NOX_DATA_DIR', '/home/noxplcec/nox_data');

// Telegram — the only place an enquiry is sent. Leave the token or the chat
// empty and the form still works: the enquiry lands in NOX_DATA_DIR/rent and
// the log records that nothing went out.
//   TG_CHAT : the nøx group is -1004298991246; a private chat is a positive id
//   TG_TOPIC: forum topic id inside that group; 0 posts to General
define('TG_TOKEN', 'REPLACE_WITH_BOT_TOKEN');
define('TG_CHAT',  '-1004298991246');
define('TG_TOPIC', 0);
