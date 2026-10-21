<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('pos');

// AJAX save: returns JSON so the cart is never lost on an error
if (is_post()) {
    require_perm('pos', true);
    csrf_verify(true);
    try {
        $cart = json_decode(post('cart', '[]'), true);
        if (!is_array($cart)) throw new UserError('The order data was not valid.');
        $override = false;
        $res = create_order($cart, post('order_type'), post_int('table_id'), post('customer_name'), post('delivery_address'), post('notes'), post('client_token'), $override);
        if ($res['negative']) audit('stock_override', 'order', $res['id'], 'Order ' . $res['order_no'] . ' saved with negative stock exception');
        $target = 'pages/order_view.php?id=' . $res['id'] . (post('after') === 'pay' ? '#pay' : '');
        flash('success', ($res['duplicate'] ? 'This order was already saved: ' : 'Order saved and sent to the kitchen: ') . $res['order_no']);
        json_out(['ok' => true, 'redirect' => url($target), 'order_no' => $res['order_no']]);
    } catch (UserError $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('POS save failed: ' . $e->getMessage());
        json_out(['ok' => false, 'error' => 'The order could not be saved. Nothing was recorded. Please try again.' . (APP_DEBUG ? ' ' . $e->getMessage() : '')], 500);
    }
}

$cats = db_all('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name');
$products = db_all('SELECT id, category_id, name, price, image FROM products WHERE is_active = 1 AND is_available = 1 ORDER BY name');
$tables = db_all('SELECT id, name FROM dining_tables WHERE is_active = 1 ORDER BY id');
$token = bin2hex(random_bytes(16));

page_header('New Order', 'pos');
?>
<div class="row g-3">
  <div class="col-lg-8">
    <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
      <input type="search" id="pSearch" class="form-control" style="max-width:260px" placeholder="Search products…" aria-label="Search products">
      <div class="cat-pills d-flex gap-2 overflow-auto" id="catPills">
        <button class="btn btn-dark btn-sm" data-cat="0">All</button>
        <?php foreach ($cats as $c): ?><button class="btn btn-outline-dark btn-sm" data-cat="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></button><?php endforeach; ?>
      </div>
    </div>
    <div class="pos-grid" id="grid"></div>
    <div id="noProducts" class="d-none"><?= empty_state('cup-hot', 'No products match', 'Try another category or search term.') ?></div>
  </div>
  <div class="col-lg-4">
    <div class="card cart-card">
      <div class="card-header d-flex justify-content-between align-items-center">Current order
        <button class="btn btn-sm btn-outline-danger" id="clearCart" type="button">Clear</button></div>
      <div class="card-body">
        <div class="btn-group w-100 mb-2" role="group" aria-label="Order type">
          <?php foreach (ORDER_TYPES as $k => $lbl): ?>
            <input type="radio" class="btn-check" name="order_type" id="t_<?= $k ?>" value="<?= $k ?>" <?= $k === 'dine-in' ? 'checked' : '' ?>>
            <label class="btn btn-outline-primary" for="t_<?= $k ?>"><?= $lbl ?></label>
          <?php endforeach; ?>
        </div>
        <div id="tableBox" class="mb-2"><select class="form-select" id="table_id" aria-label="Table">
          <option value="">Select table…</option>
          <?php foreach ($tables as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
        </select></div>
        <input class="form-control mb-2" id="customer_name" maxlength="100" placeholder="Customer name (optional)">
        <textarea class="form-control mb-2 d-none" id="delivery_address" rows="2" maxlength="255" placeholder="Delivery address"></textarea>
        <div class="cart-lines" id="cartLines"></div>
        <textarea class="form-control my-2" id="notes" rows="2" maxlength="1000" placeholder="Order notes / special instructions"></textarea>
        <div class="d-flex justify-content-between align-items-center border-top pt-2 mb-2">
          <span class="fw-semibold">Total</span><span class="fs-3 fw-bold" id="grand">₱0.00</span></div>

        <div id="posError" class="alert alert-danger py-2 d-none"></div>
        <button class="btn btn-primary checkout-btn w-100 mb-2" id="saveBtn" type="button"><i class="bi bi-send"></i> Send to kitchen</button>
        <button class="btn btn-outline-primary w-100" id="savePayBtn" type="button"><i class="bi bi-cash-coin"></i> Send &amp; take payment</button>
      </div>
    </div>
  </div>
</div>
<?php
$js = '<script>
const PRODUCTS = ' . json_encode($products, JSON_HEX_TAG | JSON_HEX_AMP) . ';
const TOKEN = ' . json_encode($token) . ';
const IMG_BASE = ' . json_encode(url('assets/uploads/')) . ';
const POS_URL = ' . json_encode(url('pages/pos.php')) . ';
</script><script src="' . url('assets/js/pos.js') . '"></script>';
page_footer($js);
