<?php
declare(strict_types=1);

/* HTML building blocks shared by all pages. Everything printed here is escaped with e(). */

function nav_items(): array
{
    if (AREA === 'admin') {
        return [
            'dashboard' => ['index.php', 'Dashboard', 'home'],
            'products' => ['products.php', 'Products', 'box'],
            'codes' => ['codes.php', 'Codes', 'key'],
            'customers' => ['customers.php', 'Customers', 'users'],
            'rewards' => ['rewards.php', 'Rewards', 'gift'],
            'claims' => ['claims.php', 'Claims', 'check'],
            'redemptions' => ['redemptions.php', 'Redemptions', 'list'],
            'transactions' => ['transactions.php', 'Transactions', 'clock'],
            'security' => ['security.php', 'Security', 'shield'],
            'logs' => ['logs.php', 'Activity log', 'file'],
        ];
    }
    return [
        'dashboard' => ['index.php', 'Overview', 'home'],
        'claim' => ['claim.php', 'Claim a product', 'scan'],
        'rewards' => ['rewards.php', 'Rewards', 'gift'],
        'history' => ['history.php', 'Points history', 'clock'],
        'redemptions' => ['redemptions.php', 'My redemptions', 'list'],
        'profile' => ['profile.php', 'Profile', 'user'],
    ];
}

/**
 * Start a page.
 * $o keys: title, subtitle, action (trusted HTML for the primary button), active (nav key), bare (no sidebar), who (array|null)
 */
function page_start(array $o): void
{
    $title = (string)($o['title'] ?? '');
    $bare = !empty($o['bare']);
    $who = $o['who'] ?? null;
    $GLOBALS['lb_shell'] = !$bare;
    $brand = AREA === 'admin' ? 'LoyalBox Admin' : 'LoyalBox';
    echo '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' · ' . e($brand) . '</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;700&display=swap">';
    echo '<link rel="stylesheet" href="' . ASSETS . 'css/app.css">';
    echo '<script src="' . ASSETS . 'js/theme.js"></script>';      // runs before paint, so no theme flash
    echo '</head><body class="' . ($bare ? 'is-bare' : '') . '">';
    echo '<a class="skip-link" href="#main">Skip to content</a>';

    echo '<header class="topbar">';
    if (!$bare) {
        echo '<button class="icon-btn nav-toggle" type="button" aria-label="Open menu" aria-controls="sidebar" aria-expanded="false">' . icon('menu') . '</button>';
    }
    echo '<div class="brand">' . icon('box', 'icon brand-mark') . '<span>' . e($brand) . '</span></div>';
    echo '<div class="topbar-spacer"></div>';
    echo '<time class="today" datetime="' . date('Y-m-d') . '">' . date('l, j F Y') . '</time>';
    echo '<button class="icon-btn theme-toggle" type="button" aria-label="Switch to dark theme">' . icon('moon', 'icon icon-moon') . icon('sun', 'icon icon-sun') . '</button>';
    echo '</header>';

    if ($bare) {
        echo '<main id="main" class="auth-wrap stagger">';
    } else {
        $active = $o['active'] ?? '';
        echo '<div class="shell"><div class="scrim" hidden></div><aside class="sidebar" id="sidebar" aria-label="Main navigation"><nav><ul>';
        foreach (nav_items() as $key => [$href, $label, $ic]) {
            $on = $key === $active;
            echo '<li><a href="' . e($href) . '" class="nav-link' . ($on ? ' is-active' : '') . '"' . ($on ? ' aria-current="page"' : '') . '>'
               . icon($ic) . '<span>' . e($label) . '</span></a></li>';
        }
        echo '</ul></nav>';
        if ($who) {
            echo '<div class="sidebar-foot"><div class="who"><strong>' . e($who['name']) . '</strong><span>' . e($who['email']) . '</span></div>'
               . '<form method="post" action="logout.php">' . csrf_field() . '<button class="btn btn-block" type="submit">' . icon('logout') . 'Sign out</button></form></div>';
        }
        echo '</aside><main id="main" class="content">';
        echo '<div class="page-head stagger"><div><h1>' . e($title) . '</h1>';
        if (!empty($o['subtitle'])) { echo '<p class="subtitle">' . e($o['subtitle']) . '</p>'; }
        echo '</div>';
        if (!empty($o['action'])) { echo '<div class="page-action">' . $o['action'] . '</div>'; }
        echo '</div>';
    }
}

