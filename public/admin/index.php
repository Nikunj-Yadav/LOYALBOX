<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();

$c = fn(string $sql) => (int)scalar($sql);
$flagged = (int)scalar("SELECT COUNT(*) FROM (SELECT user_id FROM security_events WHERE event_type='claim_failed' AND user_id IS NOT NULL AND created_at > (NOW() - INTERVAL 24 HOUR) GROUP BY user_id HAVING COUNT(*) >= 5) x");
$recent = fetch_all('SELECT actor_type, action, detail, created_at FROM activity_logs ORDER BY id DESC LIMIT 8');

page_start(['title' => 'Dashboard', 'subtitle' => 'Live numbers from your database.', 'active' => 'dashboard', 'who' => $admin,
    'action' => '<a class="btn btn-primary" href="codes.php">' . icon('key') . 'Generate codes</a>']); ?>
<p class="greeting" data-greeting data-name="<?= e(explode(' ', $admin['name'])[0]) ?>">Welcome back, <?= e(explode(' ', $admin['name'])[0]) ?>.</p>
<div class="stat-grid stagger">
  <?= stat_card('Customers', $c("SELECT COUNT(*) FROM users"), fmt_num($c("SELECT COUNT(*) FROM users WHERE status='blocked'")) . ' blocked', 'users') ?>
  <?= stat_card('Active products', $c('SELECT COUNT(*) FROM products WHERE is_active=1'), fmt_num($c('SELECT COUNT(*) FROM products')) . ' in total', 'box') ?>
  <?= stat_card('Codes generated', $c('SELECT COUNT(*) FROM product_codes'), fmt_num($c("SELECT COUNT(*) FROM product_codes WHERE status='active'")) . ' active · ' . fmt_num($c("SELECT COUNT(*) FROM product_codes WHERE status='claimed'")) . ' claimed', 'key') ?>
  <?= stat_card('Claims', $c('SELECT COUNT(*) FROM claims'), fmt_num($c('SELECT COUNT(*) FROM claims WHERE created_at >= CURDATE()')) . ' today', 'check') ?>
  <?= stat_card('Points issued', $c('SELECT COALESCE(SUM(points_awarded),0) FROM claims'), fmt_num($c('SELECT COALESCE(SUM(points_balance),0) FROM users')) . ' held by customers', 'star') ?>
  <?= stat_card('Pending redemptions', $c("SELECT COUNT(*) FROM redemptions WHERE status='pending'"), 'Waiting for fulfilment', 'gift') ?>
  <?= stat_card('Failed attempts (24 h)', $c("SELECT COUNT(*) FROM security_events WHERE event_type IN ('claim_failed','login_failed','admin_login_failed') AND created_at > (NOW() - INTERVAL 24 HOUR)"), fmt_num($flagged) . ' flagged account' . ($flagged === 1 ? '' : 's'), 'shield') ?>
</div>
<div class="two-col">
  <section class="card chart-card">
    <h2>Claims, last 14 days</h2>
    <div class="chart" id="claims-chart" data-src="api-stats.php"><div class="skeleton" aria-hidden="true"></div><span class="sr-only">Loading chart</span></div>
  </section>
  <section class="card">
    <h2>Recent activity</h2>
    <?php if (!$recent): echo empty_state('Nothing yet', 'Actions by admins and customers will appear here.');
    else: echo '<ul class="feed">'; foreach ($recent as $r) {
        echo '<li><span class="feed-main"><strong>' . e(str_replace('_', ' ', $r['action'])) . '</strong> by ' . e($r['actor_type']) . '</span><span class="muted">' . e((string)$r['detail']) . '</span><time class="muted">' . e(fmt_dt($r['created_at'])) . '</time></li>';
    } echo '</ul><p><a href="logs.php">Open the full log</a></p>'; endif; ?>
  </section>
</div>
<?php page_end();
