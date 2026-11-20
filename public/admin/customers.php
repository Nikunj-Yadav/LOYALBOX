<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();
if (is_post()) {
    $id = post_int('id', 1); $act = post_str('action', 10);
    if ($id && in_array($act, ['block', 'unblock'], true)) {
        q('UPDATE users SET status=? WHERE id=?', [$act === 'block' ? 'blocked' : 'active', $id]);
        log_activity('admin', (int)$admin['id'], $act . '_customer', 'user', $id);
        flash('success', $act === 'block' ? 'Customer blocked. They can no longer sign in.' : 'Customer unblocked.');
    }
    redirect($_POST['back'] ?? '' ? 'customer.php?id=' . (int)$id : 'customers.php');
}
$list = fetch_all("SELECT u.*, (SELECT COUNT(*) FROM claims c WHERE c.user_id=u.id) claims,
    (SELECT COUNT(*) FROM security_events s WHERE s.user_id=u.id AND s.event_type='claim_failed' AND s.created_at > (NOW() - INTERVAL 24 HOUR)) fails
    FROM users u ORDER BY u.id DESC LIMIT 500");
$rows = []; foreach ($list as $u) {
    $rows[] = [$u['name'], $u['email'], (string)($u['phone'] ?? '—'), ['html' => fmt_num($u['points_balance']), 'v' => $u['points_balance']], ['html' => fmt_num($u['claims']), 'v' => $u['claims']],
        ['html' => $u['fails'] >= 5 ? badge('flagged') . ' ' . e((string)$u['fails']) : e((string)$u['fails']), 'v' => $u['fails']], ['html' => badge($u['status'])], ['html' => e(fmt_dt($u['created_at'])), 'v' => strtotime($u['created_at'])],
        ['html' => '<div class="actions"><a class="btn btn-small" href="customer.php?id=' . (int)$u['id'] . '">' . icon('eye') . '<span>View</span></a>'
            . ($u['status'] === 'active' ? action_form('block', ['id' => $u['id']], 'Block', 'ban', 'Block ' . $u['name'] . '? They will be signed out and cannot claim or redeem.', true) : action_form('unblock', ['id' => $u['id']], 'Unblock', 'play')) . '</div>']];
}
page_start(['title' => 'Customers', 'subtitle' => 'Everyone who has registered.', 'active' => 'customers', 'who' => $admin]);
echo data_table([['label' => 'Name', 'sort' => 'text'], ['label' => 'E-mail', 'sort' => 'text'], ['label' => 'Phone'], ['label' => 'Points', 'sort' => 'num', 'class' => 'num'], ['label' => 'Claims', 'sort' => 'num', 'class' => 'num'], ['label' => 'Failed claims 24 h', 'sort' => 'num', 'class' => 'num'], ['label' => 'Status'], ['label' => 'Joined', 'sort' => 'date'], ['label' => 'Actions']], $rows,
    ['filter' => ['col' => 6, 'label' => 'All statuses', 'options' => ['active', 'blocked']], 'empty' => ['No customers yet', 'Customers appear here once they register.']]);
page_end();
