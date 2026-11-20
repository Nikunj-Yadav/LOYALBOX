<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();
if (is_post()) {
    $id = post_int('id', 1); $act = post_str('action', 12);
    $to = ['fulfil' => 'fulfilled', 'cancel' => 'cancelled'][$act] ?? null;
    if ($id && $to) {
        $r = update_redemption($id, $to, (int)$admin['id']);
        $r['ok'] ? flash('success', $to === 'fulfilled' ? 'Marked as fulfilled.' : 'Cancelled. Points were refunded and stock restored.') : flash('error', $r['error']);
    }
    redirect('redemptions.php');
}
$list = fetch_all('SELECT d.id, d.points_spent, d.status, d.created_at, u.id uid, u.name, u.email, r.name reward FROM redemptions d JOIN users u ON u.id=d.user_id JOIN rewards r ON r.id=d.reward_id ORDER BY d.id DESC LIMIT 500');
$rows = []; foreach ($list as $d) {
    $acts = $d['status'] === 'pending' ? '<div class="actions">' . action_form('fulfil', ['id' => $d['id']], 'Fulfil', 'check', 'Mark redemption #' . $d['id'] . ' as fulfilled?')
        . action_form('cancel', ['id' => $d['id']], 'Cancel & refund', 'undo', 'Cancel redemption #' . $d['id'] . ' and refund ' . fmt_num($d['points_spent']) . ' points?', true) . '</div>' : '<span class="muted">Closed</span>';
    $rows[] = ['#' . $d['id'], ['html' => '<a href="customer.php?id=' . (int)$d['uid'] . '">' . e($d['name']) . '</a>', 'v' => $d['name']], $d['reward'], ['html' => fmt_num($d['points_spent']), 'v' => $d['points_spent']], ['html' => badge($d['status'])], ['html' => e(fmt_dt($d['created_at'])), 'v' => strtotime($d['created_at'])], ['html' => $acts]];
}
page_start(['title' => 'Redemptions', 'subtitle' => 'Reward requests from customers.', 'active' => 'redemptions', 'who' => $admin]);
echo data_table([['label' => 'Ref'], ['label' => 'Customer', 'sort' => 'text'], ['label' => 'Reward', 'sort' => 'text'], ['label' => 'Points', 'sort' => 'num', 'class' => 'num'], ['label' => 'Status'], ['label' => 'Date', 'sort' => 'date', 'class' => 'nowrap'], ['label' => 'Actions']], $rows,
    ['filter' => ['col' => 4, 'label' => 'All statuses', 'options' => ['pending', 'fulfilled', 'cancelled']], 'empty' => ['No redemptions yet', 'Requests appear when customers redeem rewards.']]);
page_end();
