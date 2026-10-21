<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('orders');
$id = (int)get('id');
$order = db_one('SELECT o.*, t.name AS table_name, u.full_name AS creator FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id LEFT JOIN users u ON u.id = o.created_by WHERE o.id = ?', [$id]);
if (!$order) { flash('danger', 'Order not found.'); redirect('pages/orders.php'); }

if (is_post()) {
    csrf_verify();
    $action = post('action');
    try {
        if ($action === 'status') {
            set_order_status($id, post('status'));
            flash('success', 'Order marked as ' . post('status') . '.');
        } elseif ($action === 'pay') {
            if (!can('take_payment')) throw new UserError('Only cashiers record payments.');
            try {
                $pid = record_payment($id, post_int('method_id'), post('received'), post('reference'));
                flash('success', 'Payment recorded.');
                redirect('pages/receipt.php?id=' . $pid);
            } catch (UserError $e) {
                audit('payment_failed', 'order', $id, $e->getMessage());
                throw $e;
            }
        } elseif ($action === 'cancel') {
            $authId = user_id();
            if (!can('refunds')) {   // cashier needs an administrator's credentials
                $authId = verify_admin_authorization(post('auth_user'), $_POST['auth_pass'] ?? '');
            }
            cancel_order($id, post('reason'), $authId);
            flash('success', 'Order cancelled.' . ($order['payment_status'] === 'paid' ? ' The payment was marked as refunded.' : ''));
        } elseif ($action === 'refund') {
            if (!can('refunds')) throw new UserError('Only the owner/administrator can refund payments.');
            $pay = db_one("SELECT id FROM payments WHERE order_id = ? AND status = 'paid'", [$id]);
            if (!$pay) throw new UserError('No paid payment found for this order.');
            refund_payment((int)$pay['id'], post('reason'));
            flash('success', 'Payment refunded.');
        }
    } catch (UserError $e) {
        if ($action === 'cancel') audit('cancel_denied', 'order', $id, $e->getMessage());
        flash('danger', $e->getMessage());
    }
    redirect_self();
}

$items = order_items($id);
$payments = db_all('SELECT p.*, u.full_name AS cashier FROM payments p LEFT JOIN users u ON u.id = p.cashier_id WHERE p.order_id = ? ORDER BY p.id', [$id]);
$methods = db_all('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY id');
$next = STATUS_FLOW[$order['status']] ?? null;
$canAdvance = $next && (user_role() === 'kitchen' || (user_role() === 'cashier' && $next === 'completed'));
$nextLabel = ['preparing' => 'Start preparing', 'ready' => 'Mark as ready', 'completed' => 'Mark as served / completed'][$next] ?? '';

