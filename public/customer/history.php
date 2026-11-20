<?php
define('AREA', 'customer');
require __DIR__ . '/../../src/bootstrap.php';
$user = require_customer();
$tx = fetch_all('SELECT type, points, balance_after, note, created_at FROM point_transactions WHERE user_id=? ORDER BY id DESC LIMIT 500', [$user['id']]);
$rows = []; foreach ($tx as $t) {
    $rows[] = [['html' => e(fmt_dt($t['created_at'])), 'v' => strtotime($t['created_at'])], ['html' => badge($t['type'])], (string)$t['note'],
        ['html' => ($t['points'] > 0 ? '+' : '') . fmt_num($t['points']), 'v' => $t['points']], ['html' => fmt_num($t['balance_after']), 'v' => $t['balance_after']]];
}
page_start(['title' => 'Points history', 'subtitle' => 'Every change to your balance, newest first.', 'active' => 'history', 'who' => $user,
    'action' => '<a class="btn btn-primary" href="claim.php">' . icon('scan') . 'Claim a product</a>']);
echo data_table([['label' => 'When', 'sort' => 'date', 'class' => 'nowrap'], ['label' => 'Type'], ['label' => 'Details', 'sort' => 'text'], ['label' => 'Points', 'sort' => 'num', 'class' => 'num'], ['label' => 'Balance', 'sort' => 'num', 'class' => 'num']], $rows,
    ['filter' => ['col' => 1, 'label' => 'All types', 'options' => ['claim', 'redeem', 'refund']],
     'empty' => ['No transactions yet', 'Your claims and redemptions will appear here.', '<a class="btn" href="claim.php">Claim a product</a>']]);
page_end();
