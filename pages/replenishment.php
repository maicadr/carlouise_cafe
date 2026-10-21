<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('replenishment');
$locs = locations();

function received_at_from(string $date): string {
    $d = valid_date($date, date('Y-m-d'));
    if ($d > date('Y-m-d')) throw new UserError('The received date cannot be in the future.');
    return $d . ' ' . date('H:i:s');
}

if (is_post()) {
    csrf_verify();
    try {
        $a = post('action');
        if ($a === 'create') {
            $iid = post_int('item_id'); $lid = post_int('location_id'); $q = parse_qty(post('qty_requested')); $notes = post('notes');
            if (!db_val('SELECT id FROM inventory_items WHERE id = ? AND is_active = 1', [$iid]) || !db_val('SELECT id FROM inventory_locations WHERE id = ?', [$lid])) throw new UserError('Choose an item and a destination location.');
            $now = post('already_received') === '1';
            $rid = db_tx(function () use ($iid, $lid, $q, $notes, $now) {
                $rid = create_replenishment($iid, $lid, $q, $notes);
                if ($now) receive_replenishment($rid, parse_qty(post('qty_received')), received_at_from(post('received_date')), '');
                return $rid;
            });
            audit($now ? 'replenishment_recorded' : 'replenishment_requested', 'replenishment', $rid, item_loc_label($iid, $lid) . ' qty ' . qty($q));
            flash('success', $now ? 'Replenishment recorded and stock updated.' : 'Replenishment request created. Stock will be added when you confirm receipt.');
        } elseif ($a === 'receive') {
            $rid = post_int('id'); $q = parse_qty(post('qty_received'));
            db_tx(fn() => receive_replenishment($rid, $q, received_at_from(post('received_date')), post('notes')));
            audit('replenishment_received', 'replenishment', $rid, 'received ' . qty($q));
            flash('success', 'Receipt confirmed and stock updated.');
        } elseif ($a === 'cancel') {
            $n = db_exec("UPDATE replenishments SET status = 'cancelled' WHERE id = ? AND status = 'requested'", [post_int('id')]);
            if (!$n) throw new UserError('Only open requests can be cancelled.');
            audit('replenishment_cancelled', 'replenishment', post_int('id'), 'cancelled'); flash('success', 'Request cancelled.');
        } elseif ($a === 'bulk') {
            $made = 0;
            db_tx(function () use (&$made) {
                foreach (low_stock_rows() as $r) {
                    if ((int)$r['open_requests'] > 0) continue;
                    create_replenishment((int)$r['item_id'], (int)$r['location_id'], suggested_restock_qty((float)$r['quantity'], (float)$r['min_threshold']), 'Weekly replenishment (auto-suggested)');
                    $made++;
                }
            });
            audit('replenishment_bulk', 'replenishment', null, "$made requests"); flash($made ? 'success' : 'info', $made ? "$made request(s) created from low-stock items." : 'Every low-stock item already has an open request.');
        }
    } catch (UserError $e) { flash('danger', $e->getMessage()); }
    redirect_self();
}

