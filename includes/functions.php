<?php
/** General helpers: escaping, formatting, flash, audit, settings, pagination. */
class UserError extends Exception {}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function peso($n): string { return '₱' . number_format((float)$n, 2); }
function qty($n): string {
    $s = number_format((float)$n, 3, '.', ',');
    return rtrim(rtrim($s, '0'), '.');
}
function url(string $path = ''): string { return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/'); }
function redirect(string $path): never {
    header('Location: ' . (preg_match('#^(https?://|/)#', $path) ? $path : url($path)));
    exit;
}
/** Redirect back to the current page (path + query only) - used after POST. */
function redirect_self(): never {
    $uri = $_SERVER['REQUEST_URI'] ?? url();
    header('Location: ' . $uri);
    exit;
}
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'; }
function post(string $k, string $d = ''): string { return isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : $d; }
function get(string $k, string $d = ''): string { return isset($_GET[$k]) && is_string($_GET[$k]) ? trim($_GET[$k]) : $d; }
function post_int(string $k): int { return (int)post($k, '0'); }
function is_ajax(): bool { return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'; }

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }

function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function audit(string $action, ?string $entity = null, ?int $entityId = null, string $details = ''): void {
    try {
        db_exec('INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)',
            [user_id() ?: null, $action, $entity, $entityId, mb_substr($details, 0, 500), client_ip()]);
    } catch (Throwable $e) { error_log('audit failed: ' . $e->getMessage()); }
}

/** Cached key/value settings. */
function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) $cache[$r['setting_key']] = $r['setting_value'];
    }
    return $cache[$key] ?? $default;
}
function save_setting(string $key, string $value): void {
    db_exec('INSERT INTO settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)', [$key, $value]);
}

/** Atomic, gap-free daily counter. Must be called inside a transaction. */
function next_counter(string $name): int {
    db_exec('INSERT INTO counters (name, n) VALUES (?, 1) ON DUPLICATE KEY UPDATE n = n + 1', [$name]);
    return (int)db_val('SELECT n FROM counters WHERE name = ?', [$name]);
}

/** Validate a Y-m-d date, otherwise return the fallback. */
function valid_date(string $d, string $fallback): string {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : $fallback;
}
function day_start(string $d): string { return $d . ' 00:00:00'; }
function day_end_exclusive(string $d): string { return date('Y-m-d', strtotime($d . ' +1 day')) . ' 00:00:00'; }

/** Parse a positive decimal quantity. */
function parse_qty(string $s, bool $allowZero = false): float {
    $s = str_replace(',', '', trim($s));
    if ($s === '' || !is_numeric($s)) throw new UserError('Enter a valid quantity.');
    $v = round((float)$s, 3);
    if ($v < 0 || ($v == 0 && !$allowZero)) throw new UserError('Quantity must be greater than zero.');
    if ($v > 9999999) throw new UserError('Quantity is too large.');
    return $v;
}
function parse_money(string $s): float {
    $s = str_replace([',', '₱'], '', trim($s));
    if ($s === '' || !is_numeric($s) || (float)$s < 0) throw new UserError('Enter a valid amount.');
    return round((float)$s, 2);
}

function status_badge(string $status): string {
    $map = ['pending' => 'warning text-dark', 'preparing' => 'info text-dark', 'ready' => 'success', 'completed' => 'secondary',
        'cancelled' => 'danger', 'unpaid' => 'warning text-dark', 'paid' => 'success', 'refunded' => 'danger',
        'requested' => 'warning text-dark', 'received' => 'success', 'ok' => 'success', 'low' => 'warning text-dark', 'out' => 'danger'];
    $label = ['ok' => 'OK', 'low' => 'Low stock', 'out' => 'Out of stock'][$status] ?? ucfirst($status);
    return '<span class="badge bg-' . ($map[$status] ?? 'secondary') . '">' . e($label) . '</span>';
}
function stock_status(float $qty, float $min): string {
    if ($min > 0 && $qty <= 0) return 'out';
    if ($min > 0 && $qty <= $min) return 'low';
    if ($qty < 0) return 'out';
    return 'ok';
}
function fmt_dt(?string $dt, string $fmt = 'M j, Y g:i A'): string { return $dt ? date($fmt, strtotime($dt)) : '—'; }

/** Pagination: returns [offset, perPage, page]. */
function paginate_params(int $perPage = 20): array {
    $page = max(1, (int)get('page', '1'));
    return [($page - 1) * $perPage, $perPage, $page];
}
function pagination_html(int $total, int $page, int $perPage = 20): string {
    $pages = (int)ceil($total / $perPage);
    if ($pages <= 1) return '';
    $q = $_GET; unset($q['partial']);
    $link = function (int $p, string $label, bool $active = false, bool $disabled = false) use ($q) {
        $q['page'] = $p;
        $href = '?' . http_build_query($q);
        return '<li class="page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '') . '"><a class="page-link" href="' . e($href) . '">' . $label . '</a></li>';
    };
    $out = '<nav aria-label="Pages"><ul class="pagination pagination-sm justify-content-center mb-0">';
    $out .= $link(max(1, $page - 1), '&laquo;', false, $page <= 1);
    $start = max(1, $page - 2); $end = min($pages, $page + 2);
    if ($start > 1) $out .= $link(1, '1') . ($start > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
    for ($i = $start; $i <= $end; $i++) $out .= $link($i, (string)$i, $i === $page);
    if ($end < $pages) $out .= ($end < $pages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . $link($pages, (string)$pages);
    $out .= $link(min($pages, $page + 1), '&raquo;', false, $page >= $pages);
    return $out . '</ul></nav><div class="text-center small text-muted mt-1">' . number_format($total) . ' record' . ($total === 1 ? '' : 's') . '</div>';
}

function empty_state(string $icon, string $title, string $hint = ''): string {
    return '<div class="empty-state text-center py-5 text-muted"><i class="bi bi-' . e($icon) . ' fs-1"></i><div class="fw-semibold mt-2">' . e($title) . '</div>' . ($hint ? '<div class="small">' . e($hint) . '</div>' : '') . '</div>';
}

function locations(): array {
    static $l = null;
    if ($l === null) $l = db_all('SELECT id, code, name FROM inventory_locations ORDER BY id');
    return $l;
}
/** Locations the current role may see in inventory. */
function visible_locations(): array {
    $role = user_role();
    return array_values(array_filter(locations(), fn($l) =>
        in_array($role, ['admin', 'inventory'], true) || $l['code'] === $role));
}

/** Verify admin credentials for an authorization override (e.g. cashier cancelling an order). */
function verify_admin_authorization(string $username, string $password): int {
    $u = db_one("SELECT id, password_hash FROM users WHERE username = ? AND role = 'admin' AND is_active = 1", [$username]);
    $hash = $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuG4hzVN2cQk8yKp2WQw6rO0zV3a6fX4e';
    if (!$u || !password_verify($password, $hash)) throw new UserError('Authorization failed: invalid administrator credentials.');
    return (int)$u['id'];
}
