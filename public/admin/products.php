<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();

if (is_post()) {
    $act = post_str('action', 20);
    if ($act === 'save') {
        $id = post_int('id', 0) ?? 0; $name = post_str('name', 120); $sku = strtoupper(post_str('sku', 40));
        $points = post_int('points', 1, 1000000); $desc = post_str('description', 500); $active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '' || !preg_match('/^[A-Z0-9._-]{2,40}$/', $sku) || $points === null) {
            flash('error', 'Check the form: name is required, SKU uses letters, numbers . _ - and points must be 1 or more.');
        } else try {
            if ($id) { q('UPDATE products SET name=?, sku=?, description=?, points=?, is_active=? WHERE id=?', [$name, $sku, $desc ?: null, $points, $active, $id]); }
            else { q('INSERT INTO products (name, sku, description, points, is_active) VALUES (?,?,?,?,?)', [$name, $sku, $desc ?: null, $points, $active]); $id = (int)db()->lastInsertId(); }
            log_activity('admin', (int)$admin['id'], $_POST['id'] ? 'update_product' : 'create_product', 'product', $id, "$name ($points points)");
            flash('success', 'Product saved.');
        } catch (PDOException $e) { if (($e->errorInfo[1] ?? 0) !== 1062) { throw $e; } flash('error', 'That SKU is already used by another product.'); }
    } elseif ($act === 'toggle' && ($id = post_int('id', 1))) {
        q('UPDATE products SET is_active = 1 - is_active WHERE id=?', [$id]);
        log_activity('admin', (int)$admin['id'], 'toggle_product', 'product', $id);
        flash('success', 'Product status changed.');
    }
    redirect('products.php');
}
$list = fetch_all('SELECT p.*, (SELECT COUNT(*) FROM product_codes c WHERE c.product_id=p.id) codes, (SELECT COUNT(*) FROM claims c WHERE c.product_id=p.id) claims FROM products p ORDER BY p.id DESC');
$rows = []; foreach ($list as $p) {
    $fill = e(json_encode(['id' => $p['id'], 'name' => $p['name'], 'sku' => $p['sku'], 'points' => $p['points'], 'description' => $p['description'], 'is_active' => (bool)$p['is_active']]));
    $rows[] = [$p['name'], $p['sku'], ['html' => fmt_num($p['points']), 'v' => $p['points']], ['html' => fmt_num($p['codes']), 'v' => $p['codes']], ['html' => fmt_num($p['claims']), 'v' => $p['claims']],
        ['html' => badge($p['is_active'] ? 'active' : 'inactive')],
        ['html' => '<div class="actions"><button class="btn btn-small" type="button" data-dialog-open="#product-dialog" data-fill="' . $fill . '">' . icon('edit') . '<span>Edit</span></button>'
            . action_form('toggle', ['id' => $p['id']], $p['is_active'] ? 'Deactivate' : 'Activate', $p['is_active'] ? 'ban' : 'play', $p['is_active'] ? 'Deactivate ' . $p['name'] . '? Its codes can no longer be claimed.' : '', (bool)$p['is_active']) . '</div>']];
}
page_start(['title' => 'Products', 'subtitle' => 'What you make and how many points each box is worth.', 'active' => 'products', 'who' => $admin,
    'action' => '<button class="btn btn-primary" type="button" data-dialog-open="#product-dialog">' . icon('plus') . 'Add product</button>']);
echo data_table([['label' => 'Product', 'sort' => 'text'], ['label' => 'SKU', 'sort' => 'text'], ['label' => 'Points', 'sort' => 'num', 'class' => 'num'], ['label' => 'Codes', 'sort' => 'num', 'class' => 'num'], ['label' => 'Claims', 'sort' => 'num', 'class' => 'num'], ['label' => 'Status'], ['label' => 'Actions']], $rows,
    ['filter' => ['col' => 5, 'label' => 'All statuses', 'options' => ['active', 'inactive']], 'empty' => ['No products yet', 'Add your first product, then generate codes for it.', '<button class="btn" type="button" data-dialog-open="#product-dialog">Add product</button>']]); ?>
<dialog class="modal" id="product-dialog" aria-labelledby="pd-title"><form method="post" class="modal-body form" novalidate>
  <h2 id="pd-title">Product</h2><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
  <?= field(['name' => 'name', 'label' => 'Name', 'attrs' => ['required' => true, 'maxlength' => 120]]) ?>
  <?= field(['name' => 'sku', 'label' => 'SKU', 'hint' => 'Letters, numbers . _ -', 'attrs' => ['required' => true, 'maxlength' => 40, 'pattern' => '[A-Za-z0-9._\-]{2,40}']]) ?>
  <?= field(['name' => 'points', 'label' => 'Points per box', 'type' => 'number', 'attrs' => ['required' => true, 'min' => 1, 'max' => 1000000]]) ?>
  <?= field(['name' => 'description', 'label' => 'Description (optional)', 'type' => 'textarea', 'attrs' => ['maxlength' => 500]]) ?>
  <label class="check"><input type="checkbox" name="is_active" value="1" checked> Active</label>
  <div class="modal-actions"><button class="btn" type="button" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Save product</button></div>
</form></dialog>
<?php page_end();
