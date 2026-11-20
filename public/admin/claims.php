<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();
$list = fetch_all('SELECT c.id, c.created_at, c.points_awarded, c.ip, u.id uid, u.name, u.email, p.name product, k.code_hint FROM claims c JOIN users u ON u.id=c.user_id JOIN products p ON p.id=c.product_id JOIN product_codes k ON k.id=c.code_id ORDER BY c.id DESC LIMIT 500');
$rows = []; foreach ($list as $c) {
    $rows[] = ['#' . $c['id'], ['html' => '<a href="customer.php?id=' . (int)$c['uid'] . '">' . e($c['name']) . '</a><br><span class="muted">' . e($c['email']) . '</span>', 'v' => $c['name']], $c['product'], '…' . $c['code_hint'], ['html' => fmt_num($c['points_awarded']), 'v' => $c['points_awarded']], (string)$c['ip'], ['html' => e(fmt_dt($c['created_at'])), 'v' => strtotime($c['created_at'])]];
}
page_start(['title' => 'Claims', 'subtitle' => 'Every successful code claim.', 'active' => 'claims', 'who' => $admin]);
echo data_table([['label' => 'Ref'], ['label' => 'Customer', 'sort' => 'text'], ['label' => 'Product', 'sort' => 'text'], ['label' => 'Code'], ['label' => 'Points', 'sort' => 'num', 'class' => 'num'], ['label' => 'IP'], ['label' => 'When', 'sort' => 'date', 'class' => 'nowrap']], $rows,
    ['note' => count($list) >= 500 ? 'Showing the newest 500 claims.' : '', 'empty' => ['No claims yet', 'Claims appear when customers enter valid codes.']]);
page_end();