function page_end(): void
{
    $flashes = take_flashes();
    echo '</main>' . (!empty($GLOBALS['lb_shell']) ? '</div>' : '');   // close <main> and the sidebar shell
    echo '<div class="toasts" role="region" aria-label="Notifications" aria-live="polite"></div>';
    echo '<dialog class="modal" id="confirm-dialog" aria-labelledby="confirm-title"><div class="modal-body">'
       . '<h2 id="confirm-title">Are you sure?</h2><p id="confirm-text"></p>'
       . '<div class="modal-actions"><button class="btn" type="button" data-confirm-cancel>Cancel</button>'
       . '<button class="btn btn-primary" type="button" data-confirm-ok>Confirm</button></div></div></dialog>';
    echo '<script id="flash-data" type="application/json">' . json_encode($flashes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
    echo '<script src="' . ASSETS . 'js/app.js" defer></script></body></html>';
}

/** Standalone error page (also used for CSRF failures and uncaught exceptions). */
function render_error_page(string $title, string $message): never
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!defined('AREA')) { define('AREA', 'customer'); }
    if (!defined('ASSETS')) { define('ASSETS', '../assets/'); }
    page_start(['title' => $title, 'bare' => true]);
    echo '<section class="card auth-card"><h1>' . e($title) . '</h1><p class="subtitle">' . e($message) . '</p>'
       . '<p><a class="btn btn-primary" href="javascript:history.back()">Go back</a></p></section>';
    echo '</main><div class="toasts"></div><script src="' . ASSETS . 'js/app.js" defer></script></body></html>';
    exit;
}

