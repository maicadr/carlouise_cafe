<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('tickets');
$id = (int)get('id');
$o = db_one('SELECT o.*, t.name AS table_name, u.full_name AS cashier FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id LEFT JOIN users u ON u.id = o.created_by WHERE o.id = ?', [$id]);
if (!$o) { http_response_code(404); exit('Order not found.'); }
$items = order_items($id);
audit('ticket_printed', 'order', $id, $o['order_no']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Kitchen ticket <?= e($o['order_no']) ?></title>
<link rel="stylesheet" href="<?= url('assets/vendor/bootstrap/bootstrap.min.css') ?>"><link rel="stylesheet" href="<?= url('assets/css/style.css') ?>"></head>
<body class="bg-light"><div class="text-center p-2 no-print">
  <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print ticket</button>
  <button class="btn btn-outline-secondary" onclick="closeSlip('<?= e(url('pages/order_view.php?id=' . $id)) ?>')">Close</button><script>function closeSlip(back){window.close();setTimeout(function(){location.href=back;},250);}</script>
  <div class="small text-muted mt-1">Opens your browser's print dialog - choose your receipt or standard printer.</div></div>
<div class="slip">
  <h1>KITCHEN ORDER</h1>
  <div class="c big"><?= e($o['order_no']) ?></div>
  <div class="c"><?= e(ORDER_TYPES[$o['order_type']]) ?><?php if ($o['table_name']): ?> — <strong><?= e($o['table_name']) ?></strong><?php endif; ?></div>
  <div class="c">Received: <?= e(fmt_dt($o['created_at'], 'M j, Y g:i A')) ?></div>
  <?php if ($o['customer_name']): ?><div class="c">Customer: <?= e($o['customer_name']) ?></div><?php endif; ?>
  <?php if ($o['delivery_address']): ?><div class="c">Deliver to: <?= e($o['delivery_address']) ?></div><?php endif; ?>
  <hr>
  <table><?php foreach ($items as $it): ?>
    <tr><td style="width:12mm"><strong><?= (int)$it['quantity'] ?> x</strong></td><td><?= e($it['product_name']) ?><?php if ($it['item_notes']): ?><br><em>&gt; <?= e($it['item_notes']) ?></em><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
  <?php if ($o['notes']): ?><hr><strong>NOTES:</strong><br><?= nl2br(e($o['notes'])) ?><?php endif; ?>
  <hr><div class="c">Cashier: <?= e($o['cashier']) ?></div>
</div>
<link rel="stylesheet" href="<?= url('assets/vendor/icons/bootstrap-icons.min.css') ?>">
<?php if (get('print') === '1'): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),300));</script><?php endif; ?>
</body></html>