<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('reports');

$SALES = ['admin', 'cashier']; $INV = ['admin', 'inventory'];
$REPORTS = [
 'sales_day'   => ['Daily sales report', $SALES, ['from', 'to', 'method']],
 'sales_week'  => ['Weekly sales report', $SALES, ['from', 'to', 'method']],
 'sales_month' => ['Monthly sales report', $SALES, ['from', 'to', 'method']],
 'sales_range' => ['Sales by date range', $SALES, ['from', 'to', 'method']],
 'by_product'  => ['Sales by product', $SALES, ['from', 'to', 'product', 'category']],
 'best'        => ['Best-selling products', $SALES, ['from', 'to', 'category']],
 'orders_day'  => ['Number of orders per day', $SALES, ['from', 'to']],
 'methods'     => ['Payment method summary', $SALES, ['from', 'to']],
 'cancelled'   => ['Cancelled orders and refunds', $SALES, ['from', 'to']],
 'inv_summary' => ['Inventory summary by location', $INV, ['item', 'icat']],
 'low_stock'   => ['Low-stock report', $INV, ['location', 'icat']],
 'movements'   => ['Stock movement report', $INV, ['from', 'to', 'item', 'mtype', 'location']],
 'replenish'   => ['Stock replenishment history', $INV, ['from', 'to', 'item', 'location', 'rstatus']],
];
$allowed = array_filter($REPORTS, fn($r) => in_array(user_role(), $r[1], true));
$type = get('type', array_key_first($allowed));
if (!isset($allowed[$type])) { $type = array_key_first($allowed); }

// default date windows per report
$today = date('Y-m-d');
$defFrom = ['sales_day' => $today, 'sales_week' => date('Y-m-d', strtotime('monday this week -7 weeks')), 'sales_month' => date('Y-m-01', strtotime('-11 months'))][$type] ?? date('Y-m-01');
$from = valid_date(get('from'), $defFrom); $to = valid_date(get('to'), $today);
if ($from > $to) [$from, $to] = [$to, $from];
$f0 = day_start($from); $f1 = day_end_exclusive($to);
$method = (int)get('method'); $product = (int)get('product'); $category = (int)get('category'); $item = (int)get('item');
$icat = get('icat'); $location = (int)get('location'); $mtype = get('mtype'); $rstatus = get('rstatus');

