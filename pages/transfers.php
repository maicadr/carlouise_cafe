<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('transfers');
$locs = locations();

if (is_post()) {
    csrf_verify();
    try {
        if (post('action') === 'transfer') {
            $iid = post_int('item_id'); $from = post_int('from_id'); $to = post_int('to_id'); $q = parse_qty(post('qty'));
            if (!db_val('SELECT id FROM inventory_items WHERE id = ? AND is_active = 1', [$iid])) throw new UserError('Choose an item.');
            if (!db_val('SELECT id FROM inventory_locations WHERE id = ?', [$from]) || !db_val('SELECT id FROM inventory_locations WHERE id = ?', [$to])) throw new UserError('Choose the source and destination locations.');
            db_tx(fn() => transfer_stock($iid, $from, $to, $q, post('note')));
            audit('stock_transfer', 'inventory_item', $iid, item_loc_label($iid, $from) . ' -> ' . db_val('SELECT name FROM inventory_locations WHERE id = ?', [$to]) . ' x ' . qty($q));
            flash('success', 'Transferred ' . qty($q) . ' successfully.');
        }
    } catch (UserError $e) { flash('danger', $e->getMessage()); }
    redirect_self();
}

$items = db_all('SELECT id, name, unit FROM inventory_items WHERE is_active = 1 ORDER BY name');
$stockMap = [];
foreach (db_all('SELECT item_id, location_id, quantity FROM inventory_stock') as $s) $stockMap[$s['item_id']][$s['location_id']] = qty($s['quantity']);

$from = valid_date(get('from'), date('Y-m-d', strtotime('-30 day'))); $to = valid_date(get('to'), date('Y-m-d'));
$fi = (int)get('item'); $ft = get('type'); $fl = (int)get('location');
$where = ['m.created_at >= ?', 'm.created_at < ?']; $params = [day_start($from), day_end_exclusive($to)];
if ($fi) { $where[] = 'm.item_id = ?'; $params[] = $fi; }
if (in_array($ft, ['receive', 'transfer', 'usage', 'adjustment', 'order_usage', 'order_restore'], true)) { $where[] = 'm.movement_type = ?'; $params[] = $ft; }
if ($fl) { $where[] = '(m.from_location_id = ? OR m.to_location_id = ?)'; array_push($params, $fl, $fl); }
$w = implode(' AND ', $where);
[$off, $per, $page] = paginate_params(20);
$total = (int)db_val("SELECT COUNT(*) FROM stock_movements m WHERE $w", $params);
$moves = db_all("SELECT m.*, i.name AS item, i.unit, lf.name AS from_name, lt.name AS to_name, u.full_name AS staff FROM stock_movements m JOIN inventory_items i ON i.id = m.item_id
  LEFT JOIN inventory_locations lf ON lf.id = m.from_location_id LEFT JOIN inventory_locations lt ON lt.id = m.to_location_id LEFT JOIN users u ON u.id = m.user_id
  WHERE $w ORDER BY m.id DESC LIMIT $per OFFSET $off", $params);
$typeLabels = ['receive' => 'Received', 'transfer' => 'Transfer', 'usage' => 'Usage', 'adjustment' => 'Adjustment', 'order_usage' => 'Order usage', 'order_restore' => 'Order restored'];

page_header('Stock Transfers & Movements', 'transfers');
?>
<div class="card mb-3"><div class="card-header">Transfer stock between locations</div><div class="card-body">
<form method="post" data-confirm="Confirm this stock transfer?"><?= csrf_field() ?><input type="hidden" name="action" value="transfer">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Item</label><select class="form-select" name="item_id" id="tItem" required><option value="">Choose…</option>
      <?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['name']) ?> (<?= e($i['unit']) ?>)</option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label">From</label><select class="form-select" name="from_id" id="tFrom"><?php foreach ($locs as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $l['code'] === 'stockroom' ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label">To</label><select class="form-select" name="to_id" id="tTo"><?php foreach ($locs as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $l['code'] === 'kitchen' ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label">Quantity</label><input class="form-control" name="qty" inputmode="decimal" required><div class="form-text" id="avail">&nbsp;</div></div>
    <div class="col-6 col-md-2"><label class="form-label">Note</label><input class="form-control" name="note" maxlength="200"></div>
    <div class="col-md-1"><button class="btn btn-primary w-100">Transfer</button></div>
  </div>
</form></div></div>

<form class="card card-body mb-3" method="get"><div class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label small mb-0">From date</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">To date</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-3"><label class="form-label small mb-0">Item</label><select class="form-select" name="item"><option value="0">All items</option><?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>" <?= $fi === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Type</label><select class="form-select" name="type"><option value="">All</option><?php foreach ($typeLabels as $k => $v): ?><option value="<?= $k ?>" <?= $ft === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-1"><label class="form-label small mb-0">Location</label><select class="form-select" name="location"><option value="0">All</option><?php foreach ($locs as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $fl === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button><a class="btn btn-outline-secondary" href="<?= url('pages/transfers.php') ?>">Reset</a></div>
</div></form>
<div class="card"><div class="card-header">Stock movement history</div><div class="card-body p-0">
<?php if (!$moves): echo empty_state('arrow-left-right', 'No stock movements found', 'Movements appear when stock is received, transferred, adjusted or used by orders.'); else: ?>
<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>Date &amp; time</th><th>Item</th><th>Type</th><th>From</th><th>To</th><th class="text-end">Quantity</th><th>Reference / note</th><th>Staff</th></tr></thead><tbody>
<?php foreach ($moves as $m): ?><tr><td><?= e(fmt_dt($m['created_at'], 'M j, Y g:i A')) ?></td><td><?= e($m['item']) ?></td><td><span class="badge text-bg-light border"><?= e($typeLabels[$m['movement_type']]) ?></span></td>
<td><?= e($m['from_name'] ?? '—') ?></td><td><?= e($m['to_name'] ?? '—') ?></td><td class="text-end"><?= qty($m['quantity']) . ' ' . e($m['unit']) ?></td><td class="small"><?= e(trim(($m['reference'] ? $m['reference'] . ' · ' : '') . $m['note'])) ?></td><td><?= e($m['staff'] ?? 'System') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div><div class="card-footer bg-white"><?= pagination_html($total, $page, $per) ?></div></div>
<?php
$js = '<script>const STOCK=' . json_encode($stockMap) . ';const LN=' . json_encode(array_column($locs, 'name', 'id')) . ';
function upd(){const i=document.getElementById("tItem").value,f=document.getElementById("tFrom").value;const q=(STOCK[i]||{})[f];document.getElementById("avail").textContent=i?("Available in "+LN[f]+": "+(q===undefined?0:q)):"\u00a0";}
["tItem","tFrom"].forEach(id=>document.getElementById(id).addEventListener("change",upd));</script>';
page_footer($js);
