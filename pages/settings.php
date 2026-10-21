<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('settings');

if (is_post()) {
    csrf_verify();
    try {
        $a = post('action'); $id = post_int('id');
        if ($a === 'general') {
            foreach (['cafe_name' => 100, 'cafe_address' => 200, 'cafe_phone' => 40, 'receipt_footer' => 200] as $k => $max) save_setting($k, mb_substr(post($k), 0, $max));
            if (post('cafe_name') === '') throw new UserError('The cafe name cannot be empty.');
            audit('settings_saved', 'settings', null, 'General settings'); flash('success', 'Settings saved.');
        } elseif ($a === 'cat_add' || $a === 'cat_rename') {
            $n = post('name'); if ($n === '' || mb_strlen($n) > 80) throw new UserError('Enter a category name.');
            if (db_val('SELECT id FROM categories WHERE name = ? AND id <> ?', [$n, $id])) throw new UserError('That category already exists.');
            if ($a === 'cat_add') db_insert('INSERT INTO categories (name) VALUES (?)', [$n]); else db_exec('UPDATE categories SET name = ? WHERE id = ?', [$n, $id]);
            audit('category_saved', 'category', $id ?: null, $n); flash('success', 'Category saved.');
        } elseif ($a === 'cat_toggle') { db_exec('UPDATE categories SET is_active = 1 - is_active WHERE id = ?', [$id]); flash('success', 'Category updated.');
        } elseif ($a === 'table_add') {
            $n = post('name'); if ($n === '' || mb_strlen($n) > 40) throw new UserError('Enter a table name, e.g. Table 7.');
            if (db_val('SELECT id FROM dining_tables WHERE name = ?', [$n])) throw new UserError('That table already exists.');
            db_insert('INSERT INTO dining_tables (name) VALUES (?)', [$n]); audit('table_added', 'table', null, $n); flash('success', 'Table added.');
        } elseif ($a === 'table_toggle') { db_exec('UPDATE dining_tables SET is_active = 1 - is_active WHERE id = ?', [$id]); flash('success', 'Table updated.');
        } elseif ($a === 'pm_add') {
            $n = post('name'); if ($n === '' || mb_strlen($n) > 40) throw new UserError('Enter a payment method name.');
            if (db_val('SELECT id FROM payment_methods WHERE name = ?', [$n])) throw new UserError('That payment method already exists.');
            db_insert('INSERT INTO payment_methods (name, is_cash, requires_reference) VALUES (?,?,?)', [$n, post('is_cash') === '1' ? 1 : 0, post('requires_reference') === '1' ? 1 : 0]);
            audit('payment_method_added', 'payment_method', null, $n); flash('success', 'Payment method added.');
        } elseif ($a === 'pm_toggle') {
            $pm = db_one('SELECT is_active FROM payment_methods WHERE id = ?', [$id]);
            if ($pm && $pm['is_active'] && (int)db_val('SELECT COUNT(*) FROM payment_methods WHERE is_active = 1') <= 1) throw new UserError('Keep at least one payment method enabled.');
            db_exec('UPDATE payment_methods SET is_active = 1 - is_active WHERE id = ?', [$id]); audit('payment_method_toggled', 'payment_method', $id, 'toggled'); flash('success', 'Payment method updated.');
        }
    } catch (UserError $e) { flash('danger', $e->getMessage()); }
    redirect_self();
}
$cats = db_all('SELECT * FROM categories ORDER BY name'); $tables = db_all('SELECT * FROM dining_tables ORDER BY id'); $pms = db_all('SELECT * FROM payment_methods ORDER BY id');
page_header('Settings', 'settings');
?>
<div class="row g-3">
<div class="col-lg-6"><div class="card h-100"><div class="card-header">Cafe details (shown on receipts)</div><div class="card-body"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="general">
  <div class="mb-2"><label class="form-label">Cafe name</label><input class="form-control" name="cafe_name" required maxlength="100" value="<?= e(setting('cafe_name')) ?>"></div>
  <div class="mb-2"><label class="form-label">Address</label><input class="form-control" name="cafe_address" maxlength="200" value="<?= e(setting('cafe_address')) ?>"></div>
  <div class="mb-2"><label class="form-label">Phone</label><input class="form-control" name="cafe_phone" maxlength="40" value="<?= e(setting('cafe_phone')) ?>"></div>
  <div class="mb-3"><label class="form-label">Receipt footer</label><input class="form-control" name="receipt_footer" maxlength="200" value="<?= e(setting('receipt_footer')) ?>"></div>
  <button class="btn btn-primary">Save settings</button></form></div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-header">Payment methods</div><div class="card-body">
  <?php foreach ($pms as $m): ?><div class="d-flex justify-content-between align-items-center border-bottom py-2"><span><?= e($m['name']) ?> <span class="small text-muted"><?= $m['is_cash'] ? '(cash, gives change)' : ($m['requires_reference'] ? '(needs reference no.)' : '') ?></span> <?= $m['is_active'] ? '' : '<span class="badge bg-secondary">Disabled</span>' ?></span>
    <?= action_form('pm_toggle', ['id' => $m['id']], $m['is_active'] ? 'Disable' : 'Enable', 'btn btn-sm btn-outline-secondary') ?></div><?php endforeach; ?>
  <form method="post" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="pm_add"><div class="col-12 col-md-5"><input class="form-control" name="name" placeholder="New method, e.g. Maya" maxlength="40" required></div>
  <div class="col-6 col-md-3 form-check ps-4 pt-2"><input class="form-check-input" type="checkbox" name="is_cash" value="1" id="pmc"><label class="form-check-label small" for="pmc">Cash</label></div>
  <div class="col-6 col-md-4 form-check ps-4 pt-2"><input class="form-check-input" type="checkbox" name="requires_reference" value="1" id="pmr" checked><label class="form-check-label small" for="pmr">Needs reference</label></div>
  <div class="col-12"><button class="btn btn-outline-primary btn-sm">Add method</button></div></form></div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-header">Product categories</div><div class="card-body">
  <?php foreach ($cats as $c): ?><div class="d-flex gap-2 mb-2"><form method="post" class="d-flex gap-2 flex-grow-1"><?= csrf_field() ?><input type="hidden" name="action" value="cat_rename"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    <input class="form-control form-control-sm" name="name" value="<?= e($c['name']) ?>" maxlength="80" <?= $c['is_active'] ? '' : 'disabled' ?>><?php if ($c['is_active']): ?><button class="btn btn-sm btn-outline-primary">Rename</button><?php endif; ?></form>
    <?= action_form('cat_toggle', ['id' => $c['id']], $c['is_active'] ? 'Hide' : 'Show', 'btn btn-sm btn-outline-secondary') ?></div><?php endforeach; ?>
  <form method="post" class="d-flex gap-2 mt-3"><?= csrf_field() ?><input type="hidden" name="action" value="cat_add"><input class="form-control" name="name" placeholder="New category" maxlength="80" required><button class="btn btn-primary">Add</button></form></div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-header">Dining tables</div><div class="card-body">
  <div class="d-flex flex-wrap gap-2 mb-3"><?php foreach ($tables as $t): ?><?= action_form('table_toggle', ['id' => $t['id']], $t['name'] . ($t['is_active'] ? '' : ' (off)'), 'btn btn-sm ' . ($t['is_active'] ? 'btn-outline-dark' : 'btn-outline-secondary text-decoration-line-through')) ?><?php endforeach; ?></div>
  <div class="small text-muted mb-2">Click a table to turn it on or off.</div>
  <form method="post" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="action" value="table_add"><input class="form-control" name="name" placeholder="e.g. Table 7 or Patio 1" maxlength="40" required><button class="btn btn-primary">Add table</button></form></div></div></div>
</div>
<?php page_footer();
