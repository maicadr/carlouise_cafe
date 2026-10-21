<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('products');
$manage = can('products_manage');

function save_product_image(array $file, ?string $old): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return $old;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new UserError('The image could not be uploaded.');
    if ($file['size'] > 2 * 1024 * 1024) throw new UserError('Image must be 2 MB or smaller.');
    $info = @getimagesize($file['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new UserError('Only JPG, PNG, WEBP or GIF images are allowed.');
    $dir = __DIR__ . '/../assets/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) throw new UserError('Could not save the image file.');
    if ($old && preg_match('/^[a-f0-9]{20}\.(jpg|png|webp|gif)$/', $old) && is_file("$dir/$old")) @unlink("$dir/$old");
    return $name;
}

if (is_post()) {
    if (!$manage) { http_response_code(403); exit('Not allowed.'); }
    csrf_verify();
    try {
        $a = post('action');
        if ($a === 'save') {
            $pid = post_int('id'); $name = post('name'); $cat = post_int('category_id'); $desc = mb_substr(post('description'), 0, 500);
            $price = parse_money(post('price'));
            if ($name === '' || mb_strlen($name) > 120) throw new UserError('Enter a product name (up to 120 characters).');
            if ($price <= 0 || $price > 99999) throw new UserError('Enter a price greater than zero.');
            if (!db_val('SELECT id FROM categories WHERE id = ?', [$cat])) throw new UserError('Choose a category.');
            $active = post('is_active') === '1' ? 1 : 0; $avail = post('is_available') === '1' ? 1 : 0;
            $old = $pid ? db_val('SELECT image FROM products WHERE id = ?', [$pid]) : null;
            $img = save_product_image($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE], $old);
            if ($pid) {
                db_exec('UPDATE products SET name=?, category_id=?, description=?, price=?, image=?, is_active=?, is_available=? WHERE id=?', [$name, $cat, $desc ?: null, $price, $img, $active, $avail, $pid]);
                audit('product_updated', 'product', $pid, $name . ' @ ' . peso($price));
                flash('success', 'Product updated.');
            } else {
                $nid = db_insert('INSERT INTO products (name, category_id, description, price, image, is_active, is_available) VALUES (?,?,?,?,?,?,?)', [$name, $cat, $desc ?: null, $price, $img, $active, $avail]);
                audit('product_created', 'product', $nid, $name . ' @ ' . peso($price));
                flash('success', 'Product added.');
            }
        } elseif ($a === 'toggle_active') {
            db_exec('UPDATE products SET is_active = 1 - is_active WHERE id = ?', [post_int('id')]); audit('product_toggled', 'product', post_int('id'), 'active toggled'); flash('success', 'Product status changed.');
        } elseif ($a === 'toggle_available') {
            db_exec('UPDATE products SET is_available = 1 - is_available WHERE id = ?', [post_int('id')]); audit('product_availability', 'product', post_int('id'), 'availability toggled'); flash('success', 'Availability changed.');
        } elseif ($a === 'delete') {
            $pid = post_int('id');
            if (db_val('SELECT COUNT(*) FROM order_items WHERE product_id = ?', [$pid])) throw new UserError('This product has order history, so it cannot be deleted. Deactivate it instead.');
            db_tx(function () use ($pid) { db_exec('DELETE FROM product_ingredients WHERE product_id = ?', [$pid]); db_exec('DELETE FROM products WHERE id = ?', [$pid]); });
            audit('product_deleted', 'product', $pid, 'deleted'); flash('success', 'Product deleted.');
        } elseif ($a === 'ingredients') {
            $pid = post_int('id'); $rows = [];
            $items = $_POST['ing_item'] ?? []; $locs = $_POST['ing_loc'] ?? []; $qs = $_POST['ing_qty'] ?? [];
            if (!is_array($items) || !is_array($locs) || !is_array($qs)) throw new UserError('Invalid ingredient data.');
            foreach ($items as $k => $iid) {
                if ((int)$iid === 0 && trim((string)($qs[$k] ?? '')) === '') continue;
                $iid = (int)$iid; $lid = (int)($locs[$k] ?? 0);
                if (!db_val('SELECT id FROM inventory_items WHERE id = ?', [$iid]) || !db_val('SELECT id FROM inventory_locations WHERE id = ?', [$lid])) throw new UserError('Choose an item and location for every ingredient row.');
                $rows[$iid . '-' . $lid] = [$iid, $lid, parse_qty((string)($qs[$k] ?? ''))];
            }
            db_tx(function () use ($pid, $rows) {
                db_exec('DELETE FROM product_ingredients WHERE product_id = ?', [$pid]);
                foreach ($rows as [$iid, $lid, $q]) db_exec('INSERT INTO product_ingredients (product_id, item_id, location_id, quantity) VALUES (?,?,?,?)', [$pid, $iid, $lid, $q]);
            });
            audit('ingredients_saved', 'product', $pid, count($rows) . ' ingredient rows'); flash('success', 'Ingredient rules saved.');
        }
    } catch (UserError $e) { flash('danger', $e->getMessage()); }
    redirect_self();
}

