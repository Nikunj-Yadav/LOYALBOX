<?php
define('AREA', 'customer');
require __DIR__ . '/../../src/bootstrap.php';
$user = require_customer();

if (is_post()) {
    $id = post_int('reward_id', 1);
    $r = $id ? redeem_reward((int)$user['id'], $id) : ['ok' => false, 'error' => 'Choose a reward to redeem.'];
    $r['ok'] ? flash('success', 'Redeemed ' . $r['reward'] . '. We will contact you about delivery.') : flash('error', $r['error']);
    redirect('rewards.php');
}
$rewards = fetch_all('SELECT id, name, description, points_cost, stock FROM rewards WHERE is_active=1 ORDER BY points_cost, id');
$bal = (int)$user['points_balance'];
page_start(['title' => 'Rewards', 'subtitle' => 'You have ' . fmt_num($bal) . ' points to spend.', 'active' => 'rewards', 'who' => $user]);
if (!$rewards) { echo '<section class="card">' . empty_state('No rewards yet', 'The factory has not published any rewards. Check back soon.') . '</section>'; }
else { echo '<div class="reward-grid stagger">';
foreach ($rewards as $r) {
    $cost = (int)$r['points_cost']; $stock = (int)$r['stock']; $can = $bal >= $cost && $stock > 0;
    $pct = min(100, (int)floor($bal / max(1, $cost) * 100)); ?>
  <article class="card reward">
    <?= icon('gift', 'icon reward-icon') ?>
    <h2><?= e($r['name']) ?></h2>
    <p class="muted"><?= e((string)$r['description']) ?></p>
    <p class="reward-cost"><strong><?= fmt_num($cost) ?></strong> points<span class="muted"> · <?= $stock > 0 ? fmt_num($stock) . ' left' : 'Out of stock' ?></span></p>
    <div class="meter" role="img" aria-label="You have <?= $pct ?> percent of the points needed"><progress max="100" value="<?= $pct ?>"></progress></div>
    <form method="post" data-confirm="Spend <?= fmt_num($cost) ?> points on <?= e($r['name']) ?>?">
      <?= csrf_field() ?><input type="hidden" name="reward_id" value="<?= (int)$r['id'] ?>">
      <button class="btn" type="submit" <?= $can ? '' : 'disabled' ?>><?= $stock < 1 ? 'Out of stock' : ($can ? 'Redeem' : fmt_num($cost - $bal) . ' points to go') ?></button>
    </form>
  </article>
<?php } echo '</div>'; }
page_end();
