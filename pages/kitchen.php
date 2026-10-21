<?php
require __DIR__ . '/../includes/bootstrap.php';
require_perm('kitchen', is_ajax() || get('partial') === '1');

if (is_post()) {              // AJAX status change from the kitchen board
    csrf_verify(true);
    try {
        set_order_status(post_int('order_id'), post('status'));
        json_out(['ok' => true]);
    } catch (UserError $e) { json_out(['ok' => false, 'error' => $e->getMessage()]); }
    catch (Throwable $e) { error_log($e->getMessage()); json_out(['ok' => false, 'error' => 'Could not update the order.'], 500); }
}

function kitchen_board(): void {
    $orders = db_all("SELECT o.*, t.name AS table_name FROM orders o LEFT JOIN dining_tables t ON t.id = o.table_id
                      WHERE o.status IN ('pending','preparing','ready') ORDER BY o.created_at ASC");
    $ids = array_column($orders, 'id');
    $items = [];
    if ($ids) foreach (db_all('SELECT * FROM order_items WHERE order_id IN (' . db_in($ids) . ') ORDER BY id', array_map('intval', $ids)) as $i) $items[$i['order_id']][] = $i;
    $cols = ['pending' => ['New orders', 'Start preparing', 'preparing', 'play-fill', 'btn-warning'],
             'preparing' => ['Preparing', 'Mark as ready', 'ready', 'check-lg', 'btn-info'],
             'ready' => ['Ready to serve', 'Mark as completed', 'completed', 'check2-all', 'btn-success']];
    echo '<div class="row g-3" data-pending="' . count(array_filter($orders, fn($o) => $o['status'] === 'pending')) . '">';
    foreach ($cols as $st => [$title, $btnLabel, $nextSt, $icon, $btnClass]) {
        $list = array_filter($orders, fn($o) => $o['status'] === $st);
        echo '<div class="col-lg-4"><div class="k-col-title mb-2">' . e($title) . ' <span class="badge bg-secondary">' . count($list) . '</span></div>';
        if (!$list) echo '<div class="text-muted small border rounded p-3 bg-white">Nothing here.</div>';
        foreach ($list as $o) {
            $mins = max(0, (int)floor((time() - strtotime($o['created_at'])) / 60));
            echo '<div class="k-card ' . e($st) . '"><div class="k-head"><span class="k-no">' . e($o['order_no']) . '</span><span class="small text-muted">' . e(date('g:i A', strtotime($o['created_at']))) . ' · ' . $mins . ' min</span></div>';
            echo '<div class="px-3 pt-2"><span class="badge text-bg-dark">' . e(ORDER_TYPES[$o['order_type']]) . '</span>';
            if ($o['table_name']) echo ' <span class="badge text-bg-secondary fs-6">' . e($o['table_name']) . '</span>';
            if ($o['customer_name']) echo ' <span class="small">' . e($o['customer_name']) . '</span>';
            echo '</div><ul>';
            foreach ($items[$o['id']] ?? [] as $it) {
                echo '<li><strong>' . (int)$it['quantity'] . '×</strong> ' . e($it['product_name']);
                if ($it['item_notes']) echo '<div class="small text-danger">↳ ' . e($it['item_notes']) . '</div>';
                echo '</li>';
            }
            echo '</ul>';
            if ($o['notes']) echo '<div class="k-note"><i class="bi bi-exclamation-circle"></i> ' . nl2br(e($o['notes'])) . '</div>';
            echo '<div class="k-foot d-flex gap-2"><button class="btn ' . $btnClass . ' flex-grow-1 k-btn" data-id="' . (int)$o['id'] . '" data-status="' . $nextSt . '"><i class="bi bi-' . $icon . '"></i> ' . e($btnLabel) . '</button>'
               . '<a class="btn btn-outline-secondary" target="_blank" title="Print kitchen ticket" href="' . url('pages/ticket.php?id=' . (int)$o['id'] . '&print=1') . '"><i class="bi bi-printer"></i></a></div></div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

if (get('partial') === '1') { kitchen_board(); exit; }

page_header('Kitchen Display', 'kitchen');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="text-muted small"><i class="bi bi-arrow-repeat"></i> Refreshes automatically every 8 seconds.</div>
  <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="sound" checked><label class="form-check-label small" for="sound">Sound for new orders</label></div>
</div>
<div id="kErr" class="alert alert-danger d-none"></div>
<div id="board"><?php kitchen_board(); ?></div>
<?php
$js = '<script>
(function(){
  const board=document.getElementById("board"); let lastPending=+(board.firstElementChild?.dataset.pending||0);
  function beep(){ if(!document.getElementById("sound").checked) return; try{const c=new (window.AudioContext||window.webkitAudioContext)();const o=c.createOscillator();const g=c.createGain();o.connect(g);g.connect(c.destination);o.frequency.value=880;g.gain.value=.15;o.start();o.stop(c.currentTime+.25);}catch(e){} }
  board.addEventListener("click", async function(e){
    const b=e.target.closest(".k-btn"); if(!b||b.disabled) return; b.disabled=true;
    const r=await postJSON(location.pathname,{order_id:b.dataset.id,status:b.dataset.status});
    const err=document.getElementById("kErr");
    if(!r.ok){ err.textContent=r.error; err.classList.remove("d-none"); } else err.classList.add("d-none");
    refresh(true);
  });
  async function refresh(force){
    try{
      const res=await fetch(location.pathname+"?partial=1",{credentials:"same-origin",headers:{"X-Requested-With":"fetch"}});
      if(!res.ok){ if(res.status===401) location.reload(); return; }
      board.innerHTML=await res.text();
      const p=+(board.firstElementChild?.dataset.pending||0); if(p>lastPending) beep(); lastPending=p;
    }catch(e){}
  }
  setInterval(()=>{ if(!document.hidden) refresh(); },8000);
})();
</script>';
page_footer($js);
