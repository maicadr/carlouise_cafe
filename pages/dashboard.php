<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('dashboard');
$role = user_role();
$showSales = in_array($role, ['admin', 'cashier'], true);
$showOrders = $role !== 'inventory';
$today = date('Y-m-d'); $t0 = day_start($today); $t1 = day_end_exclusive($today);

$salesToday = (float)db_val("SELECT COALESCE(SUM(amount_due),0) FROM payments WHERE status='paid' AND paid_at >= ? AND paid_at < ?", [$t0, $t1]);
$ordersToday = (int)db_val("SELECT COUNT(*) FROM orders WHERE status <> 'cancelled' AND created_at >= ? AND created_at < ?", [$t0, $t1]);
$pending = (int)db_val("SELECT COUNT(*) FROM orders WHERE status='pending'");
$preparing = (int)db_val("SELECT COUNT(*) FROM orders WHERE status='preparing'");
$ready = (int)db_val("SELECT COUNT(*) FROM orders WHERE status='ready'");
$completedToday = (int)db_val("SELECT COUNT(*) FROM orders WHERE status='completed' AND completed_at >= ? AND completed_at < ?", [$t0, $t1]);
$products = (int)db_val('SELECT COUNT(*) FROM products WHERE is_active = 1 AND is_available = 1');
$lowRows = low_stock_rows(null, 8);
$lowCount = (int)db_val('SELECT COUNT(*) FROM inventory_stock s JOIN inventory_items i ON i.id = s.item_id AND i.is_active = 1 WHERE s.min_threshold > 0 AND s.quantity <= s.min_threshold');

