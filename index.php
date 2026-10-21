<?php
require __DIR__ . '/includes/bootstrap.php';
redirect(current_user() ? 'pages/dashboard.php' : 'auth/login.php');
