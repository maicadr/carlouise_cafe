<?php
/**
 * Inventory operations. All functions that change quantities MUST be called
 * inside db_tx() so related rows are committed or rolled back together.
 */
function item_loc_label(int $itemId, int $locId): string {
    $i = db_val('SELECT name FROM inventory_items WHERE id = ?', [$itemId]);
    $l = db_val('SELECT name FROM inventory_locations WHERE id = ?', [$locId]);
    return ($i ?: 'item') . ' @ ' . ($l ?: 'location');
}

function lock_stock_row(int $itemId, int $locId): float {
    db_exec('INSERT IGNORE INTO inventory_stock (item_id, location_id, quantity) VALUES (?,?,0)', [$itemId, $locId]);
    $q = db_val('SELECT quantity FROM inventory_stock WHERE item_id = ? AND location_id = ? FOR UPDATE', [$itemId, $locId]);
    return (float)$q;
}

/** Add (+) or remove (-) stock at one location and log the movement. Returns the new quantity. */
function change_stock(int $itemId, int $locId, float $delta, string $type, string $note = '', ?string $ref = null, bool $allowNegative = false): float {
    $old = lock_stock_row($itemId, $locId);
    $new = round($old + $delta, 3);
    if ($new < 0 && !$allowNegative) {
        throw new UserError('Not enough stock: ' . item_loc_label($itemId, $locId) . ' has ' . qty($old) . ' but ' . qty(abs($delta)) . ' is needed.');
    }
    db_exec('UPDATE inventory_stock SET quantity = ? WHERE item_id = ? AND location_id = ?', [$new, $itemId, $locId]);
    db_insert('INSERT INTO stock_movements (item_id, from_location_id, to_location_id, movement_type, quantity, reference, note, user_id) VALUES (?,?,?,?,?,?,?,?)',
        [$itemId, $delta < 0 ? $locId : null, $delta > 0 ? $locId : null, $type, abs($delta), $ref, mb_substr($note, 0, 255), user_id() ?: null]);
    return $new;
}

/** Move stock between two locations: both sides change together or not at all. */
function transfer_stock(int $itemId, int $fromLoc, int $toLoc, float $q, string $note = ''): void {
    if ($fromLoc === $toLoc) throw new UserError('Choose two different locations.');
    if ($q <= 0) throw new UserError('Quantity must be greater than zero.');
    // lock rows in a consistent order to avoid deadlocks
    $ids = [$fromLoc, $toLoc]; sort($ids);
    $bal = [];
    foreach ($ids as $lid) $bal[$lid] = lock_stock_row($itemId, $lid);
    if ($bal[$fromLoc] < $q) {
        throw new UserError('Not enough stock to transfer: ' . item_loc_label($itemId, $fromLoc) . ' has only ' . qty($bal[$fromLoc]) . '.');
    }
    db_exec('UPDATE inventory_stock SET quantity = ? WHERE item_id = ? AND location_id = ?', [round($bal[$fromLoc] - $q, 3), $itemId, $fromLoc]);
    db_exec('UPDATE inventory_stock SET quantity = ? WHERE item_id = ? AND location_id = ?', [round($bal[$toLoc] + $q, 3), $itemId, $toLoc]);
    db_insert('INSERT INTO stock_movements (item_id, from_location_id, to_location_id, movement_type, quantity, note, user_id) VALUES (?,?,?,?,?,?,?)',
        [$itemId, $fromLoc, $toLoc, 'transfer', $q, mb_substr($note, 0, 255), user_id()]);
}

/** Every item/location at or below its minimum (and with a threshold configured). */
function low_stock_rows(?int $locationId = null, int $limit = 0): array {
    $sql = "SELECT i.id item_id, i.name, i.category, i.unit, l.id location_id, l.name location_name, s.quantity, s.min_threshold,
              (SELECT COUNT(*) FROM replenishments r WHERE r.item_id = i.id AND r.location_id = l.id AND r.status = 'requested') open_requests
            FROM inventory_stock s
            JOIN inventory_items i ON i.id = s.item_id AND i.is_active = 1
            JOIN inventory_locations l ON l.id = s.location_id
            WHERE s.min_threshold > 0 AND s.quantity <= s.min_threshold";
    $p = [];
    if ($locationId) { $sql .= ' AND s.location_id = ?'; $p[] = $locationId; }
    $sql .= ' ORDER BY (s.quantity <= 0) DESC, (s.quantity / s.min_threshold) ASC, i.name';
    if ($limit > 0) $sql .= ' LIMIT ' . (int)$limit;
    return db_all($sql, $p);
}

function suggested_restock_qty(float $qty, float $min): float {
    return max(round($min * 2 - $qty, 3), $min > 0 ? $min : 1);
}

/** Create a replenishment request (not added to stock until received). */
function create_replenishment(int $itemId, int $locId, float $q, string $notes): int {
    return db_insert('INSERT INTO replenishments (item_id, location_id, qty_requested, status, requested_by, notes) VALUES (?,?,?,?,?,?)',
        [$itemId, $locId, $q, 'requested', user_id(), mb_substr($notes, 0, 500)]);
}

/** Confirm receipt: adds the received quantity to the destination and closes the request. */
function receive_replenishment(int $repId, float $receivedQty, string $receivedAt, string $notes): void {
    $r = db_one('SELECT * FROM replenishments WHERE id = ? FOR UPDATE', [$repId]);
    if (!$r) throw new UserError('Replenishment record not found.');
    if ($r['status'] !== 'requested') throw new UserError('This request was already ' . $r['status'] . '.');
    change_stock((int)$r['item_id'], (int)$r['location_id'], $receivedQty, 'receive', 'Replenishment #' . $repId . ($notes ? ': ' . $notes : ''), 'REP-' . $repId);
    db_exec("UPDATE replenishments SET status='received', qty_received=?, received_by=?, received_at=?, notes=CONCAT(COALESCE(notes,''), ?) WHERE id=?",
        [$receivedQty, user_id(), $receivedAt, $notes !== '' ? ' | Received: ' . mb_substr($notes, 0, 200) : '', $repId]);
}
