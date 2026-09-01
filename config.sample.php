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
