<?php
define('AREA', 'customer');
require __DIR__ . '/../../src/bootstrap.php';
$user = require_customer();

$earned = (int)scalar("SELECT COALESCE(SUM(points),0) FROM point_transactions WHERE user_id=? AND points>0", [$user['id']]);
$spent  = -(int)scalar("SELECT COALESCE(SUM(points),0) FROM point_transactions WHERE user_id=? AND type='redeem'", [$user['id']]);
$claims = (int)scalar('SELECT COUNT(*) FROM claims WHERE user_id=?', [$user['id']]);
$recent = fetch_all('SELECT type, points, balance_after, note, created_at FROM point_transactions WHERE user_id=? ORDER BY id DESC LIMIT 6', [$user['id']]);
$next = fetch_one('SELECT name, points_cost FROM rewards WHERE is_active=1 AND stock>0 AND points_cost > ? ORDER BY points_cost LIMIT 1', [$user['points_balance']]);

page_start(['title' => 'Overview', 'subtitle' => 'Your points at a glance.', 'active' => 'dashboard', 'who' => $user,
    'action' => '<a class="btn btn-primary" href="claim.php">' . icon('scan') . 'Claim a product</a>']); ?>
<p class="greeting" data-greeting data-name="<?= e(explode(' ', $user['name'])[0]) ?>">Welcome back, <?= e(explode(' ', $user['name'])[0]) ?>.</p>
<div class="stat-grid stagger">
  <?= stat_card('Points balance', (int)$user['points_balance'], $next ? fmt_num((int)$next['points_cost'] - (int)$user['points_balance']) . ' to ' . $next['name'] : 'Browse rewards to spend them', 'star') ?>
  <?= stat_card('Total earned', $earned, 'From ' . fmt_num($claims) . ' claimed product' . ($claims === 1 ? '' : 's'), 'check') ?>
  <?= stat_card('Total spent', $spent, 'On rewards', 'gift') ?>
</div>
<h2 class="section-title">Recent activity</h2>
<?php $rows = []; foreach ($recent as $t) {
    $rows[] = [fmt_dt($t['created_at']), ['html' => badge($t['type'])], (string)$t['note'], ['html' => ($t['points'] > 0 ? '+' : '') . fmt_num($t['points']), 'v' => $t['points']], fmt_num($t['balance_after'])];
}
echo data_table([['label' => 'When'], ['label' => 'Type'], ['label' => 'Details'], ['label' => 'Points', 'class' => 'num'], ['label' => 'Balance', 'class' => 'num']], $rows,
    ['empty' => ['No activity yet', 'Claim the code inside a product box to earn your first points.', '<a class="btn" href="claim.php">Claim a product</a>']]);
page_end();
