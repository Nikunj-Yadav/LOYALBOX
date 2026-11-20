<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();

if (is_post()) {
    $act = post_str('action', 20);
    if ($act === 'save') {
        $id = post_int('id', 0) ?? 0; $name = post_str('name', 120); $cost = post_int('points_cost', 1, 10000000);
        $stock = post_int('stock', 0, 1000000); $desc = post_str('description', 500); $active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '' || $cost === null || $stock === null) { flash('error', 'Check the form: name is required, cost must be 1 or more, stock 0 or more.'); }
        else {
            if ($id) { q('UPDATE rewards SET name=?, description=?, points_cost=?, stock=?, is_active=? WHERE id=?', [$name, $desc ?: null, $cost, $stock, $active, $id]); }
            else { q('INSERT INTO rewards (name, description, points_cost, stock, is_active) VALUES (?,?,?,?,?)', [$name, $desc ?: null, $cost, $stock, $active]); $id = (int)db()->lastInsertId(); }
            log_activity('admin', (int)$admin['id'], $_POST['id'] ? 'update_reward' : 'create_reward', 'reward', $id, "$name ($cost points, stock $stock)");
            flash('success', 'Reward saved.');
        }
    } elseif ($act === 'toggle' && ($id = post_int('id', 1))) {
        q('UPDATE rewards SET is_active = 1 - is_active WHERE id=?', [$id]);
        log_activity('admin', (int)$admin['id'], 'toggle_reward', 'reward', $id);
        flash('success', 'Reward status changed.');
    }
    redirect('rewards.php');
}
$list = fetch_all('SELECT r.*, (SELECT COUNT(*) FROM redemptions d WHERE d.reward_id=r.id) redeemed FROM rewards r ORDER BY r.id DESC');
$rows = []; foreach ($list as $r) {
    $fill = e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'points_cost' => $r['points_cost'], 'stock' => $r['stock'], 'description' => $r['description'], 'is_active' => (bool)$r['is_active']]));
    $rows[] = [$r['name'], ['html' => fmt_num($r['points_cost']), 'v' => $r['points_cost']], ['html' => fmt_num($r['stock']), 'v' => $r['stock']], ['html' => fmt_num($r['redeemed']), 'v' => $r['redeemed']], ['html' => badge($r['is_active'] ? 'active' : 'inactive')],
        ['html' => '<div class="actions"><button class="btn btn-small" type="button" data-dialog-open="#reward-dialog" data-fill="' . $fill . '">' . icon('edit') . '<span>Edit</span></button>'
            . action_form('toggle', ['id' => $r['id']], $r['is_active'] ? 'Deactivate' : 'Activate', $r['is_active'] ? 'ban' : 'play', $r['is_active'] ? 'Hide ' . $r['name'] . ' from customers?' : '', (bool)$r['is_active']) . '</div>']];
}
page_start(['title' => 'Rewards', 'subtitle' => 'What customers can spend points on.', 'active' => 'rewards', 'who' => $admin,
    'action' => '<button class="btn btn-primary" type="button" data-dialog-open="#reward-dialog">' . icon('plus') . 'Add reward</button>']);
echo data_table([['label' => 'Reward', 'sort' => 'text'], ['label' => 'Cost', 'sort' => 'num', 'class' => 'num'], ['label' => 'Stock', 'sort' => 'num', 'class' => 'num'], ['label' => 'Redeemed', 'sort' => 'num', 'class' => 'num'], ['label' => 'Status'], ['label' => 'Actions']], $rows,
    ['filter' => ['col' => 4, 'label' => 'All statuses', 'options' => ['active', 'inactive']], 'empty' => ['No rewards yet', 'Add a reward so customers have something to aim for.', '<button class="btn" type="button" data-dialog-open="#reward-dialog">Add reward</button>']]); ?>
<dialog class="modal" id="reward-dialog" aria-labelledby="rd-title"><form method="post" class="modal-body form" novalidate>
  <h2 id="rd-title">Reward</h2><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0">
  <?= field(['name' => 'name', 'label' => 'Name', 'attrs' => ['required' => true, 'maxlength' => 120]]) ?>
  <?= field(['name' => 'points_cost', 'label' => 'Points cost', 'type' => 'number', 'attrs' => ['required' => true, 'min' => 1]]) ?>
  <?= field(['name' => 'stock', 'label' => 'Stock', 'type' => 'number', 'value' => '0', 'attrs' => ['required' => true, 'min' => 0]]) ?>
  <?= field(['name' => 'description', 'label' => 'Description (optional)', 'type' => 'textarea', 'attrs' => ['maxlength' => 500]]) ?>
  <label class="check"><input type="checkbox" name="is_active" value="1" checked> Visible to customers</label>
  <div class="modal-actions"><button class="btn" type="button" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Save reward</button></div>
</form></dialog>
<?php page_end();