/** Report builders return [cols => [[label,type]], rows, summary, chart, note]. type: text|money|num|date */
function r_sales(string $grp, string $f0, string $f1, int $method): array {
    $expr = ['day' => 'DATE(p.paid_at)', 'week' => 'DATE_SUB(DATE(p.paid_at), INTERVAL WEEKDAY(p.paid_at) DAY)', 'month' => "DATE_FORMAT(p.paid_at,'%Y-%m')"][$grp];
    $w = 'p.paid_at >= ? AND p.paid_at < ?'; $p = [$f0, $f1];
    if ($method) { $w .= ' AND p.payment_method_id = ?'; $p[] = $method; }
    $data = db_all("SELECT $expr AS period, COUNT(*) AS txns, SUM(p.amount_due) AS gross, COALESCE(SUM(CASE WHEN p.status='refunded' THEN p.amount_due END),0) AS refunds
                    FROM payments p WHERE $w GROUP BY period ORDER BY period", $p);
    $rows = []; $labels = []; $vals = []; $tg = $tr = $tt = 0;
    foreach ($data as $d) {
        $net = (float)$d['gross'] - (float)$d['refunds'];
        $lbl = $grp === 'day' ? date('D, M j, Y', strtotime($d['period'])) : ($grp === 'week' ? 'Week of ' . date('M j, Y', strtotime($d['period'])) : date('F Y', strtotime($d['period'] . '-01')));
        $rows[] = [$lbl, (int)$d['txns'], (float)$d['gross'], (float)$d['refunds'], $net];
        $labels[] = $lbl; $vals[] = $net; $tg += (float)$d['gross']; $tr += (float)$d['refunds']; $tt += (int)$d['txns'];
    }
    return ['cols' => [['Period', 'text'], ['Transactions', 'num'], ['Gross payments', 'money'], ['Refunded', 'money'], ['Net sales', 'money']], 'rows' => $rows,
        'summary' => ['Transactions' => $tt, 'Gross payments' => peso($tg), 'Refunded' => peso($tr), 'Net sales' => peso($tg - $tr)],
        'chart' => ['type' => 'bar', 'labels' => $labels, 'data' => $vals, 'label' => 'Net sales'],
        'note' => 'Net sales = payments recorded in the period minus payments refunded or cancelled. Unpaid orders are not sales.'];
}
function r_products(string $f0, string $f1, int $product, int $category, bool $best): array {
    $w = "p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ?"; $pr = [$f0, $f1];
    if ($product) { $w .= ' AND oi.product_id = ?'; $pr[] = $product; }
    if ($category) { $w .= ' AND pr.category_id = ?'; $pr[] = $category; }
    $data = db_all("SELECT oi.product_name, COALESCE(c.name,'-') AS category, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue
        FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN payments p ON p.order_id = o.id LEFT JOIN products pr ON pr.id = oi.product_id LEFT JOIN categories c ON c.id = pr.category_id
        WHERE $w GROUP BY oi.product_id, oi.product_name, c.name ORDER BY " . ($best ? 'qty DESC, revenue DESC LIMIT 10' : 'revenue DESC'), $pr);
    $rows = []; $labels = []; $vals = []; $tq = 0; $tr = 0;
    foreach ($data as $i => $d) { $rows[] = $best ? [$i + 1, $d['product_name'], $d['category'], (int)$d['qty'], (float)$d['revenue']] : [$d['product_name'], $d['category'], (int)$d['qty'], (float)$d['revenue']];
        $labels[] = $d['product_name']; $vals[] = $best ? (int)$d['qty'] : (float)$d['revenue']; $tq += (int)$d['qty']; $tr += (float)$d['revenue']; }
    return ['cols' => $best ? [['Rank', 'num'], ['Product', 'text'], ['Category', 'text'], ['Units sold', 'num'], ['Revenue', 'money']] : [['Product', 'text'], ['Category', 'text'], ['Units sold', 'num'], ['Revenue', 'money']],
        'rows' => $rows, 'summary' => ['Units sold' => $tq, 'Revenue' => peso($tr)], 'chart' => ['type' => 'bar', 'labels' => $labels, 'data' => $vals, 'label' => $best ? 'Units sold' : 'Revenue'],
        'note' => 'Counts only paid orders; cancelled and refunded orders are excluded.'];
}
function build_report(string $type, array $a): array {
    extract($a);
    switch ($type) {
        case 'sales_day': return r_sales('day', $f0, $f1, $method);
        case 'sales_range': return r_sales('day', $f0, $f1, $method);
        case 'sales_week': return r_sales('week', $f0, $f1, $method);
        case 'sales_month': return r_sales('month', $f0, $f1, $method);
        case 'by_product': return r_products($f0, $f1, $product, $category, false);
        case 'best': return r_products($f0, $f1, 0, $category, true);
        case 'orders_day':
            $d = db_all("SELECT DATE(created_at) d, COUNT(*) total, SUM(status='completed') completed, SUM(status='cancelled') cancelled, SUM(status IN ('pending','preparing','ready')) open_orders,
                         COALESCE(SUM(CASE WHEN status<>'cancelled' THEN total END),0) value FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY d ORDER BY d", [$f0, $f1]);
            $rows = array_map(fn($r) => [date('D, M j, Y', strtotime($r['d'])), (int)$r['total'], (int)$r['completed'], (int)$r['open_orders'], (int)$r['cancelled'], (float)$r['value']], $d);
            return ['cols' => [['Date', 'text'], ['Orders', 'num'], ['Completed', 'num'], ['Open', 'num'], ['Cancelled', 'num'], ['Order value (excl. cancelled)', 'money']], 'rows' => $rows,
                'summary' => ['Orders' => array_sum(array_column($rows, 1)), 'Cancelled' => array_sum(array_column($rows, 4))],
                'chart' => ['type' => 'line', 'labels' => array_column($rows, 0), 'data' => array_column($rows, 1), 'label' => 'Orders'], 'note' => ''];
        case 'methods':
            $d = db_all("SELECT method_name, SUM(status='paid') n_paid, COALESCE(SUM(CASE WHEN status='paid' THEN amount_due END),0) paid, SUM(status='refunded') n_ref, COALESCE(SUM(CASE WHEN status='refunded' THEN amount_due END),0) ref
                         FROM payments WHERE paid_at >= ? AND paid_at < ? GROUP BY method_name ORDER BY paid DESC", [$f0, $f1]);
            $rows = array_map(fn($r) => [$r['method_name'], (int)$r['n_paid'], (float)$r['paid'], (int)$r['n_ref'], (float)$r['ref']], $d);
            return ['cols' => [['Method', 'text'], ['Paid transactions', 'num'], ['Net sales', 'money'], ['Refunded transactions', 'num'], ['Refunded amount', 'money']], 'rows' => $rows,
                'summary' => ['Net sales' => peso(array_sum(array_column($rows, 2)))], 'chart' => ['type' => 'bar', 'labels' => array_column($rows, 0), 'data' => array_column($rows, 2), 'label' => 'Net sales'], 'note' => ''];
        case 'cancelled':
            $d = db_all("SELECT 'Cancelled order' AS kind, o.order_no AS ref, o.cancelled_at AS dt, o.total AS amount, o.cancel_reason AS reason, u.full_name AS by_user FROM orders o LEFT JOIN users u ON u.id = o.cancelled_by WHERE o.status='cancelled' AND o.cancelled_at >= ? AND o.cancelled_at < ?
                         UNION ALL SELECT 'Refund', p.transaction_no, p.refunded_at, p.amount_due, p.refund_reason, u2.full_name FROM payments p LEFT JOIN users u2 ON u2.id = p.refunded_by WHERE p.status='refunded' AND p.refunded_at >= ? AND p.refunded_at < ? ORDER BY dt DESC", [$f0, $f1, $f0, $f1]);
            $rows = array_map(fn($r) => [$r['kind'], $r['ref'], fmt_dt($r['dt']), (float)$r['amount'], (string)$r['reason'], (string)$r['by_user']], $d);
            $refAmt = 0; foreach ($d as $r) if ($r['kind'] === 'Refund') $refAmt += (float)$r['amount'];
            return ['cols' => [['Type', 'text'], ['Reference', 'text'], ['Date', 'text'], ['Amount', 'money'], ['Reason', 'text'], ['By', 'text']], 'rows' => $rows, 'summary' => ['Records' => count($rows), 'Refunded amount' => peso($refAmt)], 'chart' => null, 'note' => ''];
        case 'inv_summary':
            $locs = locations(); $w = 'i.is_active = 1'; $p = [];
            if ($item) { $w .= ' AND i.id = ?'; $p[] = $item; } if ($icat !== '') { $w .= ' AND i.category = ?'; $p[] = $icat; }
            $its = db_all("SELECT i.* FROM inventory_items i WHERE $w ORDER BY i.category, i.name", $p); $st = [];
            foreach (db_all('SELECT * FROM inventory_stock') as $s) $st[$s['item_id']][$s['location_id']] = (float)$s['quantity'];
            $rows = []; foreach ($its as $i) { $r = [$i['name'], $i['category'], $i['unit']]; $t = 0; foreach ($locs as $l) { $q = $st[$i['id']][$l['id']] ?? 0; $r[] = $q; $t += $q; } $r[] = $t; $rows[] = $r; }
            $cols = [['Item', 'text'], ['Category', 'text'], ['Unit', 'text']]; foreach ($locs as $l) $cols[] = [$l['name'], 'num']; $cols[] = ['Total', 'num'];
            return ['cols' => $cols, 'rows' => $rows, 'summary' => ['Items' => count($rows)], 'chart' => null, 'note' => ''];
        case 'low_stock':
            $rows = []; foreach (low_stock_rows($location ?: null) as $r) { if ($icat !== '' && $r['category'] !== $icat) continue;
                $rows[] = [$r['name'], $r['category'], $r['location_name'], (float)$r['quantity'], (float)$r['min_threshold'], $r['unit'], stock_status((float)$r['quantity'], (float)$r['min_threshold']) === 'out' ? 'Out of stock' : 'Low stock', (int)$r['open_requests'] ? 'Yes' : 'No']; }
            return ['cols' => [['Item', 'text'], ['Category', 'text'], ['Location', 'text'], ['On hand', 'num'], ['Minimum', 'num'], ['Unit', 'text'], ['Status', 'text'], ['Request open', 'text']], 'rows' => $rows, 'summary' => ['Low / out items' => count($rows)], 'chart' => null, 'note' => ''];
        case 'movements':
            $w = 'm.created_at >= ? AND m.created_at < ?'; $p = [$f0, $f1];
            if ($item) { $w .= ' AND m.item_id = ?'; $p[] = $item; } if ($location) { $w .= ' AND (m.from_location_id = ? OR m.to_location_id = ?)'; array_push($p, $location, $location); }
            if (in_array($mtype, ['receive', 'transfer', 'usage', 'adjustment', 'order_usage', 'order_restore'], true)) { $w .= ' AND m.movement_type = ?'; $p[] = $mtype; }
            $d = db_all("SELECT m.*, i.name item, i.unit, lf.name fn, lt.name tn, u.full_name staff FROM stock_movements m JOIN inventory_items i ON i.id = m.item_id LEFT JOIN inventory_locations lf ON lf.id = m.from_location_id LEFT JOIN inventory_locations lt ON lt.id = m.to_location_id LEFT JOIN users u ON u.id = m.user_id WHERE $w ORDER BY m.id DESC LIMIT 5000", $p);
            $rows = array_map(fn($r) => [fmt_dt($r['created_at']), $r['item'], $r['movement_type'], (string)$r['fn'], (string)$r['tn'], (float)$r['quantity'], $r['unit'], trim(($r['reference'] ?? '') . ' ' . ($r['note'] ?? '')), (string)$r['staff']], $d);
            return ['cols' => [['Date', 'text'], ['Item', 'text'], ['Type', 'text'], ['From', 'text'], ['To', 'text'], ['Quantity', 'num'], ['Unit', 'text'], ['Reference / note', 'text'], ['Staff', 'text']], 'rows' => $rows, 'summary' => ['Movements' => count($rows)], 'chart' => null, 'note' => count($d) >= 5000 ? 'Showing the latest 5,000 movements. Narrow the date range.' : ''];
        case 'replenish':
            $w = 'r.requested_at >= ? AND r.requested_at < ?'; $p = [$f0, $f1];
            if ($item) { $w .= ' AND r.item_id = ?'; $p[] = $item; } if ($location) { $w .= ' AND r.location_id = ?'; $p[] = $location; }
            if (in_array($rstatus, ['requested', 'received', 'cancelled'], true)) { $w .= ' AND r.status = ?'; $p[] = $rstatus; }
            $d = db_all("SELECT r.*, i.name item, i.unit, l.name loc, ur.full_name req, uv.full_name rec FROM replenishments r JOIN inventory_items i ON i.id = r.item_id JOIN inventory_locations l ON l.id = r.location_id JOIN users ur ON ur.id = r.requested_by LEFT JOIN users uv ON uv.id = r.received_by WHERE $w ORDER BY r.id DESC", $p);
            $rows = array_map(fn($r) => [fmt_dt($r['requested_at'], 'M j, Y'), $r['item'], $r['loc'], (float)$r['qty_requested'], $r['qty_received'] === null ? '' : (float)$r['qty_received'], $r['unit'], ucfirst($r['status']), $r['req'], (string)$r['rec'], $r['received_at'] ? fmt_dt($r['received_at'], 'M j, Y') : '', (string)$r['notes']], $d);
            return ['cols' => [['Requested', 'text'], ['Item', 'text'], ['Destination', 'text'], ['Qty requested', 'num'], ['Qty received', 'num'], ['Unit', 'text'], ['Status', 'text'], ['Requested by', 'text'], ['Received by', 'text'], ['Date received', 'text'], ['Notes', 'text']], 'rows' => $rows, 'summary' => ['Records' => count($rows)], 'chart' => null, 'note' => ''];
    }
    return ['cols' => [], 'rows' => [], 'summary' => [], 'chart' => null, 'note' => ''];
}
$rep = build_report($type, compact('f0', 'f1', 'method', 'product', 'category', 'item', 'icat', 'location', 'mtype', 'rstatus'));

if (get('export') === 'csv') {
    audit('report_exported', 'report', null, $type);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="carlouise_' . $type . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [setting('cafe_name') . ' - ' . $REPORTS[$type][0]]);
    fputcsv($out, ['Generated', date('Y-m-d H:i'), 'Range', "$from to $to"]);
    fputcsv($out, array_column($rep['cols'], 0));
    foreach ($rep['rows'] as $r) fputcsv($out, array_map(fn($v) => (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v, $r));
    fclose($out); exit;
}

$methods = db_all('SELECT id, name FROM payment_methods ORDER BY id');
$prods = db_all('SELECT id, name FROM products ORDER BY name');
$cats = db_all('SELECT id, name FROM categories ORDER BY name');
$items = db_all('SELECT id, name FROM inventory_items ORDER BY name');
$icats = array_column(db_all('SELECT DISTINCT category FROM inventory_items ORDER BY category'), 'category');
$locs = locations();
page_header('Reports', 'reports');
$filters = $REPORTS[$type][2];
?>
<form class="card card-body mb-3 no-print" method="get" id="repForm"><div class="row g-2 align-items-end">
  <div class="col-md-3"><label class="form-label small mb-0">Report</label><select class="form-select" name="type" id="repType"><?php foreach ($allowed as $k => $r): ?><option value="<?= $k ?>" data-f="<?= e(implode(',', $r[2])) ?>" <?= $k === $type ? 'selected' : '' ?>><?= e($r[0]) ?></option><?php endforeach; ?></select></div>
  <div class="col-6 col-md-2" data-filter="from"><label class="form-label small mb-0">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
  <div class="col-6 col-md-2" data-filter="to"><label class="form-label small mb-0">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
  <div class="col-md-2" data-filter="method"><label class="form-label small mb-0">Payment method</label><select class="form-select" name="method"><option value="0">All</option><?php foreach ($methods as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $method === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2" data-filter="product"><label class="form-label small mb-0">Product</label><select class="form-select" name="product"><option value="0">All</option><?php foreach ($prods as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $product === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2" data-filter="category"><label class="form-label small mb-0">Category</label><select class="form-select" name="category"><option value="0">All</option><?php foreach ($cats as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $category === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2" data-filter="item"><label class="form-label small mb-0">Inventory item</label><select class="form-select" name="item"><option value="0">All</option><?php foreach ($items as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $item === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2" data-filter="icat"><label class="form-label small mb-0">Item category</label><select class="form-select" name="icat"><option value="">All</option><?php foreach ($icats as $m): ?><option <?= $icat === $m ? 'selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2" data-filter="location"><label class="form-label small mb-0">Location</label><select class="form-select" name="location"><option value="0">All</option><?php foreach ($locs as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $location === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2" data-filter="mtype"><label class="form-label small mb-0">Movement type</label><select class="form-select" name="mtype"><option value="">All</option><?php foreach (['receive', 'transfer', 'usage', 'adjustment', 'order_usage', 'order_restore'] as $m): ?><option value="<?= $m ?>" <?= $mtype === $m ? 'selected' : '' ?>><?= e(ucfirst(str_replace('_', ' ', $m))) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2" data-filter="rstatus"><label class="form-label small mb-0">Status</label><select class="form-select" name="rstatus"><option value="">All</option><?php foreach (['requested' => 'Open', 'received' => 'Received', 'cancelled' => 'Cancelled'] as $k => $m): ?><option value="<?= $k ?>" <?= $rstatus === $k ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Run report</button></div>
</div></form>

<div class="card"><div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2"><span><?= e($REPORTS[$type][0]) ?> <span class="text-muted small fw-normal"><?= in_array('from', $filters, true) ? e(fmt_dt($from, 'M j, Y') . ' – ' . fmt_dt($to, 'M j, Y')) : 'as of ' . e(date('M j, Y g:i A')) ?></span></span>
  <span class="no-print"><a class="btn btn-sm btn-outline-secondary" href="?<?= e(http_build_query(array_merge($_GET, ['type' => $type, 'export' => 'csv']))) ?>"><i class="bi bi-filetype-csv"></i> Export CSV</a>
  <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button></span></div>
<div class="card-body">
  <?php if ($rep['summary']): ?><div class="d-flex flex-wrap gap-4 mb-3"><?php foreach ($rep['summary'] as $k => $v): ?><div><div class="small text-muted"><?= e($k) ?></div><div class="fs-5 fw-bold"><?= e($v) ?></div></div><?php endforeach; ?></div><?php endif; ?>
  <?php if ($rep['chart'] && $rep['chart']['labels']): ?><div style="height:260px" class="mb-3"><canvas id="repChart"></canvas></div><?php endif; ?>
  <?php if (!$rep['rows']): echo empty_state('bar-chart-line', 'No data for these filters', 'Try a wider date range or different filters.'); else: ?>
  <div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead><tr><?php foreach ($rep['cols'] as [$l, $t]): ?><th class="<?= $t === 'text' ? '' : 'text-end' ?>"><?= e($l) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($rep['rows'] as $r): ?><tr><?php foreach ($r as $i => $v): $t = $rep['cols'][$i][1]; ?><td class="<?= $t === 'text' ? '' : 'text-end' ?>"><?= $t === 'money' ? peso($v) : ($t === 'num' ? ($v === '' ? '' : qty($v)) : e($v)) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <?php if ($rep['note']): ?><div class="small text-muted mt-2"><?= e($rep['note']) ?></div><?php endif; ?>
</div></div>
<?php
$js = '<script>function showF(){const o=document.getElementById("repType");const f=o.options[o.selectedIndex].dataset.f.split(",");document.querySelectorAll("[data-filter]").forEach(e=>e.classList.toggle("d-none",!f.includes(e.dataset.filter)));}
document.getElementById("repType").addEventListener("change",()=>{ document.querySelectorAll("#repForm [name=from],#repForm [name=to]").forEach(i=>i.value=""); showF(); });showF();</script>';
if ($rep['chart'] && $rep['chart']['labels']) {
    $js .= '<script src="' . url('assets/vendor/chart/chart.umd.js') . '"></script><script>const C=' . json_encode($rep['chart']) . ';const money=' . json_encode($type !== 'best' && $type !== 'orders_day') . ';
new Chart(document.getElementById("repChart"),{type:C.type,data:{labels:C.labels,datasets:[{label:C.label,data:C.data,backgroundColor:"#2f6b5a",borderColor:"#2f6b5a",borderRadius:4,tension:.25}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>money?"₱"+Number(v).toLocaleString("en-PH"):v}}}}});</script>';
}
page_footer($js);
