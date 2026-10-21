<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('payments');
if (is_post()) {
    csrf_verify();
    try {
        if (post('action') === 'refund') {
            if (!can('refunds')) throw new UserError('Only the owner/administrator can refund payments.');
            refund_payment(post_int('payment_id'), post('reason'));
            flash('success', 'Payment refunded.');
        }
    } catch (UserError $e) { flash('danger', $e->getMessage()); }
    redirect_self();
}
$from = valid_date(get('from'), date('Y-m-d')); $to = valid_date(get('to'), date('Y-m-d'));
$status = get('status'); $method = (int)get('method'); $q = get('q');
$where = ['p.paid_at >= ?', 'p.paid_at < ?']; $params = [day_start($from), day_end_exclusive($to)];
if (in_array($status, ['paid', 'refunded'], true)) { $where[] = 'p.status = ?'; $params[] = $status; }
if ($method) { $where[] = 'p.payment_method_id = ?'; $params[] = $method; }
if ($q !== '') { $where[] = '(p.transaction_no LIKE ? OR o.order_no LIKE ? OR p.reference_no LIKE ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$w = implode(' AND ', $where);
[$off, $per, $page] = paginate_params(20);
$total = (int)db_val("SELECT COUNT(*) FROM payments p JOIN orders o ON o.id = p.order_id WHERE $w", $params);
$rows = db_all("SELECT p.*, o.order_no, o.status AS order_status, u.full_name AS cashier FROM payments p JOIN orders o ON o.id = p.order_id LEFT JOIN users u ON u.id = p.cashier_id WHERE $w ORDER BY p.paid_at DESC LIMIT $per OFFSET $off", $params);
$methods = db_all('SELECT id, name FROM payment_methods ORDER BY id');
page_header('Payments', 'payments');
?>
<form class="card card-body mb-3" method="get"><div class="row g-2 align-items-end">
  <div class="col-6 col-md-2"><label class="form-label small mb-0">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Status</label><select class="form-select" name="status"><option value="">All</option><option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Paid</option><option value="refunded" <?= $status === 'refunded' ? 'selected' : '' ?>>Refunded</option></select></div>
  <div class="col-6 col-md-2"><label class="form-label small mb-0">Method</label><select class="form-select" name="method"><option value="0">All</option><?php foreach ($methods as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $method === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2"><label class="form-label small mb-0">Search</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Txn / order / ref"></div>
  <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button><a class="btn btn-outline-secondary" href="<?= url('pages/payments.php') ?>">Reset</a></div>
</div></form>
<div class="card"><div class="card-body p-0">
<?php if (!$rows): echo empty_state('cash-coin', 'No payment records', 'Payments are recorded from the order screen.'); else: ?>
<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Transaction</th><th>Date &amp; time</th><th>Order</th><th>Method</th><th class="text-end">Due</th><th class="text-end">Received</th><th class="text-end">Change</th><th>Status</th><th>Cashier</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="fw-semibold"><?= e($r['transaction_no']) ?></td><td><?= e(fmt_dt($r['paid_at'], 'M j, g:i A')) ?></td>
<td><a href="<?= url('pages/order_view.php?id=' . (int)$r['order_id']) ?>"><?= e($r['order_no']) ?></a></td><td><?= e($r['method_name']) ?><?= $r['reference_no'] ? '<div class="small text-muted">Ref ' . e($r['reference_no']) . '</div>' : '' ?></td>
<td class="text-end"><?= peso($r['amount_due']) ?></td><td class="text-end"><?= peso($r['amount_received']) ?></td><td class="text-end"><?= peso($r['change_amount']) ?></td>
<td><?= status_badge($r['status']) ?><?= $r['status'] === 'refunded' ? '<div class="small text-muted">' . e($r['refund_reason']) . '</div>' : '' ?></td><td><?= e($r['cashier']) ?></td>
<td class="text-nowrap"><a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= url('pages/receipt.php?id=' . (int)$r['id']) ?>">Receipt</a>
<?php if ($r['status'] === 'paid' && $r['order_status'] === 'completed' && can('refunds')): ?><button class="btn btn-sm btn-outline-danger" data-fill="#refundModal" data-bs-toggle="modal" data-bs-target="#refundModal" data-json="<?= e(json_encode(['payment_id' => $r['id'], 'txn' => $r['transaction_no']])) ?>">Refund</button><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?>
</div><div class="card-footer bg-white"><?= pagination_html($total, $page, $per) ?></div></div>
<?php if (can('refunds')): ?>
<div class="modal fade" id="refundModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" data-confirm="Refund this payment? This is recorded in the audit log."><?= csrf_field() ?><input type="hidden" name="action" value="refund"><input type="hidden" name="payment_id" value="0">
<div class="modal-header"><h5 class="modal-title">Refund <span data-text="txn"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><p class="small text-muted">Only completed orders can be refunded here. For open orders, use Cancel order.</p><label class="form-label">Reason (required)</label><input class="form-control" name="reason" required minlength="3" maxlength="255"></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-danger">Refund</button></div></form></div></div><?php endif; ?>
<?php page_footer();
