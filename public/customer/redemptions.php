<?php
define('AREA', 'customer');
require __DIR__ . '/../../src/bootstrap.php';
$user = require_customer();
$list = fetch_all('SELECT d.id, d.points_spent, d.status, d.created_at, r.name FROM redemptions d JOIN rewards r ON r.id=d.reward_id WHERE d.user_id=? ORDER BY d.id DESC LIMIT 500', [$user['id']]);
$rows = []; foreach ($list as $d) {
    $rows[] = ['#' . $d['id'], $d['name'], ['html' => fmt_num($d['points_spent']), 'v' => $d['points_spent']], ['html' => badge($d['status'])], ['html' => e(fmt_dt($d['created_at'])), 'v' => strtotime($d['created_at'])]];
}
page_start(['title' => 'My redemptions', 'subtitle' => 'Rewards you have redeemed and their status.', 'active' => 'redemptions', 'who' => $user,
    'action' => '<a class="btn btn-primary" href="rewards.php">' . icon('gift') . 'Browse rewards</a>']);
echo data_table([['label' => 'Ref'], ['label' => 'Reward', 'sort' => 'text'], ['label' => 'Points', 'sort' => 'num', 'class' => 'num'], ['label' => 'Status'], ['label' => 'Date', 'sort' => 'date', 'class' => 'nowrap']], $rows,
    ['filter' => ['col' => 3, 'label' => 'All statuses', 'options' => ['pending', 'fulfilled', 'cancelled']],
     'empty' => ['Nothing redeemed yet', 'Spend your points on a reward and it will show up here.', '<a class="btn" href="rewards.php">Browse rewards</a>']]);
page_end();