$q = get('q'); $cat = (int)get('category'); $st = get('status');
$where = ['1=1']; $params = [];
if ($q !== '') { $where[] = '(p.name LIKE ? OR p.description LIKE ?)'; $params[] = "%$q%"; $params[] = "%$q%"; }
if ($cat) { $where[] = 'p.category_id = ?'; $params[] = $cat; }
if ($st === 'active') $where[] = 'p.is_active = 1'; elseif ($st === 'inactive') $where[] = 'p.is_active = 0';
elseif ($st === 'available') $where[] = 'p.is_active = 1 AND p.is_available = 1'; elseif ($st === 'unavailable') $where[] = 'p.is_available = 0';
$w = implode(' AND ', $where);
[$off, $per, $page] = paginate_params(15);
$total = (int)db_val("SELECT COUNT(*) FROM products p WHERE $w", $params);
$rows = db_all("SELECT p.*, c.name AS category, (SELECT COUNT(*) FROM product_ingredients WHERE product_id = p.id) AS ing_count
                FROM products p JOIN categories c ON c.id = p.category_id WHERE $w ORDER BY c.name, p.name LIMIT $per OFFSET $off", $params);
$cats = db_all('SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name');
$invItems = db_all('SELECT id, name, unit FROM inventory_items WHERE is_active = 1 ORDER BY name');
$locs = locations();
$ingMap = [];
foreach (db_all('SELECT product_id, item_id, location_id, quantity FROM product_ingredients') as $r) $ingMap[$r['product_id']][] = ['item' => (int)$r['item_id'], 'loc' => (int)$r['location_id'], 'qty' => qty($r['quantity'])];

page_header('Products & Menu', 'products');
?>
<form class="card card-body mb-3" method="get"><div class="row g-2 align-items-end">
  <div class="col-md-4"><label class="form-label small mb-0">Search</label><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Name or description"></div>
  <div class="col-md-3"><label class="form-label small mb-0">Category</label><select class="form-select" name="category"><option value="0">All categories</option>
    <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $cat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-0">Availability</label><select class="form-select" name="status"><option value="">All</option>
    <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'available' => 'Orderable now', 'unavailable' => 'Marked unavailable'] as $k => $v): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filter</button><a class="btn btn-outline-secondary" href="<?= url('pages/products.php') ?>">Reset</a></div>
</div></form>
<?php if ($manage): ?><div class="mb-3"><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal" id="addBtn"><i class="bi bi-plus-lg"></i> Add product</button></div><?php endif; ?>
<div class="card"><div class="card-body p-0">
<?php if (!$rows): echo empty_state('cup-hot', 'No products found', $manage ? 'Add your first menu item.' : ''); else: ?>
<div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th></th><th>Product</th><th>Category</th><th class="text-end">Price</th><th>Status</th><th>Stock rules</th><?php if ($manage): ?><th></th><?php endif; ?></tr></thead><tbody>
<?php foreach ($rows as $r): ?>
<tr class="<?= !$r['is_active'] ? 'table-secondary' : '' ?>">
  <td style="width:56px"><?php if ($r['image']): ?><img src="<?= url('assets/uploads/' . rawurlencode($r['image'])) ?>" alt="" width="44" height="44" class="rounded object-fit-cover"><?php else: ?><span class="text-muted"><i class="bi bi-image fs-4"></i></span><?php endif; ?></td>
  <td><div class="fw-semibold"><?= e($r['name']) ?></div><div class="small text-muted"><?= e($r['description']) ?></div></td>
  <td><?= e($r['category']) ?></td><td class="text-end"><?= peso($r['price']) ?></td>
  <td><?= $r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?> <?= $r['is_available'] ? '' : '<span class="badge bg-warning text-dark">Unavailable</span>' ?></td>
  <td><?= (int)$r['ing_count'] ? (int)$r['ing_count'] . ' ingredient' . ($r['ing_count'] > 1 ? 's' : '') : '<span class="text-muted">None</span>' ?></td>
  <?php if ($manage): ?><td class="text-end text-nowrap">
    <button class="btn btn-sm btn-outline-secondary" data-fill="#productModal" data-bs-toggle="modal" data-bs-target="#productModal" data-json="<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'category_id' => $r['category_id'], 'description' => $r['description'], 'price' => $r['price'], 'is_active' => $r['is_active'], 'is_available' => $r['is_available']])) ?>">Edit</button>
    <button class="btn btn-sm btn-outline-secondary" data-fill="#ingModal" data-bs-toggle="modal" data-bs-target="#ingModal" data-json="<?= e(json_encode(['id' => $r['id'], 'pname' => $r['name'], 'ings' => $ingMap[$r['id']] ?? []])) ?>">Ingredients</button>
    <?= action_form('toggle_available', ['id' => $r['id']], $r['is_available'] ? 'Set unavailable' : 'Set available', 'btn btn-sm btn-outline-warning') ?>
    <?= action_form('toggle_active', ['id' => $r['id']], $r['is_active'] ? 'Deactivate' : 'Activate', 'btn btn-sm btn-outline-secondary', $r['is_active'] ? 'Deactivate this product? It will no longer be selectable for new orders.' : '') ?>
    <?= action_form('delete', ['id' => $r['id']], 'Delete', 'btn btn-sm btn-outline-danger', 'Delete ' . $r['name'] . ' permanently? Products with order history cannot be deleted.') ?>
  </td><?php endif; ?>
</tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div><div class="card-footer bg-white"><?= pagination_html($total, $page, $per) ?></div></div>

<?php if ($manage): ?>
<div class="modal fade" id="productModal" tabindex="-1"><div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
  <div class="modal-header"><h5 class="modal-title">Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body">
    <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="name" required maxlength="120"></div>
    <div class="row"><div class="col-6 mb-2"><label class="form-label">Category</label><select class="form-select" name="category_id" required><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 mb-2"><label class="form-label">Price (₱)</label><input class="form-control" name="price" inputmode="decimal" required></div></div>
    <div class="mb-2"><label class="form-label">Description</label><textarea class="form-control" name="description" rows="2" maxlength="500"></textarea></div>
    <div class="mb-2"><label class="form-label">Image (JPG, PNG, WEBP, GIF · max 2 MB)</label><input class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif"></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="f_active" checked><label class="form-check-label" for="f_active">Active (shows on the menu)</label></div>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="is_available" value="1" id="f_avail" checked><label class="form-check-label" for="f_avail">Available today</label></div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save product</button></div></form></div></div>

<div class="modal fade" id="ingModal" tabindex="-1"><div class="modal-dialog modal-lg"><form method="post" class="modal-content"><?= csrf_field() ?><input type="hidden" name="action" value="ingredients"><input type="hidden" name="id" value="0">
  <div class="modal-header"><h5 class="modal-title">Ingredients for <span data-text="pname"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><p class="small text-muted">Quantities used for <strong>one</strong> unit sold. They are deducted from the chosen location when an order is saved, and restored if the order is cancelled.</p>
    <div id="ingRows"></div><button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addIng"><i class="bi bi-plus"></i> Add ingredient</button></div>
  <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit">Save ingredients</button></div></form></div></div>
<?php endif; ?>
<?php
$js = '';
if ($manage) {
    $js = '<script>
const INV=' . json_encode($invItems) . ', LOCS=' . json_encode($locs) . ';
const opt=(a,sel,l)=>a.map(x=>`<option value="${x.id}" ${x.id==sel?"selected":""}>${x.name.replace(/</g,"&lt;")}${x.unit?" ("+x.unit+")":""}</option>`).join("");
function ingRow(r){r=r||{};return `<div class="row g-2 mb-2 ing-row"><div class="col-5"><select class="form-select" name="ing_item[]"><option value="0">Item…</option>${opt(INV,r.item)}</select></div>
<div class="col-4"><select class="form-select" name="ing_loc[]">${opt(LOCS,r.loc||(LOCS[0]||{}).id)}</select></div>
<div class="col-2"><input class="form-control" name="ing_qty[]" inputmode="decimal" placeholder="Qty" value="${r.qty||""}"></div>
<div class="col-1"><button type="button" class="btn btn-outline-danger w-100 rm-ing" aria-label="Remove">&times;</button></div></div>`;}
document.getElementById("ingModal").addEventListener("filled",e=>{document.getElementById("ingRows").innerHTML=(e.detail.ings||[]).map(ingRow).join("")||ingRow();});
document.getElementById("addIng").addEventListener("click",()=>document.getElementById("ingRows").insertAdjacentHTML("beforeend",ingRow()));
document.getElementById("ingRows").addEventListener("click",e=>{if(e.target.closest(".rm-ing"))e.target.closest(".ing-row").remove();});
document.getElementById("addBtn").addEventListener("click",()=>{const f=document.querySelector("#productModal form");f.reset();f.querySelector("[name=id]").value=0;});
</script>';
}
page_footer($js);
