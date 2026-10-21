<?php
/** Shared page chrome: sidebar, top bar, flash messages. */
function menu_items(): array {
    return [
        ['dashboard', 'Dashboard', 'speedometer2', 'pages/dashboard.php'],
        ['pos', 'New Order (POS)', 'cart-plus', 'pages/pos.php'],
        ['orders', 'Orders', 'receipt', 'pages/orders.php'],
        ['kitchen', 'Kitchen Display', 'fire', 'pages/kitchen.php'],
        ['products', 'Products', 'cup-hot', 'pages/products.php'],
        ['sales', 'Sales', 'graph-up-arrow', 'pages/sales.php'],
        ['payments', 'Payments', 'cash-coin', 'pages/payments.php'],
        ['inventory', 'Inventory', 'boxes', 'pages/inventory.php'],
        ['transfers', 'Stock Transfers', 'arrow-left-right', 'pages/transfers.php'],
        ['replenishment', 'Replenishment', 'truck', 'pages/replenishment.php'],
        ['reports', 'Reports', 'bar-chart-line', 'pages/reports.php'],
        ['users', 'Users', 'people', 'pages/users.php'],
        ['settings', 'Settings', 'gear', 'pages/settings.php'],
    ];
}

function page_header(string $title, string $active = '', bool $refreshMeta = false): void {
    $cafe = setting('cafe_name', 'CarLouise Cafe');
    $u = current_user();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="app-base" content="<?= e(BASE_URL) ?>">
<title><?= e($title) ?> · <?= e($cafe) ?></title>
<link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/icons/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="sidebar">
    <div class="sidebar-brand">
      <i class="bi bi-cup-hot-fill"></i>
      <div><div class="brand-name"><?= e($cafe) ?></div><div class="brand-sub">Management System</div></div>
      <button type="button" class="btn-close btn-close-white d-lg-none ms-auto" data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Close"></button>
    </div>
    <nav class="sidebar-nav">
      <?php foreach (menu_items() as [$key, $label, $icon, $file]):
        if (!can($key)) continue; ?>
        <a href="<?= url($file) ?>" class="<?= $active === $key ? 'active' : '' ?>"><i class="bi bi-<?= $icon ?>"></i><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-user">
      <div class="small text-white-50"><?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?></div>
      <a class="user-name" href="<?= url('pages/profile.php') ?>"><i class="bi bi-person-circle"></i> <?= e($u['name']) ?></a>
      <form method="post" action="<?= url('auth/logout.php') ?>" class="mt-2"><?= csrf_field() ?>
        <button class="btn btn-sm btn-outline-light w-100" type="submit"><i class="bi bi-box-arrow-right"></i> Log out</button></form>
    </div>
  </aside>
  <main class="main-area">
    <header class="topbar no-print">
      <button class="btn btn-outline-secondary d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar" aria-label="Menu"><i class="bi bi-list"></i></button>
      <h1 class="page-title"><?= e($title) ?></h1>
      <div class="ms-auto small text-muted d-none d-md-block"><i class="bi bi-clock"></i> <span id="clock"><?= date('D, M j · g:i A') ?></span></div>
    </header>
    <div class="content">
<?php
    foreach ($_SESSION['flash'] ?? [] as [$type, $msg]) {
        echo '<div class="alert alert-' . e($type) . ' alert-dismissible fade show no-print" role="alert">' . e($msg)
           . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    unset($_SESSION['flash']);
}

function page_footer(string $extraScripts = '', array $libs = []): void { ?>
    </div>
  </main>
</div>
<div class="loading-overlay" id="loading"><div class="spinner-border text-light" role="status"></div></div>
<script src="<?= url('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<?php foreach ($libs as $l): ?><script src="<?= url($l) ?>"></script><?php endforeach; ?>
<script src="<?= url('assets/js/app.js') ?>"></script>
<?= $extraScripts ?>
</body>
</html>
<?php }

/** Reusable confirm-able POST button form. */
function action_form(string $action, array $fields, string $label, string $class = 'btn btn-sm btn-outline-secondary', string $confirm = '', string $icon = ''): string {
    $h = '<form method="post" class="d-inline"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . csrf_field()
       . '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    return $h . '<button type="submit" class="' . e($class) . '">' . ($icon ? '<i class="bi bi-' . e($icon) . '"></i> ' : '') . e($label) . '</button></form>';
}
