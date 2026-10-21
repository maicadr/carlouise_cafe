<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
date_default_timezone_set(TIMEZONE);
if (APP_DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }
else { ini_set('display_errors', '0'); error_reporting(E_ALL); ini_set('log_errors', '1'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/orders.php';
