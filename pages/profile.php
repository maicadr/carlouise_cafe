<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('profile');
if (is_post()) {
    csrf_verify();
    try {
        $u = db_one('SELECT password_hash FROM users WHERE id = ?', [user_id()]);
        $cur = $_POST['current'] ?? ''; $new = $_POST['new'] ?? ''; $new2 = $_POST['new2'] ?? '';
        if (!password_verify($cur, $u['password_hash'])) throw new UserError('Your current password is incorrect.');
        if (strlen($new) < 8) throw new UserError('The new password must be at least 8 characters.');
        if ($new !== $new2) throw new UserError('The new passwords do not match.');
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), user_id()]);
        session_regenerate_id(true); audit('password_changed', 'user', user_id(), 'Own password');
        flash('success', 'Password changed.');
    } catch (UserError $e) { flash('danger', $e->getMessage()); }
    redirect_self();
}
page_header('My Account', 'profile');
?>
<div class="card" style="max-width:480px"><div class="card-body">
<p class="mb-1"><strong><?= e(current_user()['name']) ?></strong> · <?= e(ROLE_LABELS[user_role()]) ?></p><p class="text-muted small">Username: <code><?= e(current_user()['username']) ?></code></p>
<form method="post"><?= csrf_field() ?><h2 class="h6">Change password</h2>
<div class="mb-2"><input class="form-control" type="password" name="current" placeholder="Current password" required autocomplete="current-password"></div>
<div class="mb-2"><input class="form-control" type="password" name="new" placeholder="New password (8+ characters)" required minlength="8" autocomplete="new-password"></div>
<div class="mb-3"><input class="form-control" type="password" name="new2" placeholder="Repeat new password" required autocomplete="new-password"></div>
<button class="btn btn-primary">Change password</button></form></div></div>
<?php page_footer();
