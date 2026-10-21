<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('users');

function check_password(string $pw): void { if (strlen($pw) < 8) throw new UserError('Passwords must be at least 8 characters.'); }

if (is_post()) {
    csrf_verify();
    try {
        $a = post('action'); $id = post_int('id');
        if ($a === 'save') {
            $name = post('full_name'); $uname = post('username'); $role = post('role'); $pw = $_POST['password'] ?? '';
            if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $uname)) throw new UserError('Enter a full name and a username (3-50 letters, numbers, . _ -).');
            if (!isset(ROLE_LABELS[$role])) throw new UserError('Choose a role.');
            if (db_val('SELECT id FROM users WHERE username = ? AND id <> ?', [$uname, $id])) throw new UserError('That username is already taken.');
            if ($id) {
                if ($id === user_id() && $role !== 'admin') throw new UserError('You cannot remove your own administrator role.');
                db_exec('UPDATE users SET full_name=?, username=?, role=? WHERE id=?', [$name, $uname, $role, $id]);
                if ($pw !== '') { check_password($pw); db_exec('UPDATE users SET password_hash=? WHERE id=?', [password_hash($pw, PASSWORD_DEFAULT), $id]); audit('password_reset', 'user', $id, $uname); }
                audit('user_updated', 'user', $id, "$uname ($role)"); flash('success', 'User updated.');
            } else {
                check_password($pw);
                $nid = db_insert('INSERT INTO users (full_name, username, password_hash, role) VALUES (?,?,?,?)', [$name, $uname, password_hash($pw, PASSWORD_DEFAULT), $role]);
                audit('user_created', 'user', $nid, "$uname ($role)"); flash('success', 'User created.');
            }
        } elseif ($a === 'toggle') {
            if ($id === user_id()) throw new UserError('You cannot deactivate your own account.');
            $target = db_one('SELECT role, is_active FROM users WHERE id = ?', [$id]);
            if (!$target) throw new UserError('User not found.');
            if ($target['role'] === 'admin' && $target['is_active'] && (int)db_val("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1") <= 1) throw new UserError('At least one active administrator is required.');
            db_exec('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]); audit('user_toggled', 'user', $id, 'active toggled'); flash('success', 'Account status changed.');
        }
    } catch (UserError $e) { flash('danger', $e->getMessage()); }
    redirect_self();
}
$users = db_all('SELECT * FROM users ORDER BY is_active DESC, role, full_name');
page_header('Users', 'users');
?>
<button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#userModal" id="addUser"><i class="bi bi-person-plus"></i> Add user</button>
<div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last login</th><th></th></tr></thead><tbody>
<?php foreach ($users as $u): ?><tr class="<?= $u['is_active'] ? '' : 'table-secondary' ?>"><td class="fw-semibold"><?= e($u['full_name']) ?></td><td><code><?= e($u['username']) ?></code></td><td><?= e(ROLE_LABELS[$u['role']]) ?></td>
<td><?= $u['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td><td><?= e(fmt_dt($u['last_login'])) ?></td>
<td class="text-end text-nowrap"><button class="btn btn-sm btn-outline-secondary" data-fill="#userModal" data-bs-toggle="modal" data-bs-target="#userModal" data-json="<?= e(json_encode(['id' => $u['id'], 'full_name' => $u['full_name'], 'username' => $u['username'], 'role' => $u['role']])) ?>">Edit</button>
<?php if ((int)$u['id'] !== user_id()) echo action_form('toggle', ['id' => $u['id']], $u['is_active'] ? 'Deactivate' : 'Activate', 'btn btn-sm btn-outline-secondary', $u['is_active'] ? 'Deactivate ' . $u['full_name'] . '? They will no longer be able to log in.' : ''); ?></td></tr>
<?php endforeach; ?></tbody></table></div></div></div>
<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
<div class="modal-header"><h5 class="modal-title">User account</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
<div class="mb-2"><label class="form-label">Full name</label><input class="form-control" name="full_name" required maxlength="100"></div>
<div class="row"><div class="col-6 mb-2"><label class="form-label">Username</label><input class="form-control" name="username" required maxlength="50"></div>
<div class="col-6 mb-2"><label class="form-label">Role</label><select class="form-select" name="role"><?php foreach (ROLE_LABELS as $k => $v): ?><option value="<?= $k ?>"><?= e($v) ?></option><?php endforeach; ?></select></div></div>
<div class="mb-2"><label class="form-label">Password <span class="text-muted small" id="pwHint">(required, 8+ characters)</span></label><input class="form-control" type="password" name="password" minlength="8" autocomplete="new-password"></div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save user</button></div></form></div></div>
<?php page_footer('<script>document.getElementById("addUser").addEventListener("click",()=>{const f=document.querySelector("#userModal form");f.reset();f.querySelector("[name=id]").value=0;document.getElementById("pwHint").textContent="(required, 8+ characters)";f.password.required=true;});
document.getElementById("userModal").addEventListener("filled",()=>{document.getElementById("pwHint").textContent="(leave blank to keep current)";document.querySelector("#userModal [name=password]").required=false;document.querySelector("#userModal [name=password]").value="";});</script>');