page_header('Order ' . $order['order_no'], 'orders');
?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">
      <span><?= e($order['order_no']) ?> <?= status_badge($order['status']) ?> <?= status_badge($order['payment_status']) ?></span>
      <span class="no-print"><a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= url('pages/ticket.php?id=' . $id . '&print=1') ?>"><i class="bi bi-printer"></i> Kitchen ticket</a></span></div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4">Type</dt><dd class="col-sm-8"><?= e(ORDER_TYPES[$order['order_type']]) ?><?= $order['table_name'] ? ' · ' . e($order['table_name']) : '' ?></dd>
          <dt class="col-sm-4">Received</dt><dd class="col-sm-8"><?= e(fmt_dt($order['created_at'])) ?> by <?= e($order['creator']) ?></dd>
          <?php if ($order['customer_name']): ?><dt class="col-sm-4">Customer</dt><dd class="col-sm-8"><?= e($order['customer_name']) ?></dd><?php endif; ?>
          <?php if ($order['delivery_address']): ?><dt class="col-sm-4">Address</dt><dd class="col-sm-8"><?= e($order['delivery_address']) ?></dd><?php endif; ?>
          <?php if ($order['notes']): ?><dt class="col-sm-4">Notes</dt><dd class="col-sm-8"><?= nl2br(e($order['notes'])) ?></dd><?php endif; ?>
          <?php if ($order['preparing_at']): ?><dt class="col-sm-4">Preparing</dt><dd class="col-sm-8"><?= e(fmt_dt($order['preparing_at'])) ?></dd><?php endif; ?>
          <?php if ($order['ready_at']): ?><dt class="col-sm-4">Ready</dt><dd class="col-sm-8"><?= e(fmt_dt($order['ready_at'])) ?></dd><?php endif; ?>
          <?php if ($order['completed_at']): ?><dt class="col-sm-4">Completed</dt><dd class="col-sm-8"><?= e(fmt_dt($order['completed_at'])) ?></dd><?php endif; ?>
          <?php if ($order['status'] === 'cancelled'): ?><dt class="col-sm-4 text-danger">Cancelled</dt><dd class="col-sm-8"><?= e(fmt_dt($order['cancelled_at'])) ?> — <?= e($order['cancel_reason']) ?></dd><?php endif; ?>
        </dl>
      </div>
      <div class="table-responsive"><table class="table mb-0"><thead><tr><th>Item</th><th class="text-end">Price</th><th class="text-end">Qty</th><th class="text-end">Subtotal</th></tr></thead><tbody>
        <?php foreach ($items as $it): ?><tr><td><?= e($it['product_name']) ?><?php if ($it['item_notes']): ?><div class="small text-muted"><i class="bi bi-chat-left-text"></i> <?= e($it['item_notes']) ?></div><?php endif; ?></td>
          <td class="text-end"><?= peso($it['unit_price']) ?></td><td class="text-end"><?= (int)$it['quantity'] ?></td><td class="text-end"><?= peso($it['line_total']) ?></td></tr><?php endforeach; ?>
        </tbody><tfoot><tr><th colspan="3" class="text-end">Total</th><th class="text-end fs-5"><?= peso($order['total']) ?></th></tr></tfoot></table></div>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
      <?php if ($canAdvance) echo action_form('status', ['status' => $next], $nextLabel, 'btn btn-primary', '', 'arrow-right-circle'); ?>
      <?php if (in_array($order['status'], ['pending', 'preparing', 'ready'], true) && can('orders')): ?>
        <button class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal"><i class="bi bi-x-circle"></i> Cancel order</button><?php endif; ?>
      <a href="<?= url('pages/orders.php') ?>" class="btn btn-outline-secondary">Back to orders</a>
    </div>
  </div>

  <div class="col-lg-5" id="pay">
    <div class="card"><div class="card-header">Payment</div><div class="card-body">
    <?php foreach ($payments as $p): ?>
      <div class="border rounded p-2 mb-2 <?= $p['status'] === 'refunded' ? 'bg-light' : '' ?>">
        <div class="d-flex justify-content-between"><strong><?= e($p['transaction_no']) ?></strong><?= status_badge($p['status']) ?></div>
        <div class="small text-muted"><?= e($p['method_name']) ?> · <?= e(fmt_dt($p['paid_at'])) ?> · <?= e($p['cashier']) ?><?= $p['reference_no'] ? ' · Ref ' . e($p['reference_no']) : '' ?></div>
        <div>Paid <?= peso($p['amount_received']) ?> · Change <?= peso($p['change_amount']) ?></div>
        <?php if ($p['status'] === 'refunded'): ?><div class="small text-danger">Refunded <?= e(fmt_dt($p['refunded_at'])) ?>: <?= e($p['refund_reason']) ?></div><?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary mt-1 no-print" target="_blank" href="<?= url('pages/receipt.php?id=' . (int)$p['id'] . '&print=1') ?>"><i class="bi bi-printer"></i> Receipt</a>
      </div>
    <?php endforeach; ?>
    <?php if ($order['payment_status'] === 'unpaid' && $order['status'] !== 'cancelled' && can('take_payment')): ?>
      <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="pay">
        <div class="mb-2 fs-5">Amount due: <strong><?= peso($order['total']) ?></strong></div>
        <div class="mb-2"><label class="form-label">Payment method</label><select class="form-select" name="method_id" id="method" required>
          <?php foreach ($methods as $m): ?><option value="<?= (int)$m['id'] ?>" data-cash="<?= (int)$m['is_cash'] ?>" data-ref="<?= (int)$m['requires_reference'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-2" id="cashBox"><label class="form-label">Amount received</label><input class="form-control form-control-lg" name="received" id="received" inputmode="decimal" value="<?= e(number_format((float)$order['total'], 2, '.', '')) ?>">
          <div class="mt-1">Change: <strong id="change">₱0.00</strong></div></div>
        <div class="mb-2 d-none" id="refBox"><label class="form-label">Reference no.</label><input class="form-control" name="reference" maxlength="60"></div>
        <button class="btn btn-primary btn-lg w-100" type="submit"><i class="bi bi-cash-coin"></i> Record payment</button>
      </form>
    <?php elseif ($order['status'] === 'cancelled'): ?><p class="text-muted mb-0">Cancelled orders cannot be paid.</p>
    <?php elseif (!$payments): ?><p class="text-muted mb-0"><?= $order['payment_status'] === 'unpaid' ? 'Not paid yet (the cashier records payment).' : 'No payment recorded.' ?></p><?php endif; ?>
    <?php if ($order['payment_status'] === 'paid' && $order['status'] === 'completed' && can('refunds')): ?>
      <button class="btn btn-outline-danger w-100 mt-2" data-bs-toggle="modal" data-bs-target="#refundModal"><i class="bi bi-arrow-counterclockwise"></i> Refund payment</button><?php endif; ?>
    </div></div>
  </div>
