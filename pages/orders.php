<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('orders', is_ajax());

if (is_post()) {
    csrf_verify();
    try {
        if (post('action') === 'status' && can('pos')) { set_order_status(post_int('order_id'), post('status')); flash('success', 'Order marked as ' . post('status') . '.'); }
    } catch (UserError $e) { flash('danger', $e->getMessage()); }
    redirect_self();
}

$from = valid_date(get('from'), date('Y-m-d'));
$to = valid_date(get('to'), date('Y-m-d'));
$status = get('status'); $pay = get('pay'); $q = get('q');
$where = ['o.created_at >= ?', 'o.created_at < ?']; $params = [day_start($from), day_end_exclusive($to)];
if (in_array($status, ['pending', 'preparing', 'ready', 'completed', 'cancelled'], true)) { $where[] = 'o.status = ?'; $params[] = $status; }
if (in_array($pay, ['unpaid', 'paid', 'refunded'], true)) { $where[] = 'o.payment_status = ?'; $params[] = $pay; }
if ($q !== '') { $where[] = '(o.order_no LIKE ? OR o.customer_name LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
$w = implode(' AND ', $where);
[$off, $per, $page] = paginate_params(20);
$total = (int)db_val("SELECT COUNT(*) FROM orders o WHERE $w", $params);
$rows = db_all("SELECT o.*, t.name AS table_name, (SELECT SUM(quantity) FROM order_items WHERE order_id = o.id) AS qty
                FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id WHERE $w
                ORDER BY (o.status = 'ready') DESC, o.created_at DESC LIMIT $per OFFSET $off", $params);
$readyCount = (int)db_val("SELECT COUNT(*) FROM orders WHERE status = 'ready'");

function orders_table(array $rows, int $readyCount): void { ?>
  <?php if ($readyCount): ?><div class="alert alert-success py-2"><i class="bi bi-bell-fill"></i> <strong><?= $readyCount ?></strong> order<?= $readyCount > 1 ? 's are' : ' is' ?> ready to serve.</div><?php endif; ?>
  <?php if (!$rows): echo empty_state('receipt', 'No orders found', 'Change the filters or create a new order.'); else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Order</th><th>Time</th><th>Type</th><th class="text-end">Items</th><th class="text-end">Total</th><th>Status</th><th>Payment</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['status'] === 'ready' ? 'ready-row' : '' ?>">
        <td><a class="fw-semibold" href="<?= url('pages/order_view.php?id=' . (int)$r['id']) ?>"><?= e($r['order_no']) ?></a><?php if ($r['customer_name']): ?><div class="small text-muted"><?= e($r['customer_name']) ?></div><?php endif; ?></td>
        <td><?= e(fmt_dt($r['created_at'], 'M j, g:i A')) ?></td>
        <td><?= e(ORDER_TYPES[$r['order_type']]) ?><?= $r['table_name'] ? ' · ' . e($r['table_name']) : '' ?></td>
        <td class="text-end"><?= (int)$r['qty'] ?></td>
        <td class="text-end"><?= peso($r['total']) ?></td>
        <td><?= status_badge($r['status']) ?></td>
        <td><?= status_badge($r['payment_status']) ?></td>
        <td class="text-end text-nowrap">
          <?php if ($r['status'] === 'ready' && can('pos')) echo action_form('status', ['order_id' => $r['id'], 'status' => 'completed'], 'Mark served', 'btn btn-sm btn-success', 'Mark order ' . $r['order_no'] . ' as served/completed?', 'check2-circle'); ?>
          <a class="btn btn-sm btn-outline-secondary" href="<?= url('pages/order_view.php?id=' . (int)$r['id']) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif;
}

if (get('partial') === '1') { orders_table($rows, $readyCount); exit; }

page_header('Orders', 'orders');
?>
<form class="card card-body mb-3 no-print" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-0">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Status</label><select class="form-select" name="status"><option value="">All</option>
      <?php foreach (['pending', 'preparing', 'ready', 'completed', 'cancelled'] as $s): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Payment</label><select class="form-select" name="pay"><option value="">All</option>
      <?php foreach (['unpaid', 'paid', 'refunded'] as $s): ?><option value="<?= $s ?>" <?= $pay === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-2"><label class="form-label small mb-0">Search</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Order no. or customer"></div>
    <div class="col-12 col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit">Filter</button><a class="btn btn-outline-secondary" href="<?= url('pages/orders.php') ?>">Reset</a></div>
  </div>
</form>
<div class="card"><div class="card-body p-0" id="ordersBox"><?php orders_table($rows, $readyCount); ?></div>
  <div class="card-footer bg-white"><?= pagination_html($total, $page, $per) ?></div></div>
<?php if (can('pos')): ?><a href="<?= url('pages/pos.php') ?>" class="btn btn-primary mt-3 no-print"><i class="bi bi-cart-plus"></i> New order</a><?php endif; ?>
<?php page_footer('<script>autoRefresh("#ordersBox", 15);</script>');
