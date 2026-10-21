<?php
/**
 * Copy this file to config.php and adjust the values.
 * Never commit config.php with real credentials.
 */
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');      // XAMPP default
define('DB_PASS', '');          // XAMPP default is an empty password - set one for production
define('DB_NAME', 'carlouise_cafe');

define('BASE_URL', '/carlouise_cafe');   // URL path of the project folder under htdocs
define('TIMEZONE', 'Asia/Manila');
define('DB_TIMEZONE_OFFSET', '+08:00');  // must match TIMEZONE (Philippines has no DST)
define('APP_DEBUG', false);              // true shows technical errors; keep false in daily use
define('SESSION_LIFETIME', 28800);       // seconds of inactivity before auto-logout (8 hours)
