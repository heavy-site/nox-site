<?php
// Copy to /home/noxplcec/nox_config.php (OUTSIDE public_html), chmod 600.
// Never commit the real file.

// Writable directory outside the web root for the enquiries, the log and rate
// limiting. Created on the first submission.
define('NOX_DATA_DIR', '/home/noxplcec/nox_data');

// Telegram — the only place an enquiry is sent. Leave the token or the chat
// empty and the form still works: the enquiry lands in NOX_DATA_DIR/rent and
// the log records that nothing went out.
//
// TG_CHAT — mind the sign, it is the whole difference between the two kinds:
//   a person   6535254719        POSITIVE, no minus. The bot cannot write
//                                first, so send it /start once.
//   a group   -1004298991246     NEGATIVE. The bot has to be a member.
// A minus in front of a personal id asks Telegram for a group that does not
// exist, and the answer is "chat not found".
//
// TG_TOPIC — forum topic id inside a group; 0 posts to General.
define('TG_TOKEN', 'REPLACE_WITH_BOT_TOKEN');
define('TG_CHAT',  '6535254719');
define('TG_TOPIC', 0);
