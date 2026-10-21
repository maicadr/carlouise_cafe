<?php
require __DIR__ . '/../includes/bootstrap.php';
if (is_post() && current_user()) {
    csrf_verify();
    audit('logout', 'user', user_id(), 'Logged out');
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
    flash('success', 'You have been logged out.');
}
redirect('auth/login.php');
