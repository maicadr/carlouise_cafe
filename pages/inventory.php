<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('inventory');
$manage = can('inventory_manage');
$locs = locations();
$visible = visible_locations();
$visIds = array_map(fn($l) => (int)$l['id'], $visible);

if (is_post()) {
    csrf_verify();
    try {
        $a = post('action');
        if ($a === 'report_low') {                       // any inventory viewer, own location only
            $iid = post_int('item_id'); $lid = post_int('location_id');
            if (!in_array($lid, $visIds, true)) throw new UserError('You can only report items for your own location.');
            if (!db_val('SELECT id FROM inventory_items WHERE id = ? AND is_active = 1', [$iid])) throw new UserError('Item not found.');
            if (db_val("SELECT id FROM replenishments WHERE item_id = ? AND location_id = ? AND status = 'requested' LIMIT 1", [$iid, $lid])) throw new UserError('A replenishment request is already open for this item.');
            $q = parse_qty(post('qty'));
            $rid = create_replenishment($iid, $lid, $q, 'Reported low by ' . current_user()['name'] . (post('notes') !== '' ? ': ' . post('notes') : ''));
            audit('low_stock_reported', 'replenishment', $rid, item_loc_label($iid, $lid));
            flash('success', 'Low-stock report sent to the inventory manager.');
        } elseif ($manage) {
            if ($a === 'add_item') {
                $name = post('name'); $cat = post('category', 'General') ?: 'General'; $unit = post('unit');
                if ($name === '' || $unit === '') throw new UserError('Enter the item name and unit of measurement.');
                if (db_val('SELECT id FROM inventory_items WHERE name = ?', [$name])) throw new UserError('An item with this name already exists.');
                db_tx(function () use ($name, $cat, $unit, $locs) {
                    $iid = db_insert('INSERT INTO inventory_items (name, category, unit) VALUES (?,?,?)', [mb_substr($name, 0, 120), mb_substr($cat, 0, 60), mb_substr($unit, 0, 20)]);
                    foreach ($locs as $l) {
                        $min = parse_qty(post('min_' . $l['id'], '0') ?: '0', true); $q0 = parse_qty(post('qty_' . $l['id'], '0') ?: '0', true);
                        db_exec('INSERT INTO inventory_stock (item_id, location_id, quantity, min_threshold) VALUES (?,?,0,?)', [$iid, (int)$l['id'], $min]);
                        if ($q0 > 0) change_stock($iid, (int)$l['id'], $q0, 'receive', 'Opening stock');
                    }
                    audit('inventory_item_created', 'inventory_item', $iid, $name);
                });
                flash('success', 'Inventory item added.');
            } elseif ($a === 'edit_item') {
                $iid = post_int('id'); $name = post('name'); $unit = post('unit'); $cat = post('category', 'General') ?: 'General';
                if ($name === '' || $unit === '') throw new UserError('Enter the item name and unit.');
                if (db_val('SELECT id FROM inventory_items WHERE name = ? AND id <> ?', [$name, $iid])) throw new UserError('Another item already uses this name.');
                db_tx(function () use ($iid, $name, $unit, $cat, $locs) {
                    db_exec('UPDATE inventory_items SET name=?, category=?, unit=?, is_active=? WHERE id=?', [mb_substr($name, 0, 120), mb_substr($cat, 0, 60), mb_substr($unit, 0, 20), post('is_active') === '1' ? 1 : 0, $iid]);
                    foreach ($locs as $l) {
                        $min = parse_qty(post('min_' . $l['id'], '0') ?: '0', true);
                        lock_stock_row($iid, (int)$l['id']);
                        db_exec('UPDATE inventory_stock SET min_threshold = ? WHERE item_id = ? AND location_id = ?', [$min, $iid, (int)$l['id']]);
                    }
                });
                audit('inventory_item_updated', 'inventory_item', $iid, $name);
                flash('success', 'Inventory item updated.');
            } elseif ($a === 'receive') {
                $iid = post_int('item_id'); $lid = post_int('location_id'); $q = parse_qty(post('qty'));
                db_tx(fn() => change_stock($iid, $lid, $q, 'receive', post('note', 'Supplies received')));
                audit('stock_received', 'inventory_item', $iid, item_loc_label($iid, $lid) . ' +' . qty($q));
                flash('success', 'Supplies received: ' . qty($q) . ' added.');
            } elseif ($a === 'adjust') {
                $iid = post_int('item_id'); $lid = post_int('location_id'); $mode = post('mode'); $reason = post('reason');
                if (mb_strlen($reason) < 3) throw new UserError('Enter a reason for the adjustment.');
                $val = parse_qty(post('qty'), $mode === 'set');
                db_tx(function () use ($iid, $lid, $mode, $val, $reason) {
                    $cur = lock_stock_row($iid, $lid);
                    if ($mode === 'usage') change_stock($iid, $lid, -$val, 'usage', $reason);
                    elseif ($mode === 'decrease') change_stock($iid, $lid, -$val, 'adjustment', $reason);
                    elseif ($mode === 'increase') change_stock($iid, $lid, $val, 'adjustment', $reason);
                    elseif ($mode === 'set') {
                        $delta = round($val - $cur, 3);
                        if ($delta == 0) throw new UserError('The counted quantity matches the current stock. Nothing to adjust.');
                        change_stock($iid, $lid, $delta, 'adjustment', 'Stock count: ' . qty($cur) . ' → ' . qty($val) . '. ' . $reason);
                    } else throw new UserError('Choose an adjustment type.');
                });
                audit('stock_adjusted', 'inventory_item', $iid, item_loc_label($iid, $lid) . " $mode " . qty($val) . ' | ' . $reason);
                flash('success', 'Stock updated.');
            } elseif ($a === 'toggle_item') {
                db_exec('UPDATE inventory_items SET is_active = 1 - is_active WHERE id = ?', [post_int('id')]);
                audit('inventory_item_toggled', 'inventory_item', post_int('id'), 'active toggled'); flash('success', 'Item status changed.');
            }
        } else { throw new UserError('You do not have permission to change inventory.'); }
    } catch (UserError $e) { flash('danger', $e->getMessage()); }
    redirect_self();
}