$low = low_stock_rows();
$items = db_all('SELECT id, name, unit FROM inventory_items WHERE is_active = 1 ORDER BY name');
$st = get('status'); $fi = (int)get('item'); $fl = (int)get('location');
$from = valid_date(get('from'), date('Y-m-d', strtotime('-90 day'))); $to = valid_date(get('to'), date('Y-m-d'));
$where = ['r.requested_at >= ?', 'r.requested_at < ?']; $params = [day_start($from), day_end_exclusive($to)];
if (in_array($st, ['requested', 'received', 'cancelled'], true)) { $where[] = 'r.status = ?'; $params[] = $st; }
if ($fi) { $where[] = 'r.item_id = ?'; $params[] = $fi; } if ($fl) { $where[] = 'r.location_id = ?'; $params[] = $fl; }
$w = implode(' AND ', $where);
[$off, $per, $page] = paginate_params(15);
$total = (int)db_val("SELECT COUNT(*) FROM replenishments r WHERE $w", $params);
$rows = db_all("SELECT r.*, i.name AS item, i.unit, l.name AS location, ur.full_name AS requester, uv.full_name AS receiver FROM replenishments r JOIN inventory_items i ON i.id = r.item_id JOIN inventory_locations l ON l.id = r.location_id
  JOIN users ur ON ur.id = r.requested_by LEFT JOIN users uv ON uv.id = r.received_by WHERE $w ORDER BY (r.status = 'requested') DESC, r.id DESC LIMIT $per OFFSET $off", $params);

page_header('Replenishment', 'replenishment');
$itemOpts = ''; foreach ($items as $i) $itemOpts .= '<option value="' . (int)$i['id'] . '">' . e($i['name']) . ' (' . e($i['unit']) . ')</option>';
$locOpts = ''; foreach ($locs as $l) $locOpts .= '<option value="' . (int)$l['id'] . '">' . e($l['name']) . '</option>';
?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">Items that need replenishment <span class="badge bg-danger"><?= count($low) ?></span>
  <span><?php if ($low): echo action_form('bulk', [], 'Create requests for all low items', 'btn btn-sm btn-outline-primary', 'Create a request for every low-stock item that has no open request?', 'list-check'); endif; ?></span></div>
<div class="card-body p-0"><?php if (!$low): echo empty_state('check-circle', 'Nothing needs replenishing', 'Items appear here when stock reaches its minimum.'); else: ?>
<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Item</th><th>Location</th><th class="text-end">On hand</th><th class="text-end">Minimum</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($low as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['location_name']) ?></td><td class="text-end"><?= qty($r['quantity']) . ' ' . e($r['unit']) ?></td><td class="text-end"><?= qty($r['min_threshold']) ?></td>
<td><?= status_badge(stock_status((float)$r['quantity'], (float)$r['min_threshold'])) ?></td><td class="text-end"><?php if ((int)$r['open_requests']): ?><span class="badge bg-secondary">Request open</span><?php else: ?>
<button class="btn btn-sm btn-outline-primary" data-fill="#newModal" data-bs-toggle="modal" data-bs-target="#newModal" data-json="<?= e(json_encode(['item_id' => $r['item_id'], 'location_id' => $r['location_id'], 'qty_requested' => qty(suggested_restock_qty((float)$r['quantity'], (float)$r['min_threshold']))])) ?>">Create request</button><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></div></div>

<button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#newModal" id="newBtn"><i class="bi bi-plus-lg"></i> Record replenishment</button>
<form class="card card-body mb-3" method="get"><div class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label small mb-0">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Status</label><select class="form-select" name="status"><option value="">All</option><?php foreach (['requested' => 'Open', 'received' => 'Received', 'cancelled' => 'Cancelled'] as $k => $v): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-3"><label class="form-label small mb-0">Item</label><select class="form-select" name="item"><option value="0">All</option><?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>" <?= $fi === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-1"><label class="form-label small mb-0">Location</label><select class="form-select" name="location"><option value="0">All</option><?php foreach ($locs as $l): ?><option value="<?= (int)$l['id'] ?>" <?= $fl === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button><a class="btn btn-outline-secondary" href="<?= url('pages/replenishment.php') ?>">Reset</a></div>
</div></form>
<div class="card"><div class="card-header">Replenishment records</div><div class="card-body p-0">
<?php if (!$rows): echo empty_state('truck', 'No replenishment records', 'Record weekly restocking or any time supplies arrive.'); else: ?>
<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>#</th><th>Item</th><th>Destination</th><th class="text-end">Requested</th><th class="text-end">Received</th><th>Status</th><th>Requested by</th><th>Received</th><th>Notes</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= e($r['item']) ?></td><td><?= e($r['location']) ?></td><td class="text-end"><?= qty($r['qty_requested']) . ' ' . e($r['unit']) ?></td>
<td class="text-end"><?= $r['qty_received'] !== null ? qty($r['qty_received']) . ' ' . e($r['unit']) : '—' ?></td><td><?= status_badge($r['status']) ?></td>
<td><?= e($r['requester']) ?><div class="small text-muted"><?= e(fmt_dt($r['requested_at'], 'M j, Y')) ?></div></td><td><?= $r['received_at'] ? e(fmt_dt($r['received_at'], 'M j, Y')) . '<div class="small text-muted">' . e($r['receiver']) . '</div>' : '—' ?></td>
<td class="small" style="max-width:240px"><?= e($r['notes']) ?></td>
<td class="text-nowrap"><?php if ($r['status'] === 'requested'): ?>
<button class="btn btn-sm btn-success" data-fill="#recvModal" data-bs-toggle="modal" data-bs-target="#recvModal" data-json="<?= e(json_encode(['id' => $r['id'], 'qty_received' => qty($r['qty_requested']), 'label' => $r['item'] . ' → ' . $r['location']])) ?>">Confirm received</button>
<?= action_form('cancel', ['id' => $r['id']], 'Cancel', 'btn btn-sm btn-outline-danger', 'Cancel this request?') ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div><div class="card-footer bg-white"><?= pagination_html($total, $page, $per) ?></div></div>

<div class="modal fade" id="newModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="modal-header"><h5 class="modal-title">Record replenishment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
  <div class="mb-2"><label class="form-label">Item</label><select class="form-select" name="item_id" required><?= $itemOpts ?></select></div>
  <div class="row"><div class="col-6 mb-2"><label class="form-label">Destination</label><select class="form-select" name="location_id"><?= $locOpts ?></select></div>
  <div class="col-6 mb-2"><label class="form-label">Quantity requested</label><input class="form-control" name="qty_requested" inputmode="decimal" required></div></div>
  <div class="mb-2"><label class="form-label">Notes</label><input class="form-control" name="notes" maxlength="400" placeholder="Supplier, weekly run, etc."></div>
  <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="already_received" value="1" id="already"><label class="form-check-label" for="already">Supplies already received - add to stock now</label></div>
  <div class="row d-none" id="recvFields"><div class="col-6"><label class="form-label">Quantity received</label><input class="form-control" name="qty_received" inputmode="decimal"></div><div class="col-6"><label class="form-label">Date received</label><input class="form-control" type="date" name="received_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div></div>
  <div class="small text-muted mt-2">Requested quantities are never added to stock until receipt is confirmed.</div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>

<div class="modal fade" id="recvModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" data-confirm="Confirm receipt? This adds the quantity to stock."><?= csrf_field() ?><input type="hidden" name="action" value="receive"><input type="hidden" name="id" value="0">
  <div class="modal-header"><h5 class="modal-title">Confirm received: <span data-text="label"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body">
  <div class="row"><div class="col-6 mb-2"><label class="form-label">Quantity received</label><input class="form-control" name="qty_received" inputmode="decimal" required></div><div class="col-6 mb-2"><label class="form-label">Date received</label><input class="form-control" type="date" name="received_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div></div>
  <div class="mb-2"><label class="form-label">Notes</label><input class="form-control" name="notes" maxlength="200"></div></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success">Add to stock</button></div></form></div></div>
<?php
page_footer('<script>const al=document.getElementById("already");al.addEventListener("change",()=>document.getElementById("recvFields").classList.toggle("d-none",!al.checked));
document.getElementById("newBtn").addEventListener("click",()=>{const f=document.querySelector("#newModal form");f.reset();document.getElementById("recvFields").classList.add("d-none");});</script>');
