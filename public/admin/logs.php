<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();
$list = fetch_all("SELECT l.created_at, l.actor_type, l.action, l.entity, l.entity_id, l.detail, l.ip, COALESCE(a.name, u.name) actor FROM activity_logs l LEFT JOIN admins a ON l.actor_type='admin' AND a.id=l.actor_id LEFT JOIN users u ON l.actor_type='customer' AND u.id=l.actor_id ORDER BY l.id DESC LIMIT 500");
$rows = []; foreach ($list as $l) {
    $rows[] = [['html' => e(fmt_dt($l['created_at'])), 'v' => strtotime($l['created_at'])], $l['actor_type'], (string)($l['actor'] ?? '—'), str_replace('_', ' ', $l['action']), trim(($l['entity'] ?? '') . ' ' . ($l['entity_id'] ? '#' . $l['entity_id'] : '')), (string)$l['detail'], (string)$l['ip']];
}
page_start(['title' => 'Activity log', 'subtitle' => 'Who did what, newest first.', 'active' => 'logs', 'who' => $admin]);
echo data_table([['label' => 'When', 'sort' => 'date', 'class' => 'nowrap'], ['label' => 'Role'], ['label' => 'Who', 'sort' => 'text'], ['label' => 'Action', 'sort' => 'text'], ['label' => 'Item'], ['label' => 'Detail'], ['label' => 'IP']], $rows,
    ['filter' => ['col' => 1, 'label' => 'All roles', 'options' => ['admin', 'customer', 'system']], 'note' => count($list) >= 500 ? 'Showing the newest 500 entries.' : '', 'empty' => ['No activity yet', 'Admin and customer actions will be logged here.']]);
page_end();
