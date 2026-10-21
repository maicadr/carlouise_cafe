<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('sales');
$from = valid_date(get('from'), date('Y-m-d')); $to = valid_date(get('to'), date('Y-m-d'));
$method = (int)get('method'); $cashier = (int)get('cashier'); $q = get('q');
// Sales = recorded payments that are still "paid" (refunded/cancelled are excluded)
$where = ["p.status = 'paid'", 'p.paid_at >= ?', 'p.paid_at < ?']; $params = [day_start($from), day_end_exclusive($to)];
if ($method) { $where[] = 'p.payment_method_id = ?'; $params[] = $method; }
if ($cashier) { $where[] = 'p.cashier_id = ?'; $params[] = $cashier; }
if ($q !== '') { $where[] = '(p.transaction_no LIKE ? OR o.order_no LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
$w = implode(' AND ', $where);
[$off, $per, $page] = paginate_params(20);
$sum = db_one("SELECT COUNT(*) n, COALESCE(SUM(p.amount_due),0) total FROM payments p JOIN orders o ON o.id = p.order_id WHERE $w", $params);
$rows = db_all("SELECT p.*, o.order_no, o.order_type, u.full_name AS cashier,
   (SELECT GROUP_CONCAT(CONCAT(oi.quantity, ' x ', oi.product_name) ORDER BY oi.id SEPARATOR ', ') FROM order_items oi WHERE oi.order_id = o.id) AS items
   FROM payments p JOIN orders o ON o.id = p.order_id LEFT JOIN users u ON u.id = p.cashier_id WHERE $w ORDER BY p.paid_at DESC LIMIT $per OFFSET $off", $params);
$methods = db_all('SELECT id, name FROM payment_methods ORDER BY id');
$cashiers = db_all("SELECT id, full_name FROM users WHERE role IN ('cashier','admin') ORDER BY full_name");
page_header('Sales', 'sales');
?>
<form class="card card-body mb-3" method="get"><div class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label small mb-0">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Method</label><select class="form-select" name="method"><option value="0">All</option><?php foreach ($methods as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $method === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Cashier</label><select class="form-select" name="cashier"><option value="0">All</option><?php foreach ($cashiers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cashier === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Search</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Txn / order no."></div>
  <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button><a class="btn btn-outline-secondary" href="<?= url('pages/sales.php') ?>">Reset</a></div>
</div></form>
<div class="row g-3 mb-3"><div class="col-6 col-md-3"><div class="stat-card good"><div class="label">Net sales (filtered)</div><div class="value"><?= peso($sum['total']) ?></div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="label">Transactions</div><div class="value"><?= (int)$sum['n'] ?></div></div></div>
  <div class="col-12 col-md-6 small text-muted align-self-center">Sales count only payments that are still paid. Cancelled orders and refunded payments are excluded.</div></div>
<div class="card"><div class="card-body p-0">
<?php if (!$rows): echo empty_state('graph-up-arrow', 'No sales in this period', 'Sales appear here once payments are recorded.'); else: ?>
<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Transaction</th><th>Date &amp; time</th><th>Order</th><th>Items</th><th>Method</th><th class="text-end">Total</th><th>Cashier</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="fw-semibold"><?= e($r['transaction_no']) ?></td><td><?= e(fmt_dt($r['paid_at'], 'M j, g:i A')) ?></td>
<td><a href="<?= url('pages/order_view.php?id=' . (int)$r['order_id']) ?>"><?= e($r['order_no']) ?></a></td><td class="small" style="max-width:320px"><?= e($r['items']) ?></td>
<td><?= e($r['method_name']) ?></td><td class="text-end"><?= peso($r['amount_due']) ?></td><td><?= e($r['cashier']) ?></td>
<td><a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= url('pages/receipt.php?id=' . (int)$r['id']) ?>">Receipt</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div><div class="card-footer bg-white"><?= pagination_html((int)$sum['n'], $page, $per) ?></div></div>
<?php page_footer();
