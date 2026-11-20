<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();
$fail = "event_type IN ('claim_failed','login_failed','admin_login_failed')";
$d24 = 'created_at > (NOW() - INTERVAL 24 HOUR)';

$badUsers = fetch_all("SELECT u.id, u.name, u.email, u.status, COUNT(*) n, MAX(s.created_at) last_at FROM security_events s JOIN users u ON u.id=s.user_id WHERE s.event_type='claim_failed' AND s.$d24 GROUP BY u.id HAVING n >= 5 ORDER BY n DESC");
$badIps = fetch_all("SELECT ip, COUNT(*) n, COUNT(DISTINCT COALESCE(user_id, identifier)) who, MAX(created_at) last_at FROM security_events WHERE $fail AND $d24 GROUP BY ip HAVING n >= 10 ORDER BY n DESC");
$badMail = fetch_all("SELECT identifier, event_type, COUNT(*) n, MAX(created_at) last_at FROM security_events WHERE event_type IN ('login_failed','admin_login_failed') AND identifier IS NOT NULL AND $d24 GROUP BY identifier, event_type HAVING n >= 5 ORDER BY n DESC");
$events = fetch_all('SELECT s.created_at, s.event_type, s.ip, s.identifier, s.detail, u.name FROM security_events s LEFT JOIN users u ON u.id=s.user_id ORDER BY s.id DESC LIMIT 300');
$types = array_column(fetch_all('SELECT DISTINCT event_type FROM security_events ORDER BY 1'), 'event_type');

page_start(['title' => 'Security', 'subtitle' => 'Repeated failures and suspicious behaviour in the last 24 hours.', 'active' => 'security', 'who' => $admin]); ?>
<div class="stat-grid stagger">
  <?= stat_card('Failed claims', (int)scalar("SELECT COUNT(*) FROM security_events WHERE event_type='claim_failed' AND $d24"), 'Last 24 hours', 'key') ?>
  <?= stat_card('Failed sign-ins', (int)scalar("SELECT COUNT(*) FROM security_events WHERE event_type IN ('login_failed','admin_login_failed') AND $d24"), 'Last 24 hours', 'user') ?>
  <?= stat_card('Rate-limit blocks', (int)scalar("SELECT COUNT(*) FROM security_events WHERE event_type IN ('claim_rate_limited','login_rate_limited') AND $d24"), 'Attempts refused', 'shield') ?>
  <?= stat_card('Flagged accounts', count($badUsers), '5+ failed claims', 'alert') ?>
</div>
<h2 class="section-title">Accounts with repeated failed claims</h2>
<?php $rows = []; foreach ($badUsers as $b) {
    $rows[] = [['html' => '<a href="customer.php?id=' . (int)$b['id'] . '">' . e($b['name']) . '</a><br><span class="muted">' . e($b['email']) . '</span>', 'v' => $b['name']], ['html' => fmt_num($b['n']), 'v' => $b['n']], ['html' => e(fmt_dt($b['last_at'])), 'v' => strtotime($b['last_at'])], ['html' => badge($b['status'])],
        ['html' => $b['status'] === 'active' ? action_form('block', ['id' => $b['id']], 'Block', 'ban', 'Block ' . $b['name'] . '?', true) : '<span class="muted">Blocked</span>']];
}
echo data_table([['label' => 'Customer', 'sort' => 'text'], ['label' => 'Failures', 'sort' => 'num', 'class' => 'num'], ['label' => 'Last attempt', 'sort' => 'date', 'class' => 'nowrap'], ['label' => 'Status'], ['label' => 'Action']], $rows, ['empty' => ['Nothing suspicious', 'No account has 5 or more failed claims in the last 24 hours.']]); ?>
<h2 class="section-title">Networks with many failures</h2>
<?php $rows = []; foreach ($badIps as $b) { $rows[] = [$b['ip'], ['html' => fmt_num($b['n']), 'v' => $b['n']], ['html' => fmt_num($b['who']), 'v' => $b['who']], ['html' => e(fmt_dt($b['last_at'])), 'v' => strtotime($b['last_at'])]]; }
echo data_table([['label' => 'IP address', 'sort' => 'text'], ['label' => 'Failures', 'sort' => 'num', 'class' => 'num'], ['label' => 'Accounts / e-mails tried', 'sort' => 'num', 'class' => 'num'], ['label' => 'Last attempt', 'sort' => 'date', 'class' => 'nowrap']], $rows, ['empty' => ['Nothing suspicious', 'No IP address has 10 or more failures in the last 24 hours.']]); ?>
<h2 class="section-title">E-mails with repeated failed sign-ins</h2>
<?php $rows = []; foreach ($badMail as $b) { $rows[] = [(string)$b['identifier'], $b['event_type'] === 'admin_login_failed' ? 'Admin' : 'Customer', ['html' => fmt_num($b['n']), 'v' => $b['n']], ['html' => e(fmt_dt($b['last_at'])), 'v' => strtotime($b['last_at'])]]; }
echo data_table([['label' => 'E-mail', 'sort' => 'text'], ['label' => 'Area'], ['label' => 'Failures', 'sort' => 'num', 'class' => 'num'], ['label' => 'Last attempt', 'sort' => 'date', 'class' => 'nowrap']], $rows, ['empty' => ['Nothing suspicious', 'No e-mail has 5 or more failed sign-ins in the last 24 hours.']]); ?>
<h2 class="section-title">Recent security events</h2>
<?php $rows = []; foreach ($events as $s) { $rows[] = [['html' => e(fmt_dt($s['created_at'])), 'v' => strtotime($s['created_at'])], str_replace('_', ' ', $s['event_type']), $s['ip'], (string)($s['name'] ?? $s['identifier'] ?? '—'), (string)$s['detail']]; }
echo data_table([['label' => 'When', 'sort' => 'date', 'class' => 'nowrap'], ['label' => 'Event'], ['label' => 'IP', 'sort' => 'text'], ['label' => 'Who'], ['label' => 'Detail']], $rows,
    ['filter' => ['col' => 1, 'label' => 'All events', 'options' => array_map(fn($t) => str_replace('_', ' ', $t), $types)], 'empty' => ['No events yet', 'Failed attempts and blocks will be recorded here.']]);
page_end();