// ---- data ----
$q = get('q'); $cat = get('category'); $locF = (int)get('location'); $statusF = get('status'); $inactive = get('inactive') === '1';
$items = db_all('SELECT * FROM inventory_items' . ($inactive ? '' : ' WHERE is_active = 1') . ' ORDER BY name');
$stock = [];
foreach (db_all('SELECT * FROM inventory_stock') as $s) $stock[$s['item_id']][$s['location_id']] = $s;
$open = [];
foreach (db_all("SELECT item_id, location_id FROM replenishments WHERE status='requested'") as $r) $open[$r['item_id'] . '-' . $r['location_id']] = true;
$cats = array_column(db_all('SELECT DISTINCT category FROM inventory_items ORDER BY category'), 'category');

$rank = ['ok' => 0, 'low' => 1, 'out' => 2];
$list = [];
foreach ($items as $it) {
    if ($q !== '' && stripos($it['name'], $q) === false && stripos($it['category'], $q) === false) continue;
    if ($cat !== '' && $it['category'] !== $cat) continue;
    $total = 0; $worst = 'ok'; $cells = []; $locStatus = 'ok';
    foreach ($visible as $l) {
        $s = $stock[$it['id']][$l['id']] ?? ['quantity' => 0, 'min_threshold' => 0, 'updated_at' => null];
        $st = stock_status((float)$s['quantity'], (float)$s['min_threshold']);
        $cells[$l['id']] = ['qty' => (float)$s['quantity'], 'min' => (float)$s['min_threshold'], 'st' => $st];
        $total += (float)$s['quantity'];
        if ($rank[$st] > $rank[$worst]) $worst = $st;
        if ($locF === (int)$l['id']) $locStatus = $st;
    }
    $eff = $locF ? $locStatus : $worst;
    if (in_array($statusF, ['low', 'out', 'ok'], true)) { if ($statusF === 'low' ? !in_array($eff, ['low', 'out'], true) : $eff !== $statusF) continue; }
    $list[] = ['it' => $it, 'cells' => $cells, 'total' => $total, 'st' => $eff];
}
$totalRows = count($list);
[$off, $per, $page] = paginate_params(20);
$pageRows = array_slice($list, $off, $per);