$locSummary = db_all('SELECT l.id, l.name, COUNT(i.id) AS items,
    COALESCE(SUM(s.min_threshold > 0 AND s.quantity <= s.min_threshold AND s.quantity > 0),0) AS low,
    COALESCE(SUM(s.min_threshold > 0 AND s.quantity <= 0),0) AS outs
  FROM inventory_locations l LEFT JOIN inventory_stock s ON s.location_id = l.id LEFT JOIN inventory_items i ON i.id = s.item_id AND i.is_active = 1
  GROUP BY l.id, l.name ORDER BY l.id');

$chart = null;
if ($showSales) {
    $daily = []; for ($i = 13; $i >= 0; $i--) $daily[date('Y-m-d', strtotime("-$i day"))] = 0;
    foreach (db_all("SELECT DATE(paid_at) d, SUM(amount_due) s FROM payments WHERE status='paid' AND paid_at >= ? GROUP BY d", [day_start(date('Y-m-d', strtotime('-13 day')))]) as $r) $daily[$r['d']] = (float)$r['s'];
    $weekly = []; $mon = strtotime('monday this week');
    for ($i = 7; $i >= 0; $i--) $weekly[date('Y-m-d', strtotime("-$i week", $mon))] = 0;
    foreach (db_all("SELECT DATE_SUB(DATE(paid_at), INTERVAL WEEKDAY(paid_at) DAY) w, SUM(amount_due) s FROM payments WHERE status='paid' AND paid_at >= ? GROUP BY w", [date('Y-m-d', strtotime('-7 week', $mon)) . ' 00:00:00']) as $r) $weekly[$r['w']] = (float)$r['s'];
    $monthly = []; for ($i = 11; $i >= 0; $i--) $monthly[date('Y-m', strtotime(date('Y-m-01') . " -$i month"))] = 0;
    foreach (db_all("SELECT DATE_FORMAT(paid_at,'%Y-%m') m, SUM(amount_due) s FROM payments WHERE status='paid' AND paid_at >= ? GROUP BY m", [date('Y-m-01', strtotime(date('Y-m-01') . ' -11 month')) . ' 00:00:00']) as $r) $monthly[$r['m']] = (float)$r['s'];
    $chart = [
        'daily' => ['labels' => array_map(fn($d) => date('M j', strtotime($d)), array_keys($daily)), 'data' => array_values($daily)],
        'weekly' => ['labels' => array_map(fn($d) => 'Wk of ' . date('M j', strtotime($d)), array_keys($weekly)), 'data' => array_values($weekly)],
        'monthly' => ['labels' => array_map(fn($d) => date('M Y', strtotime($d . '-01')), array_keys($monthly)), 'data' => array_values($monthly)],
    ];
    $recentTx = db_all("SELECT p.id, p.transaction_no, p.method_name, p.amount_due, p.paid_at, o.order_no FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.status='paid' ORDER BY p.paid_at DESC LIMIT 8");
}
if ($showOrders) {
    $recentOrders = db_all('SELECT o.id, o.order_no, o.order_type, o.total, o.status, o.payment_status, o.created_at, t.name AS table_name FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id ORDER BY o.created_at DESC LIMIT 8');
}
page_header('Dashboard', 'dashboard');

function stat_card(string $label, string $value, string $cls = '', string $href = ''): string {
    $card = '<div class="stat-card ' . $cls . '"><div class="label">' . e($label) . '</div><div class="value">' . $value . '</div></div>';
    if ($href) $card = '<a class="text-decoration-none d-block" href="' . e($href) . '">' . $card . '</a>';
    return '<div class="col-6 col-md-4 col-xl-3">' . $card . '</div>';
}
?>
<div class="row g-3 mb-3">
  <?php if ($showSales) echo stat_card("Today's sales", peso($salesToday), 'good', url('pages/sales.php')); ?>
  <?php if ($showOrders): echo stat_card('Orders today', (string)$ordersToday, '', url('pages/orders.php'));
    echo stat_card('Pending orders', (string)$pending, $pending ? 'warn' : '');
    echo stat_card('Being prepared', (string)$preparing);
    echo stat_card('Ready to serve', (string)$ready, $ready ? 'good' : '');
    echo stat_card('Completed today', (string)$completedToday); endif; ?>
  <?php echo stat_card('Low-stock items', (string)$lowCount, $lowCount ? 'danger' : '', url('pages/inventory.php?status=low')); ?>
  <?php echo stat_card('Products available', (string)$products); ?>
</div>

<?php if ($showSales): ?>
<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">Sales performance
  <div class="btn-group btn-group-sm" id="rangeBtns"><button class="btn btn-dark" data-r="daily">Daily</button><button class="btn btn-outline-dark" data-r="weekly">Weekly</button><button class="btn btn-outline-dark" data-r="monthly">Monthly</button></div></div>
  <div class="card-body"><div style="height:280px"><canvas id="salesChart"></canvas></div></div></div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <?php if ($showSales): ?><div class="col-lg-6"><div class="card h-100"><div class="card-header">Recent sales transactions</div><div class="card-body p-0">
    <?php if (!$recentTx): echo empty_state('cash-coin', 'No sales yet'); else: ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Txn</th><th>Order</th><th>Method</th><th class="text-end">Amount</th><th>Time</th></tr></thead><tbody>
    <?php foreach ($recentTx as $r): ?><tr><td><?= e($r['transaction_no']) ?></td><td><?= e($r['order_no']) ?></td><td><?= e($r['method_name']) ?></td><td class="text-end"><?= peso($r['amount_due']) ?></td><td><?= e(fmt_dt($r['paid_at'], 'M j g:i A')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div></div><?php endif; ?>
  <?php if ($showOrders): ?><div class="col-lg-6"><div class="card h-100"><div class="card-header">Recent orders</div><div class="card-body p-0">
    <?php if (!$recentOrders): echo empty_state('receipt', 'No orders yet'); else: ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Order</th><th>Type</th><th class="text-end">Total</th><th>Status</th><th>Payment</th></tr></thead><tbody>
    <?php foreach ($recentOrders as $r): ?><tr><td><?= can('orders') ? '<a href="' . url('pages/order_view.php?id=' . (int)$r['id']) . '">' . e($r['order_no']) . '</a>' : e($r['order_no']) ?></td><td><?= e(ORDER_TYPES[$r['order_type']]) ?><?= $r['table_name'] ? ' · ' . e($r['table_name']) : '' ?></td><td class="text-end"><?= peso($r['total']) ?></td><td><?= status_badge($r['status']) ?></td><td><?= status_badge($r['payment_status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div></div><?php endif; ?>
</div>

<div class="row g-3">
  <div class="col-lg-5"><div class="card h-100"><div class="card-header">Inventory summary by location</div><div class="card-body p-0"><table class="table mb-0"><thead><tr><th>Location</th><th class="text-end">Items</th><th class="text-end">Low</th><th class="text-end">Out</th></tr></thead><tbody>
    <?php foreach ($locSummary as $l): ?><tr><td><?= e($l['name']) ?></td><td class="text-end"><?= (int)$l['items'] ?></td><td class="text-end"><?= (int)$l['low'] ? '<span class="badge bg-warning text-dark">' . (int)$l['low'] . '</span>' : '0' ?></td><td class="text-end"><?= (int)$l['outs'] ? '<span class="badge bg-danger">' . (int)$l['outs'] . '</span>' : '0' ?></td></tr><?php endforeach; ?></tbody></table></div></div></div>
  <div class="col-lg-7"><div class="card h-100"><div class="card-header d-flex justify-content-between">Low-stock alerts <a class="small" href="<?= url('pages/inventory.php?status=low') ?>">View all</a></div><div class="card-body p-0">
    <?php if (!$lowRows): echo empty_state('check-circle', 'All stock levels are healthy'); else: ?><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Item</th><th>Location</th><th class="text-end">On hand</th><th class="text-end">Minimum</th><th></th></tr></thead><tbody>
    <?php foreach ($lowRows as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['location_name']) ?></td><td class="text-end"><?= qty($r['quantity']) . ' ' . e($r['unit']) ?></td><td class="text-end"><?= qty($r['min_threshold']) ?></td><td><?= status_badge(stock_status((float)$r['quantity'], (float)$r['min_threshold'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div></div></div>
</div>
<?php
$js = '<script>setTimeout(function r(){ if(document.querySelector(".modal.show")) return setTimeout(r,60000); location.reload(); },60000);</script>';
if ($showSales) {
    $js .= '<script src="' . url('assets/vendor/chart/chart.umd.js') . '"></script><script>
const D=' . json_encode($chart) . ';
const fmt=v=>"₱"+Number(v).toLocaleString("en-PH");
const ch=new Chart(document.getElementById("salesChart"),{type:"bar",data:{labels:D.daily.labels,datasets:[{label:"Net sales",data:D.daily.data,backgroundColor:"#2f6b5a",borderRadius:4}]},
 options:{maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>fmt(c.parsed.y)}}},scales:{y:{beginAtZero:true,ticks:{callback:fmt}}}}});
document.getElementById("rangeBtns").addEventListener("click",e=>{const b=e.target.closest("[data-r]");if(!b)return;
 document.querySelectorAll("#rangeBtns .btn").forEach(x=>{x.classList.toggle("btn-dark",x===b);x.classList.toggle("btn-outline-dark",x!==b)});
 ch.data.labels=D[b.dataset.r].labels;ch.data.datasets[0].data=D[b.dataset.r].data;ch.update();});
</script>';
}
page_footer($js);
