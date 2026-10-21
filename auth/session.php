<?php
/** Secure session handling, CSRF and role checks. */
if (session_status() === PHP_SESSION_NONE) {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('CLCAFESESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
// idle timeout
if (!empty($_SESSION['user'])) {
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'][] = ['warning', 'You were logged out after a period of inactivity.'];
    } else {
        $_SESSION['last_activity'] = time();
    }
}

const ROLE_LABELS = [
    'admin' => 'Owner / Administrator', 'cashier' => 'Cashier',
    'kitchen' => 'Kitchen Staff', 'inventory' => 'Inventory Manager',
];

/** Which roles may open each page / perform each area of actions. */
function permission_map(): array {
    $all = ['admin', 'cashier', 'kitchen', 'inventory'];
    return [
        'dashboard'   => $all,
        'pos'         => ['cashier'],
        'take_payment' => ['cashier'],
        'orders'      => ['admin', 'cashier'],
        'kitchen'     => ['kitchen'],
        'tickets'     => ['admin', 'cashier', 'kitchen'],
        'products'    => ['admin', 'cashier'],
        'products_manage' => ['admin'],
        'sales'       => ['admin', 'cashier'],
        'payments'    => ['admin', 'cashier'],
        'refunds'     => ['admin'],
        'inventory'   => $all,
        'inventory_manage' => ['admin', 'inventory'],
        'transfers'   => ['admin', 'inventory'],
        'replenishment' => ['admin', 'inventory'],
        'reports'     => ['admin', 'cashier', 'inventory'],
        'users'       => ['admin'],
        'settings'    => ['admin'],
        'profile'     => $all,
    ];
}

function current_user(): ?array { return $_SESSION['user'] ?? null; }
function user_id(): int { return (int)($_SESSION['user']['id'] ?? 0); }
function user_role(): string { return $_SESSION['user']['role'] ?? ''; }
function can(string $perm): bool {
    $map = permission_map();
    return isset($map[$perm]) && in_array(user_role(), $map[$perm], true);
}

function require_perm(string $perm, bool $json = false): void {
    if (!current_user()) {
        if ($json) { json_out(['ok' => false, 'error' => 'Session expired. Please log in again.'], 401); }
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
    // re-check account is still active
    static $checked = false;
    if (!$checked) {
        $checked = true;
        $u = db_one('SELECT is_active, role FROM users WHERE id = ?', [user_id()]);
        if (!$u || !(int)$u['is_active']) {
            $_SESSION = []; session_regenerate_id(true);
            header('Location: ' . BASE_URL . '/auth/login.php'); exit;
        }
        $_SESSION['user']['role'] = $u['role'];
    }
    if (!can($perm)) {
        if ($json) json_out(['ok' => false, 'error' => 'You do not have permission to do that.'], 403);
        http_response_code(403);
        include __DIR__ . '/../includes/forbidden.php';
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . csrf_token() . '">'; }
function csrf_verify(bool $json = false): void {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        if ($json) json_out(['ok' => false, 'error' => 'Security token expired. Reload the page and try again.'], 419);
        http_response_code(419);
        exit('Security token invalid or expired. Go back, reload the page and try again.');
    }
}
function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