/** A labelled form field with inline error. $f: name, label, type, value, error, hint, attrs[] */
function field(array $f): string
{
    $name = $f['name'];
    $id = 'f-' . $name;
    $type = $f['type'] ?? 'text';
    $attrs = '';
    foreach (($f['attrs'] ?? []) as $k => $v) {
        $attrs .= $v === true ? ' ' . e($k) : ' ' . e($k) . '="' . e((string)$v) . '"';
    }
    $err = $f['error'] ?? '';
    $hint = $f['hint'] ?? '';
    $describedBy = trim(($hint ? $id . '-hint ' : '') . $id . '-error');
    $html = '<div class="field' . ($err ? ' has-error' : '') . '"><label for="' . e($id) . '">' . e($f['label']) . '</label>';
    if ($type === 'textarea') {
        $html .= '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="3" aria-describedby="' . e($describedBy) . '"' . $attrs . '>' . e($f['value'] ?? '') . '</textarea>';
    } elseif ($type === 'select') {
        $html .= '<select id="' . e($id) . '" name="' . e($name) . '" aria-describedby="' . e($describedBy) . '"' . $attrs . '>';
        foreach ($f['options'] as $val => $label) {
            $html .= '<option value="' . e((string)$val) . '"' . ((string)$val === (string)($f['value'] ?? '') ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        $html .= '</select>';
    } else {
        $html .= '<input id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e((string)($f['value'] ?? '')) . '" aria-describedby="' . e($describedBy) . '"' . $attrs . '>';
    }
    if ($hint) { $html .= '<small class="hint" id="' . e($id) . '-hint">' . e($hint) . '</small>'; }
    return $html . '<small class="field-error" id="' . e($id) . '-error" role="alert">' . e($err) . '</small></div>';
}

/** Coloured status badge. Always carries a text label, never colour alone. */
function badge(string $status): string
{
    static $tone = [
        'active' => 'ok', 'fulfilled' => 'ok', 'claim' => 'ok', 'refund' => 'ok', 'open' => 'ok',
        'pending' => 'warn', 'inactive' => 'warn', 'claimed' => 'info', 'redeem' => 'info',
        'blocked' => 'danger', 'cancelled' => 'danger', 'flagged' => 'danger',
    ];
    return '<span class="badge badge-' . ($tone[$status] ?? 'info') . '">' . e(ucfirst($status)) . '</span>';
}

function empty_state(string $title, string $text, string $actionHtml = ''): string
{
    return '<div class="empty">' . icon('info', 'icon empty-icon') . '<h2>' . e($title) . '</h2><p>' . e($text) . '</p>' . $actionHtml . '</div>';
}

function stat_card(string $label, int|string $value, string $hint = '', string $ic = 'star', bool $count = true): string
{
    $v = is_int($value) && $count ? '<span class="stat-value" data-count="' . $value . '">' . fmt_num($value) . '</span>' : '<span class="stat-value">' . e((string)$value) . '</span>';
    return '<div class="card stat">' . icon($ic, 'icon stat-icon') . '<span class="stat-label">' . e($label) . '</span>' . $v
         . ($hint ? '<span class="stat-hint">' . e($hint) . '</span>' : '') . '</div>';
}

/**
 * Searchable, sortable, filterable table. Filtering and sorting run in the browser (see app.js).
 *
 * $headers: [['label'=>'Name','sort'=>'text'|'num'|'date'|null, 'class'=>'num'?], ...]
 * $rows:    list of rows; each cell is a plain string (escaped here) or ['html'=>..., 'v'=>sortValue].
 * $o:       id, filter => ['col'=>int,'label'=>'All statuses','options'=>[...]], empty => [title,text,actionHtml], note
 */
function data_table(array $headers, array $rows, array $o = []): string
{
    if (!$rows) {
        [$t, $x, $a] = $o['empty'] + [2 => ''];
        return '<section class="card table-card">' . empty_state($t, $x, $a) . '</section>';
    }
    $h = '<section class="card table-card" data-table><div class="table-tools">'
       . '<label class="search">' . icon('search') . '<span class="sr-only">Search this table</span>'
       . '<input type="search" placeholder="Search…  ( / )" data-table-search autocomplete="off"></label>';
    if (!empty($o['filter'])) {
        $h .= '<label class="filter"><span class="sr-only">' . e($o['filter']['label']) . '</span><select data-table-filter data-col="' . (int)$o['filter']['col'] . '"><option value="">' . e($o['filter']['label']) . '</option>';
        foreach ($o['filter']['options'] as $opt) { $h .= '<option value="' . e(strtolower($opt)) . '">' . e(ucfirst($opt)) . '</option>'; }
        $h .= '</select></label>';
    }
    $h .= '<span class="counter" data-table-count aria-live="polite"></span></div><div class="table-scroll"><table><thead><tr>';
    foreach ($headers as $col) {
        $cls = !empty($col['class']) ? ' class="' . e($col['class']) . '"' : '';
        $h .= !empty($col['sort'])
            ? '<th' . $cls . ' scope="col" aria-sort="none"><button type="button" class="sort" data-sort="' . e($col['sort']) . '">' . e($col['label']) . '<span class="sort-arrow" aria-hidden="true"></span></button></th>'
            : '<th' . $cls . ' scope="col">' . e($col['label']) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $h .= '<tr>';
        foreach ($row as $i => $cell) {
            $cls = !empty($headers[$i]['class']) ? ' class="' . e($headers[$i]['class']) . '"' : '';
            if (is_array($cell)) {
                $v = isset($cell['v']) ? ' data-v="' . e((string)$cell['v']) . '"' : '';
                $h .= '<td' . $cls . $v . '>' . $cell['html'] . '</td>';
            } else {
                $h .= '<td' . $cls . '>' . e((string)$cell) . '</td>';
            }
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table></div><div class="empty" data-table-empty hidden>' . icon('search', 'icon empty-icon') . '<h2>No matches</h2><p>Try a different search or clear the filter.</p></div>';
    if (!empty($o['note'])) { $h .= '<p class="table-note">' . e($o['note']) . '</p>'; }
    return $h . '</section>';
}

/** Small POST button (own form + CSRF). $confirm adds the custom confirmation dialog. */
function action_form(string $action, array $hidden, string $label, string $iconName, string $confirm = '', bool $danger = false): string
{
    $f = '<form method="post" class="inline-form"' . ($confirm ? ' data-confirm="' . e($confirm) . '"' . ($danger ? ' data-danger' : '') : '') . '>'
       . csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($hidden as $k => $v) { $f .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">'; }
    return $f . '<button class="btn btn-small' . ($danger ? ' btn-danger' : '') . '" type="submit" title="' . e($label) . '">' . icon($iconName) . '<span>' . e($label) . '</span></button></form>';
}