page_header('Inventory', 'inventory');
?>
<form class="card card-body mb-3" method="get"><div class="row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label small mb-0">Search</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Item or category"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Category</label><select class="form-select" name="category"><option value="">All</option><?php foreach ($cats as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
  <?php if (count($visible) > 1): ?><div class="col-6 col-md-2"><label class="form-label small mb-0">Location</label><select class="form-select" name="location"><option value="0">All locations</option><?php foreach ($visible as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $locF === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Stock status</label><select class="form-select" name="status"><option value="">All</option><option value="low" <?= $statusF === 'low' ? 'selected' : '' ?>>Low or out</option><option value="out" <?= $statusF === 'out' ? 'selected' : '' ?>>Out of stock</option><option value="ok" <?= $statusF === 'ok' ? 'selected' : '' ?>>OK</option></select></div>
  <?php if ($manage): ?><div class="col-6 col-md-1"><div class="form-check mt-4"><input class="form-check-input" type="checkbox" name="inactive" value="1" id="inact" <?= $inactive ? 'checked' : '' ?>><label class="form-check-label small" for="inact">Inactive</label></div></div><?php endif; ?>
  <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button><a class="btn btn-outline-secondary" href="<?= url('pages/inventory.php') ?>">Reset</a></div>
</div></form>
<?php if ($manage): ?><div class="mb-3 d-flex flex-wrap gap-2">
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal"><i class="bi bi-plus-lg"></i> Add item</button>
  <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#recvModal"><i class="bi bi-box-arrow-in-down"></i> Receive supplies</button>
  <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#adjModal"><i class="bi bi-sliders"></i> Usage / adjustment</button>
  <a class="btn btn-outline-secondary" href="<?= url('pages/transfers.php') ?>"><i class="bi bi-arrow-left-right"></i> Transfer stock</a></div>
<?php else: ?><div class="alert alert-info py-2 small">Showing <?= e(implode(', ', array_column($visible, 'name'))) ?> inventory. Use <strong>Report low</strong> to tell the inventory manager an item needs restocking.</div><?php endif; ?>
<div class="card"><div class="card-body p-0">
<?php if (!$pageRows): echo empty_state('boxes', 'No inventory items match', $manage ? 'Add an item or change the filters.' : ''); else: ?>
<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Item</th><th>Category</th><th>Unit</th>
  <?php foreach ($visible as $l): ?><th class="text-end"><?= e($l['name']) ?></th><?php endforeach; ?>
  <?php if (count($visible) > 1): ?><th class="text-end">Total</th><?php endif; ?><th>Status</th><th>Updated</th><th></th></tr></thead><tbody>
<?php foreach ($pageRows as $r): $it = $r['it']; ?>
<tr class="<?= !$it['is_active'] ? 'table-secondary' : '' ?>"><td class="fw-semibold"><?= e($it['name']) ?></td><td><?= e($it['category']) ?></td><td><?= e($it['unit']) ?></td>
  <?php foreach ($visible as $l): $c = $r['cells'][$l['id']]; ?><td class="text-end <?= $c['st'] === 'out' ? 'text-danger fw-bold' : ($c['st'] === 'low' ? 'text-warning-emphasis fw-bold' : '') ?>"><?= qty($c['qty']) ?><?= $c['min'] > 0 ? '<div class="small text-muted fw-normal">min ' . qty($c['min']) . '</div>' : '' ?></td><?php endforeach; ?>
  <?php if (count($visible) > 1): ?><td class="text-end fw-semibold"><?= qty($r['total']) ?></td><?php endif; ?>
  <td><?= status_badge($r['st']) ?></td><td class="small text-muted"><?= e(fmt_dt($it['updated_at'], 'M j, Y')) ?></td>
  <td class="text-end text-nowrap">
  <?php if ($manage): ?>
    <button class="btn btn-sm btn-outline-secondary" data-fill="#editModal" data-bs-toggle="modal" data-bs-target="#editModal" data-json="<?php $ej = ['id' => $it['id'], 'name' => $it['name'], 'category' => $it['category'], 'unit' => $it['unit'], 'is_active' => $it['is_active']]; foreach ($visible as $l) $ej['min_' . $l['id']] = qty($r['cells'][$l['id']]['min']); echo e(json_encode($ej)); ?>">Edit</button>
    <?= action_form('toggle_item', ['id' => $it['id']], $it['is_active'] ? 'Deactivate' : 'Activate', 'btn btn-sm btn-outline-secondary', $it['is_active'] ? 'Deactivate ' . $it['name'] . '? History is kept.' : '') ?>
  <?php else: foreach ($visible as $l): $c = $r['cells'][$l['id']]; if ($c['st'] !== 'ok' && !isset($open[$it['id'] . '-' . $l['id']])): ?>
    <button class="btn btn-sm btn-outline-danger" data-fill="#reportModal" data-bs-toggle="modal" data-bs-target="#reportModal" data-json="<?= e(json_encode(['item_id' => $it['id'], 'location_id' => $l['id'], 'iname' => $it['name'], 'qty' => qty(suggested_restock_qty($c['qty'], $c['min']))])) ?>">Report low</button>
  <?php elseif ($c['st'] !== 'ok'): ?><span class="badge bg-secondary">Reported</span><?php endif; endforeach; endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
</div><div class="card-footer bg-white"><?= pagination_html($totalRows, $page, $per) ?></div></div>

<?php
$itemOpts = ''; foreach ($items as $it) if ($it['is_active']) $itemOpts .= '<option value="' . (int)$it['id'] . '">' . e($it['name']) . ' (' . e($it['unit']) . ')</option>';
$locOpts = ''; foreach ($locs as $l) $locOpts .= '<option value="' . (int)$l['id'] . '">' . e($l['name']) . '</option>';
?>
<datalist id="catList"><?php foreach ($cats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
<?php if ($manage): ?>
<div class="modal fade" id="addModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="add_item">
  <div class="modal-header"><h5 class="modal-title">Add inventory item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
  <div class="row"><div class="col-md-5 mb-2"><label class="form-label">Item name</label><input class="form-control" name="name" required maxlength="120"></div>
  <div class="col-md-4 mb-2"><label class="form-label">Category</label><input class="form-control" name="category" list="catList" maxlength="60" placeholder="e.g. Dairy"></div>
  <div class="col-md-3 mb-2"><label class="form-label">Unit</label><input class="form-control" name="unit" required maxlength="20" placeholder="kg, L, pcs"></div></div>
  <table class="table table-sm"><thead><tr><th>Location</th><th>Opening quantity</th><th>Minimum threshold</th></tr></thead><tbody>
  <?php foreach ($locs as $l): ?><tr><td><?= e($l['name']) ?></td><td><input class="form-control form-control-sm" name="qty_<?= (int)$l['id'] ?>" inputmode="decimal" value="0"></td><td><input class="form-control form-control-sm" name="min_<?= (int)$l['id'] ?>" inputmode="decimal" value="0"></td></tr><?php endforeach; ?></tbody></table>
  <div class="small text-muted">A minimum of 0 means no low-stock alert for that location.</div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add item</button></div></form></div></div>

<div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="edit_item"><input type="hidden" name="id" value="0">
  <div class="modal-header"><h5 class="modal-title">Edit item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
  <div class="mb-2"><label class="form-label">Item name</label><input class="form-control" name="name" required maxlength="120"></div>
  <div class="row"><div class="col-7 mb-2"><label class="form-label">Category</label><input class="form-control" name="category" list="catList" maxlength="60"></div><div class="col-5 mb-2"><label class="form-label">Unit</label><input class="form-control" name="unit" required maxlength="20"></div></div>
  <div class="small fw-semibold mb-1">Minimum stock thresholds</div>
  <?php foreach ($visible as $l): ?><div class="row mb-1 align-items-center"><div class="col-6"><?= e($l['name']) ?></div><div class="col-6"><input class="form-control form-control-sm" name="min_<?= (int)$l['id'] ?>" inputmode="decimal"></div></div><?php endforeach; ?>
  <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="e_active"><label class="form-check-label" for="e_active">Active</label></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save changes</button></div></form></div></div>

<div class="modal fade" id="recvModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="receive">
  <div class="modal-header"><h5 class="modal-title">Receive supplies</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
  <div class="mb-2"><label class="form-label">Item</label><select class="form-select" name="item_id" required><?= $itemOpts ?></select></div>
  <div class="row"><div class="col-6 mb-2"><label class="form-label">Into location</label><select class="form-select" name="location_id"><?= $locOpts ?></select></div><div class="col-6 mb-2"><label class="form-label">Quantity</label><input class="form-control" name="qty" inputmode="decimal" required></div></div>
  <div class="mb-2"><label class="form-label">Supplier / note</label><input class="form-control" name="note" maxlength="200"></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add to stock</button></div></form></div></div>

<div class="modal fade" id="adjModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" data-confirm="Record this stock change?"><?= csrf_field() ?><input type="hidden" name="action" value="adjust">
  <div class="modal-header"><h5 class="modal-title">Stock usage / adjustment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
  <div class="mb-2"><label class="form-label">Item</label><select class="form-select" name="item_id" required><?= $itemOpts ?></select></div>
  <div class="row"><div class="col-6 mb-2"><label class="form-label">Location</label><select class="form-select" name="location_id"><?= $locOpts ?></select></div>
  <div class="col-6 mb-2"><label class="form-label">Type</label><select class="form-select" name="mode"><option value="usage">Usage (deduct)</option><option value="decrease">Decrease (spoilage, loss)</option><option value="increase">Increase (correction)</option><option value="set">Set to counted quantity</option></select></div></div>
  <div class="mb-2"><label class="form-label">Quantity</label><input class="form-control" name="qty" inputmode="decimal" required></div>
  <div class="mb-2"><label class="form-label">Reason (required)</label><input class="form-control" name="reason" required minlength="3" maxlength="200"></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
<?php else: ?>
<div class="modal fade" id="reportModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="report_low"><input type="hidden" name="item_id" value="0"><input type="hidden" name="location_id" value="0">
  <div class="modal-header"><h5 class="modal-title">Report low stock: <span data-text="iname"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
  <div class="mb-2"><label class="form-label">Quantity needed</label><input class="form-control" name="qty" inputmode="decimal" required></div>
  <div class="mb-2"><label class="form-label">Note (optional)</label><input class="form-control" name="notes" maxlength="200"></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Send report</button></div></form></div></div>
<?php endif; ?>
<?php page_footer();
