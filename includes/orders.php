<?php
/** Order, payment, cancellation and refund logic. Each public function manages its own transaction. */
require_once __DIR__ . '/inventory.php';

const ORDER_TYPES = ['dine-in' => 'Dine-in', 'take-out' => 'Take-out', 'delivery' => 'Delivery'];
const STATUS_FLOW = ['pending' => 'preparing', 'preparing' => 'ready', 'ready' => 'completed'];

function order_items(int $orderId): array {
    return db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
}

/** Create an order from a cart. Prices always come from the database, never from the browser. */
function create_order(array $cart, string $type, int $tableId, string $customer, string $address, string $notes, string $token, bool $allowNegative): array {
    if (!isset(ORDER_TYPES[$type])) throw new UserError('Choose dine-in, take-out or delivery.');
    if (!preg_match('/^[A-Za-z0-9-]{16,64}$/', $token)) throw new UserError('Invalid form token. Reload the page and try again.');
    $dup = db_one('SELECT id, order_no FROM orders WHERE client_token = ?', [$token]);
    if ($dup) return ['id' => (int)$dup['id'], 'order_no' => $dup['order_no'], 'duplicate' => true, 'negative' => false];

    $tid = null;
    if ($type === 'dine-in') {
        if (!$tableId || !db_val('SELECT id FROM dining_tables WHERE id = ? AND is_active = 1', [$tableId])) throw new UserError('Select a table for the dine-in order.');
        $tid = $tableId;
    }
    $address = mb_substr($address, 0, 255);
    if ($type === 'delivery' && $address === '') throw new UserError('Enter the delivery address.');

    // merge duplicate product lines and validate quantities
    $lines = [];
    foreach ($cart as $c) {
        if (!is_array($c)) continue;
        $pid = (int)($c['id'] ?? 0); $q = (int)($c['qty'] ?? 0);
        if ($pid <= 0 || $q < 1 || $q > 99) throw new UserError('Each item needs a quantity between 1 and 99.');
        $note = mb_substr(trim((string)($c['note'] ?? '')), 0, 255);
        if (isset($lines[$pid])) { $lines[$pid]['qty'] += $q; if ($note !== '') $lines[$pid]['note'] = trim($lines[$pid]['note'] . ' ' . $note); }
        else $lines[$pid] = ['qty' => $q, 'note' => $note];
    }
    if (!$lines) throw new UserError('Add at least one product to the order.');
    $ids = array_keys($lines);
    $prods = [];
    foreach (db_all('SELECT id, name, price, is_active, is_available FROM products WHERE id IN (' . db_in($ids) . ')', $ids) as $p) $prods[(int)$p['id']] = $p;
    $total = 0.0;
    foreach ($lines as $pid => &$l) {
        $p = $prods[$pid] ?? null;
        if (!$p) throw new UserError('A selected product no longer exists.');
        if (!(int)$p['is_active'] || !(int)$p['is_available']) throw new UserError($p['name'] . ' is not available. Remove it and try again.');
        $l['name'] = $p['name']; $l['price'] = (float)$p['price']; $l['total'] = round($l['price'] * $l['qty'], 2);
        $total += $l['total'];
    }
    unset($l);
    $total = round($total, 2);

    try {
        $res = db_tx(function () use ($lines, $type, $tid, $customer, $address, $notes, $token, $total, $allowNegative) {
            $orderNo = sprintf('ORD-%s-%04d', date('ymd'), next_counter('ORD' . date('Ymd')));
            $id = db_insert('INSERT INTO orders (order_no, client_token, order_type, table_id, customer_name, delivery_address, notes, total, created_by) VALUES (?,?,?,?,?,?,?,?,?)',
                [$orderNo, $token, $type, $tid, mb_substr($customer, 0, 100) ?: null, $type === 'delivery' ? $address : null, mb_substr($notes, 0, 1000) ?: null, $total, user_id()]);
            foreach ($lines as $pid => $l) {
                db_insert('INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity, line_total, item_notes) VALUES (?,?,?,?,?,?,?)',
                    [$id, $pid, $l['name'], $l['price'], $l['qty'], $l['total'], $l['note'] ?: null]);
            }
            $negative = deduct_inventory_for_order($id, $orderNo, $allowNegative);
            return ['id' => $id, 'order_no' => $orderNo, 'duplicate' => false, 'negative' => $negative];
        });
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() === 1062 && str_contains($e->getMessage(), 'client_token')) {
            $dup = db_one('SELECT id, order_no FROM orders WHERE client_token = ?', [$token]);
            if ($dup) return ['id' => (int)$dup['id'], 'order_no' => $dup['order_no'], 'duplicate' => true, 'negative' => false];
        }
        throw $e;
    }
    audit('order_created', 'order', $res['id'], $res['order_no'] . ' total ' . peso($total) . ($res['negative'] ? ' (NEGATIVE STOCK EXCEPTION)' : ''));
    return $res;
}

