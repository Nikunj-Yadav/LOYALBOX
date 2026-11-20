<?php
// JSON for the dashboard chart: claims per day, last 14 days. Admin only.
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
header('Content-Type: application/json');
if (!current_admin()) { http_response_code(401); echo '{"error":"auth"}'; exit; }
$found = [];
foreach (fetch_all('SELECT DATE(created_at) d, COUNT(*) n FROM claims WHERE created_at >= (CURDATE() - INTERVAL 13 DAY) GROUP BY DATE(created_at)') as $r) { $found[$r['d']] = (int)$r['n']; }
$out = [];
for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $out[] = ['date' => $d, 'label' => date('j M', strtotime($d)), 'claims' => $found[$d] ?? 0]; }
echo json_encode($out);