</div>

<div class="modal fade" id="cancelModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" data-confirm="Cancel order <?= e($order['order_no']) ?>? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
  <div class="modal-header"><h5 class="modal-title">Cancel order <?= e($order['order_no']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <?php if ($order['payment_status'] === 'paid'): ?><div class="alert alert-warning py-2 small">This order is paid. Cancelling will mark the payment as refunded.</div><?php endif; ?>
    <label class="form-label">Reason (required)</label><input class="form-control mb-3" name="reason" required minlength="3" maxlength="255">
    <?php if (!can('refunds')): ?>
      <div class="border rounded p-2 bg-light"><div class="small fw-semibold mb-2">Administrator authorization required</div>
        <input class="form-control mb-2" name="auth_user" placeholder="Administrator username" required autocomplete="off">
        <input class="form-control" type="password" name="auth_pass" placeholder="Administrator password" required autocomplete="new-password"></div>
    <?php endif; ?>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep order</button><button class="btn btn-danger" type="submit">Cancel order</button></div></form></div></div>
<?php if (can('refunds')): ?>
<div class="modal fade" id="refundModal" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content" data-confirm="Refund this payment?"><?= csrf_field() ?><input type="hidden" name="action" value="refund">
  <div class="modal-header"><h5 class="modal-title">Refund payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><p class="small text-muted">The order stays completed (items were served) and the sale is excluded from sales totals.</p><label class="form-label">Reason (required)</label><input class="form-control" name="reason" required minlength="3" maxlength="255"></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button><button class="btn btn-danger" type="submit">Refund</button></div></form></div></div>
<?php endif; ?>
<?php
$js = '<script>(function(){const m=document.getElementById("method");if(!m)return;const total=' . json_encode((float)$order['total']) . ';
const rec=document.getElementById("received"),ch=document.getElementById("change");
function upd(){const o=m.options[m.selectedIndex];const cash=o.dataset.cash==="1";document.getElementById("cashBox").classList.toggle("d-none",!cash);
document.getElementById("refBox").classList.toggle("d-none",o.dataset.ref!=="1");const c=(parseFloat(rec.value)||0)-total;ch.textContent="₱"+Math.max(c,0).toFixed(2);ch.className=c<0?"text-danger":"";}
m.addEventListener("change",upd);rec.addEventListener("input",upd);upd();if(location.hash==="#pay")document.getElementById("pay").scrollIntoView();})();</script>';
page_footer($js);
