<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
$admin = require_admin();

/** Stop spreadsheet programs from running a cell as a formula. */
function csv_safe(string $v): string { return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v; }

if (is_post()) {
    $act = post_str('action', 20);
    if ($act === 'generate') {
        $pid = post_int('product_id', 1); $qty = post_int('quantity', 1, 1000); $note = post_str('note', 200);
        $product = $pid ? fetch_one('SELECT id, name, sku, is_active FROM products WHERE id=?', [$pid]) : null;
        if (!$product || !$product['is_active'] || $qty === null) {
            flash('error', 'Choose an active product and a quantity from 1 to 1,000.');
            redirect('codes.php');
        }
        $res = create_code_batch((int)$product['id'], $qty, $note, (int)$admin['id']);
        log_activity('admin', (int)$admin['id'], 'generate_codes', 'batch', $res['batch_id'], "$qty codes for {$product['name']}");
        csrf_rotate();      // a browser refresh re-sends the old token and is rejected, so it cannot create a second batch
        $export = array_map(fn($c) => ['batch' => $res['batch_id'], 'sku' => csv_safe($product['sku']), 'product' => csv_safe($product['name']), 'code' => format_code($c), 'claim_url' => claim_url($c)], $res['codes']);
        page_start(['title' => 'Batch #' . $res['batch_id'] . ' created', 'subtitle' => 'Download the codes now. They are stored only as hashes and cannot be shown again.', 'active' => 'codes', 'who' => $admin,
            'action' => '<button class="btn btn-primary" type="button" id="download-codes" data-filename="loyalbox-batch-' . $res['batch_id'] . '.csv">' . icon('download') . 'Download CSV</button>']);
        echo '<script id="codes-data" type="application/json">' . json_encode($export, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
        $rows = []; foreach (array_slice($export, 0, 500) as $x) { $rows[] = [$x['code'], $x['product'], $x['claim_url']]; }
        echo data_table([['label' => 'Code'], ['label' => 'Product'], ['label' => 'Claim link']], $rows, ['empty' => ['No codes', ''], 'note' => count($export) > 500 ? 'Showing 500 of ' . count($export) . '. The CSV contains all of them.' : '']);
        echo '<p><a class="btn" href="codes.php">Back to codes</a></p>';
        page_end(); exit;
    } elseif (in_array($act, ['code_off', 'code_on'], true) && ($id = post_int('id', 1))) {
        $ok = set_code_active($id, $act === 'code_on');
        if ($ok) { log_activity('admin', (int)$admin['id'], $act === 'code_on' ? 'activate_code' : 'deactivate_code', 'code', $id); }
        $ok ? flash('success', $act === 'code_on' ? 'Code activated.' : 'Code deactivated.') : flash('error', 'That code cannot be changed (it may already be claimed).');
    } elseif (in_array($act, ['batch_off', 'batch_on'], true) && ($id = post_int('id', 1))) {
        $n = set_batch_active($id, $act === 'batch_on');
        log_activity('admin', (int)$admin['id'], $act === 'batch_on' ? 'activate_batch' : 'deactivate_batch', 'batch', $id, "$n codes changed");
        flash('success', fmt_num($n) . ' code' . ($n === 1 ? '' : 's') . ($act === 'batch_on' ? ' activated.' : ' deactivated.'));
    }
    redirect('codes.php' . (($_POST['back'] ?? '') === 'filtered' ? '?' . http_build_query(['batch' => post_int('batch', 0) ?: null]) : ''));
}

// Filters (server side, because there can be many thousands of codes).
$fStatus = get_str('status', 10); $fBatch = (int)get_str('batch', 10);
$where = ['1=1']; $args = [];
if (in_array($fStatus, ['active', 'inactive', 'claimed'], true)) { $where[] = 'c.status=?'; $args[] = $fStatus; }
if ($fBatch > 0) { $where[] = 'c.batch_id=?'; $args[] = $fBatch; }
$sqlWhere = implode(' AND ', $where);

if (get_str('export', 1) === '1') {      // metadata export: never contains plaintext codes
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="loyalbox-codes-export.csv"');
    $out = fopen('php://output', 'w'); fputcsv($out, ['code_id', 'batch_id', 'product_sku', 'code_ends_with', 'status', 'claimed_by', 'claimed_at', 'created_at']);
    foreach (fetch_all("SELECT c.id, c.batch_id, p.sku, c.code_hint, c.status, u.email, c.claimed_at, c.created_at FROM product_codes c JOIN products p ON p.id=c.product_id LEFT JOIN users u ON u.id=c.claimed_by WHERE $sqlWhere ORDER BY c.id DESC LIMIT 50000", $args) as $r) {
        fputcsv($out, array_map(fn($v) => csv_safe((string)$v), array_values($r)));
    }
    exit;
}

$products = fetch_all('SELECT id, name, sku FROM products WHERE is_active=1 ORDER BY name');
$batches = fetch_all("SELECT b.id, b.quantity, b.note, b.created_at, p.name product, a.name admin,
    SUM(c.status='active') n_active, SUM(c.status='inactive') n_inactive, SUM(c.status='claimed') n_claimed
    FROM product_batches b JOIN products p ON p.id=b.product_id JOIN admins a ON a.id=b.created_by LEFT JOIN product_codes c ON c.batch_id=b.id GROUP BY b.id ORDER BY b.id DESC LIMIT 100");
$codes = fetch_all("SELECT c.id, c.batch_id, c.code_hint, c.status, c.claimed_at, p.name product, u.email FROM product_codes c JOIN products p ON p.id=c.product_id LEFT JOIN users u ON u.id=c.claimed_by WHERE $sqlWhere ORDER BY c.id DESC LIMIT 500", $args);

$exportUrl = 'codes.php?' . http_build_query(array_filter(['export' => 1, 'status' => $fStatus ?: null, 'batch' => $fBatch ?: null]));
page_start(['title' => 'Codes', 'subtitle' => 'Single-use codes for the boxes you ship.', 'active' => 'codes', 'who' => $admin,
    'action' => $products ? '<button class="btn btn-primary" type="button" data-dialog-open="#gen-dialog">' . icon('plus') . 'Generate codes</button>' : '<a class="btn btn-primary" href="products.php">Add a product first</a>']);

echo '<h2 class="section-title">Batches</h2>';
$rows = []; foreach ($batches as $b) {
    $rows[] = ['#' . $b['id'], $b['product'], ['html' => fmt_num($b['quantity']), 'v' => $b['quantity']], ['html' => fmt_num($b['n_active']), 'v' => $b['n_active']], ['html' => fmt_num($b['n_claimed']), 'v' => $b['n_claimed']], ['html' => fmt_num($b['n_inactive']), 'v' => $b['n_inactive']],
        ['html' => e(fmt_dt($b['created_at'])), 'v' => strtotime($b['created_at'])],
        ['html' => '<div class="actions"><a class="btn btn-small" href="codes.php?batch=' . (int)$b['id'] . '">' . icon('eye') . '<span>View</span></a>'
            . action_form('batch_off', ['id' => $b['id']], 'Deactivate', 'ban', 'Deactivate all unclaimed codes in batch #' . $b['id'] . '?', true)
            . action_form('batch_on', ['id' => $b['id']], 'Activate', 'play', '') . '</div>']];
}
echo data_table([['label' => 'Batch', 'sort' => 'text'], ['label' => 'Product', 'sort' => 'text'], ['label' => 'Size', 'sort' => 'num', 'class' => 'num'], ['label' => 'Active', 'sort' => 'num', 'class' => 'num'], ['label' => 'Claimed', 'sort' => 'num', 'class' => 'num'], ['label' => 'Inactive', 'sort' => 'num', 'class' => 'num'], ['label' => 'Created', 'sort' => 'date', 'class' => 'nowrap'], ['label' => 'Actions']], $rows,
    ['empty' => ['No batches yet', 'Generate a batch of codes for one of your products.', $products ? '<button class="btn" type="button" data-dialog-open="#gen-dialog">Generate codes</button>' : '']]);

echo '<h2 class="section-title">Codes' . ($fBatch ? ' in batch #' . $fBatch : '') . '</h2><div class="toolbar"><form method="get" class="filter-form">'
   . ($fBatch ? '<input type="hidden" name="batch" value="' . $fBatch . '">' : '')
   . '<label class="filter"><span class="sr-only">Status</span><select name="status" data-autosubmit><option value="">All statuses</option>';
foreach (['active', 'inactive', 'claimed'] as $s) { echo '<option value="' . $s . '"' . ($fStatus === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>'; }
echo '</select></label></form><a class="btn" href="' . e($exportUrl) . '">' . icon('download') . 'Export list (no codes)</a>' . ($fBatch ? '<a class="btn" href="codes.php">Show all batches</a>' : '') . '</div>';
$rows = []; foreach ($codes as $c) {
    $btn = $c['status'] === 'active' ? action_form('code_off', ['id' => $c['id'], 'back' => $fBatch ? 'filtered' : '', 'batch' => $fBatch], 'Deactivate', 'ban', 'Deactivate this code?', true)
         : ($c['status'] === 'inactive' ? action_form('code_on', ['id' => $c['id'], 'back' => $fBatch ? 'filtered' : '', 'batch' => $fBatch], 'Activate', 'play') : '<span class="muted">Locked</span>');
    $rows[] = ['#' . $c['id'], '••••-••••-••••-' . $c['code_hint'], $c['product'], '#' . $c['batch_id'], ['html' => badge($c['status'])], (string)($c['email'] ?? '—'), ['html' => e(fmt_dt($c['claimed_at'])), 'v' => $c['claimed_at'] ? strtotime($c['claimed_at']) : 0], ['html' => $btn]];
}
echo data_table([['label' => 'ID', 'sort' => 'text'], ['label' => 'Code', 'class' => 'nowrap'], ['label' => 'Product', 'sort' => 'text'], ['label' => 'Batch'], ['label' => 'Status'], ['label' => 'Claimed by', 'sort' => 'text'], ['label' => 'Claimed at', 'sort' => 'date', 'class' => 'nowrap'], ['label' => 'Actions']], $rows,
    ['note' => count($codes) >= 500 ? 'Showing the newest 500 matching codes. Use the filters or export for more.' : '',
     'empty' => ['No codes match', 'Change the filter or generate a new batch.']]); ?>
<dialog class="modal" id="gen-dialog" aria-labelledby="gd-title"><form method="post" class="modal-body form" novalidate>
  <h2 id="gd-title">Generate codes</h2><?= csrf_field() ?><input type="hidden" name="action" value="generate">
  <?php $opts = []; foreach ($products as $p) { $opts[$p['id']] = $p['name'] . ' (' . $p['sku'] . ')'; }
  echo field(['name' => 'product_id', 'label' => 'Product', 'type' => 'select', 'options' => $opts, 'attrs' => ['required' => true]]);
  echo field(['name' => 'quantity', 'label' => 'How many codes', 'type' => 'number', 'value' => '100', 'hint' => '1 to 1,000 per batch.', 'attrs' => ['required' => true, 'min' => 1, 'max' => 1000]]);
  echo field(['name' => 'note', 'label' => 'Note (optional)', 'hint' => 'For example: production line 2, March.', 'attrs' => ['maxlength' => 200]]); ?>
  <div class="modal-actions"><button class="btn" type="button" data-dialog-close>Cancel</button><button class="btn btn-primary" type="submit">Generate</button></div>
</form></dialog>
<?php page_end();
