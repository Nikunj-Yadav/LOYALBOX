<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();
$list = fetch_all('SELECT t.id, t.created_at, t.type, t.points, t.balance_after, t.note, u.id uid, u.name FROM point_transactions t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 500');
$rows = []; foreach ($list as $t) {
    $rows[] = ['#' . $t['id'], ['html' => '<a href="customer.php?id=' . (int)$t['uid'] . '">' . e($t['name']) . '</a>', 'v' => $t['name']], ['html' => badge($t['type'])], ['html' => ($t['points'] > 0 ? '+' : '') . fmt_num($t['points']), 'v' => $t['points']], ['html' => fmt_num($t['balance_after']), 'v' => $t['balance_after']], (string)$t['note'], ['html' => e(fmt_dt($t['created_at'])), 'v' => strtotime($t['created_at'])]];
}
page_start(['title' => 'Transactions', 'subtitle' => 'The points ledger. Rows are only ever added, never edited.', 'active' => 'transactions', 'who' => $admin]);
echo data_table([['label' => 'Ref'], ['label' => 'Customer', 'sort' => 'text'], ['label' => 'Type'], ['label' => 'Points', 'sort' => 'num', 'class' => 'num'], ['label' => 'Balance after', 'sort' => 'num', 'class' => 'num'], ['label' => 'Note'], ['label' => 'When', 'sort' => 'date', 'class' => 'nowrap']], $rows,
    ['filter' => ['col' => 2, 'label' => 'All types', 'options' => ['claim', 'redeem', 'refund']], 'note' => count($list) >= 500 ? 'Showing the newest 500 transactions.' : '', 'empty' => ['No transactions yet', 'Points movements will be listed here.']]);
page_end();