/** Deduct configured ingredients. Returns true if any stock went negative by explicit exception. */
function deduct_inventory_for_order(int $orderId, string $orderNo, bool $allowNegative): bool {
    $needs = db_all('SELECT pi.item_id, pi.location_id, SUM(oi.quantity * pi.quantity) AS need
                     FROM order_items oi JOIN product_ingredients pi ON pi.product_id = oi.product_id
                     WHERE oi.order_id = ? GROUP BY pi.item_id, pi.location_id ORDER BY pi.item_id, pi.location_id', [$orderId]);
    if (!$needs) return false;
    $short = []; $negative = false;
    foreach ($needs as $n) {
        $item = (int)$n['item_id']; $loc = (int)$n['location_id']; $need = round((float)$n['need'], 3);
        $have = lock_stock_row($item, $loc);
        if ($have < $need) {
            if (!$allowNegative) { $short[] = item_loc_label($item, $loc) . ' (have ' . qty($have) . ', need ' . qty($need) . ')'; continue; }
            $negative = true;
        }
        change_stock($item, $loc, -$need, 'order_usage', 'Order ' . $orderNo . ($have < $need ? ' - NEGATIVE STOCK EXCEPTION' : ''), $orderNo, true);
    }
    if ($short) throw new UserError('Not enough stock for this order: ' . implode('; ', $short) . '. Restock or transfer stock first, then try again.');
    db_exec('UPDATE orders SET inventory_deducted = 1 WHERE id = ?', [$orderId]);
    return $negative;
}

/** Move an order along pending -> preparing -> ready -> completed. */
function set_order_status(int $orderId, string $new): void {
    $role = user_role();
    db_tx(function () use ($orderId, $new, $role) {
        $o = db_one('SELECT id, order_no, status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$o) throw new UserError('Order not found.');
        if ((STATUS_FLOW[$o['status']] ?? null) !== $new) {
            throw new UserError('Order ' . $o['order_no'] . ' is already "' . $o['status'] . '" - the screen was out of date.');
        }
        if ($role !== 'kitchen' && !($role === 'cashier' && $new === 'completed')) {
            throw new UserError('Your role cannot change the order to "' . $new . '".');
        }
        $col = ['preparing' => 'preparing_at', 'ready' => 'ready_at', 'completed' => 'completed_at'][$new];
        db_exec("UPDATE orders SET status = ?, $col = NOW() WHERE id = ?", [$new, $orderId]);
        audit('order_status', 'order', $orderId, $o['order_no'] . ': ' . $o['status'] . ' -> ' . $new);
    });
}

/** Record payment. Orders are marked paid only inside the same transaction as the payment row. */
function record_payment(int $orderId, int $methodId, string $received, string $reference): int {
    if (!can('take_payment')) throw new UserError('Only cashiers record payments.');
    return db_tx(function () use ($orderId, $methodId, $received, $reference) {
        $o = db_one('SELECT id, order_no, total, status, payment_status FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$o) throw new UserError('Order not found.');
        if ($o['status'] === 'cancelled') throw new UserError('This order was cancelled and cannot be paid.');
        if ($o['payment_status'] !== 'unpaid') throw new UserError('This order is already ' . $o['payment_status'] . '. No new payment was recorded.');
        $m = db_one('SELECT * FROM payment_methods WHERE id = ? AND is_active = 1', [$methodId]);
        if (!$m) throw new UserError('Choose an accepted payment method.');
        $total = round((float)$o['total'], 2);
        $reference = mb_substr($reference, 0, 60);
        if ((int)$m['is_cash']) {
            $recv = parse_money($received);
            if ($recv + 0.001 < $total) throw new UserError('Amount received (' . peso($recv) . ') is less than the total (' . peso($total) . ').');
        } else {
            $recv = $total;
            if ((int)$m['requires_reference'] && $reference === '') throw new UserError($m['name'] . ' payments need a reference number.');
        }
        $change = round($recv - $total, 2);
        $txn = sprintf('TXN-%s-%04d', date('ymd'), next_counter('TXN' . date('Ymd')));
        $pid = db_insert('INSERT INTO payments (transaction_no, order_id, payment_method_id, method_name, amount_due, amount_received, change_amount, reference_no, status, cashier_id) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$txn, $orderId, $methodId, $m['name'], $total, $recv, $change, $reference ?: null, 'paid', user_id()]);
        db_exec("UPDATE orders SET payment_status = 'paid' WHERE id = ?", [$orderId]);
        audit('payment_recorded', 'payment', $pid, $txn . ' ' . $o['order_no'] . ' ' . $m['name'] . ' ' . peso($total));
        return $pid;
    });
}

/**
 * Cancel an open order. Refunds a recorded payment and puts deducted ingredients back.
 * $authorizedBy is the administrator who approved it (the admin themself or via credentials).
 */
function cancel_order(int $orderId, string $reason, int $authorizedBy): void {
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) throw new UserError('Enter a reason for the cancellation.');
    db_tx(function () use ($orderId, $reason, $authorizedBy) {
        $o = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
        if (!$o) throw new UserError('Order not found.');
        if (!in_array($o['status'], ['pending', 'preparing', 'ready'], true)) {
            throw new UserError($o['status'] === 'completed' ? 'Completed orders cannot be cancelled. Use a refund instead.' : 'This order is already cancelled.');
        }
        if ($o['payment_status'] === 'paid') {
            db_exec("UPDATE payments SET status = 'refunded', refund_reason = ?, refunded_by = ?, refunded_at = NOW() WHERE order_id = ? AND status = 'paid'",
                [mb_substr('Order cancelled: ' . $reason, 0, 255), user_id(), $orderId]);
            db_exec("UPDATE orders SET payment_status = 'refunded' WHERE id = ?", [$orderId]);
        }
        if ((int)$o['inventory_deducted'] === 1) {
            $moves = db_all("SELECT item_id, from_location_id, quantity FROM stock_movements WHERE reference = ? AND movement_type = 'order_usage'", [$o['order_no']]);
            foreach ($moves as $m) {
                change_stock((int)$m['item_id'], (int)$m['from_location_id'], (float)$m['quantity'], 'order_restore', 'Order ' . $o['order_no'] . ' cancelled', $o['order_no'], true);
            }
            db_exec('UPDATE orders SET inventory_deducted = 0 WHERE id = ?', [$orderId]);
        }
        db_exec("UPDATE orders SET status = 'cancelled', cancel_reason = ?, cancelled_by = ?, authorized_by = ?, cancelled_at = NOW() WHERE id = ?",
            [mb_substr($reason, 0, 255), user_id(), $authorizedBy, $orderId]);
        audit('order_cancelled', 'order', $orderId, $o['order_no'] . ' | ' . $reason . ' | authorized_by=' . $authorizedBy);
    });
}

/** Admin-only refund of a paid, completed order (food already served: stock is not restored). */
function refund_payment(int $paymentId, string $reason): void {
    $reason = trim($reason);
    if (mb_strlen($reason) < 3) throw new UserError('Enter a reason for the refund.');
    db_tx(function () use ($paymentId, $reason) {
        $p = db_one('SELECT p.*, o.status AS order_status FROM payments p JOIN orders o ON o.id = p.order_id WHERE p.id = ? FOR UPDATE', [$paymentId]);
        if (!$p) throw new UserError('Payment not found.');
        if ($p['status'] !== 'paid') throw new UserError('This payment was already refunded.');
        if ($p['order_status'] !== 'completed') throw new UserError('Only completed orders are refunded here. Cancel the open order instead.');
        db_exec("UPDATE payments SET status = 'refunded', refund_reason = ?, refunded_by = ?, refunded_at = NOW() WHERE id = ?", [mb_substr($reason, 0, 255), user_id(), $paymentId]);
        db_exec("UPDATE orders SET payment_status = 'refunded' WHERE id = ?", [(int)$p['order_id']]);
        audit('payment_refunded', 'payment', $paymentId, $p['transaction_no'] . ' ' . peso($p['amount_due']) . ' | ' . $reason);
    });
}
