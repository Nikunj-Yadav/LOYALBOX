<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();
$id = (int)get_str('id', 10);
$u = fetch_one('SELECT * FROM users WHERE id=?', [$id]);
if (!$u) { http_response_code(404); render_error_page('Customer not found', 'There is no customer with that ID.'); }

page_start(['title' => $u['name'], 'subtitle' => $u['email'] . ' · joined ' . fmt_dt($u['created_at']), 'active' => 'customers', 'who' => $admin,
    'action' => '<a class="btn" href="customers.php">All customers</a>']); ?>
<div class="stat-grid stagger">
  <?= stat_card('Points balance', (int)$u['points_balance'], '', 'star') ?>
  <?= stat_card('Status', ucfirst($u['status']), 'Last sign-in: ' . fmt_dt($u['last_login_at']), 'user', false) ?>
</div>
<div class="toolbar"><?= $u['status'] === 'active' ? action_form('block', ['id' => $id, 'back' => 1], 'Block customer', 'ban', 'Block ' . $u['name'] . '?', true) : action_form('unblock', ['id' => $id, 'back' => 1], 'Unblock customer', 'play') ?></div>
<?php
$post = fn($f) => $f;
$sections = [
 'Claims' => [fetch_all('SELECT c.created_at, p.name, c.points_awarded, c.ip FROM claims c JOIN products p ON p.id=c.product_id WHERE c.user_id=? ORDER BY c.id DESC LIMIT 100', [$id]),
     [['label' => 'When'], ['label' => 'Product'], ['label' => 'Points', 'class' => 'num'], ['label' => 'IP']], fn($r) => [fmt_dt($r['created_at']), $r['name'], fmt_num($r['points_awarded']), (string)$r['ip']]],
 'Ledger' => [fetch_all('SELECT created_at, type, points, balance_after, note FROM point_transactions WHERE user_id=? ORDER BY id DESC LIMIT 100', [$id]),
     [['label' => 'When'], ['label' => 'Type'], ['label' => 'Points', 'class' => 'num'], ['label' => 'Balance', 'class' => 'num'], ['label' => 'Note']], fn($r) => [fmt_dt($r['created_at']), ['html' => badge($r['type'])], ($r['points'] > 0 ? '+' : '') . fmt_num($r['points']), fmt_num($r['balance_after']), (string)$r['note']]],
 'Security events' => [fetch_all('SELECT created_at, event_type, ip, detail FROM security_events WHERE user_id=? OR identifier=? ORDER BY id DESC LIMIT 100', [$id, $u['email']]),
     [['label' => 'When'], ['label' => 'Event'], ['label' => 'IP'], ['label' => 'Detail']], fn($r) => [fmt_dt($r['created_at']), str_replace('_', ' ', $r['event_type']), $r['ip'], (string)$r['detail']]],
];
foreach ($sections as $title => [$data, $heads, $map]) {
    echo '<h2 class="section-title">' . e($title) . '</h2>';
    echo data_table($heads, array_map($map, $data), ['empty' => ['Nothing recorded', 'No ' . strtolower($title) . ' for this customer.']]);
}
page_end();
