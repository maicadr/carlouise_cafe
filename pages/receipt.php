<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('payments');
$id = (int)get('id');
$p = db_one('SELECT p.*, o.order_no, o.order_type, o.customer_name, o.total, t.name AS table_name, u.full_name AS cashier
             FROM payments p JOIN orders o ON o.id = p.order_id LEFT JOIN dining_tables t ON t.id = o.table_id LEFT JOIN users u ON u.id = p.cashier_id WHERE p.id = ?', [$id]);
if (!$p) { http_response_code(404); exit('Payment not found.'); }
$items = order_items((int)$p['order_id']);
audit('receipt_printed', 'payment', $id, $p['transaction_no']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?= e($p['transaction_no']) ?></title>
<link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>"><link rel="stylesheet" href="<?= url('assets/css/style.css') ?>"></head>
<body class="bg-light"><div class="text-center p-2 no-print">
  <button class="btn btn-primary" onclick="window.print()">Print receipt</button>
  <button class="btn btn-outline-secondary" onclick="closeSlip('<?= e(url('pages/order_view.php?id=' . (int)$p['order_id'])) ?>')">Close</button></div><script>function closeSlip(back){window.close();setTimeout(function(){location.href=back;},250);}</script>
<div class="slip">
  <h1><?= e(setting('cafe_name', 'CarLouise Cafe')) ?></h1>
  <div class="c"><?= e(setting('cafe_address')) ?><?= setting('cafe_phone') ? '<br>' . e(setting('cafe_phone')) : '' ?></div>
  <hr>
  <div>Transaction: <strong><?= e($p['transaction_no']) ?></strong></div>
  <div>Order: <?= e($p['order_no']) ?> (<?= e(ORDER_TYPES[$p['order_type']]) ?><?= $p['table_name'] ? ', ' . e($p['table_name']) : '' ?>)</div>
  <div>Date: <?= e(fmt_dt($p['paid_at'], 'M j, Y g:i A')) ?></div>
  <div>Cashier: <?= e($p['cashier']) ?></div>
  <hr>
  <table><?php foreach ($items as $it): ?>
    <tr><td colspan="2"><?= e($it['product_name']) ?></td></tr>
    <tr><td>&nbsp;&nbsp;<?= (int)$it['quantity'] ?> x <?= number_format((float)$it['unit_price'], 2) ?></td><td class="r"><?= number_format((float)$it['line_total'], 2) ?></td></tr>
  <?php endforeach; ?></table>
  <hr>
  <table>
    <tr><td><strong>TOTAL</strong></td><td class="r"><strong><?= peso($p['amount_due']) ?></strong></td></tr>
    <tr><td><?= e($p['method_name']) ?></td><td class="r"><?= peso($p['amount_received']) ?></td></tr>
    <?php if ((float)$p['change_amount'] > 0): ?><tr><td>Change</td><td class="r"><?= peso($p['change_amount']) ?></td></tr><?php endif; ?>
    <?php if ($p['reference_no']): ?><tr><td>Ref</td><td class="r"><?= e($p['reference_no']) ?></td></tr><?php endif; ?>
  </table>
  <?php if ($p['status'] === 'refunded'): ?><hr><div class="c big">REFUNDED</div><?php endif; ?>
  <hr><div class="c"><?= e(setting('receipt_footer')) ?></div>
</div>
<?php if (get('print') === '1'): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script><?php endif; ?>
</body></html>