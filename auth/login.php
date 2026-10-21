<?php
require __DIR__ . '/../includes/bootstrap.php';
if (current_user()) redirect('pages/dashboard.php');

$error = '';
$userCount = (int)db_val('SELECT COUNT(*) FROM users');

if (is_post()) {
    csrf_verify();
    $now = time();
    if (($_SESSION['lock_until'] ?? 0) > $now) {
        $error = 'Too many failed attempts. Please wait a minute and try again.';
    } else {
        $username = post('username');
        $password = $_POST['password'] ?? '';
        $u = db_one('SELECT * FROM users WHERE username = ?', [$username]);
        // constant-ish time check even when user is unknown
        $hash = $u['password_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuG4hzVN2cQk8yKp2WQw6rO0zV3a6fX4e';
        $ok = is_string($password) && password_verify($password, $hash) && $u && (int)$u['is_active'] === 1;
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['user'] = ['id' => (int)$u['id'], 'name' => $u['full_name'], 'username' => $u['username'], 'role' => $u['role']];
            $_SESSION['last_activity'] = time();
            unset($_SESSION['fails'], $_SESSION['lock_until']);
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            }
            db_exec('UPDATE users SET last_login = NOW() WHERE id = ?', [$u['id']]);
            audit('login', 'user', (int)$u['id'], 'Logged in');
            redirect($u['role'] === 'kitchen' ? 'pages/kitchen.php' : ($u['role'] === 'cashier' ? 'pages/pos.php' : 'pages/dashboard.php'));
        }
        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= 5) { $_SESSION['lock_until'] = $now + 60; $_SESSION['fails'] = 0; }
        audit('login_failed', 'user', $u ? (int)$u['id'] : null, 'Failed login for "' . mb_substr($username, 0, 50) . '"');
        $error = 'Incorrect username or password, or the account is inactive.';
    }
}
$cafe = setting('cafe_name', 'CarLouise Cafe');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in · <?= e($cafe) ?></title>
<link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/vendor/icons/bootstrap-icons.min.css') ?>">
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>"></head>
<body>
<div class="login-wrap p-3">
  <div class="login-card">
    <div class="text-center text-white mb-4"><i class="bi bi-cup-hot-fill fs-1" style="color:var(--crema)"></i>
      <h1 class="h3 mt-1 mb-0"><?= e($cafe) ?></h1><div class="small opacity-75">Sales, orders and inventory</div></div>
    <div class="card"><div class="card-body p-4">
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
      <?php foreach ($_SESSION['flash'] ?? [] as [$t, $m]): ?><div class="alert alert-<?= e($t) ?> py-2"><?= e($m) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
      <form method="post" autocomplete="off"><?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="username">Username</label>
          <input class="form-control form-control-lg" id="username" name="username" required autofocus maxlength="50" value="<?= e(post('username')) ?>"></div>
        <div class="mb-3"><label class="form-label" for="password">Password</label>
          <input class="form-control form-control-lg" id="password" name="password" type="password" required></div>
        <button class="btn btn-primary btn-lg w-100" type="submit">Log in</button>
      </form>
      <?php if ($userCount === 0): ?><div class="alert alert-warning mt-3 mb-0 small">No accounts yet. <a href="<?= url('setup.php') ?>">Run first-time setup</a> to create the owner account.</div><?php endif; ?>
    </div></div>
  </div>
</div>
</body></html>
