<?php
/**
 * Setup check: shows what works and what is missing. Only opens on the computer running the server.
 * Open it at http://localhost/loyalbox/public/check.php
 */
define('AREA', 'customer');
define('ASSETS', 'assets/');
require __DIR__ . '/../src/bootstrap.php';
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('The setup check can only be opened on the computer that runs the server.');
}

$checks = [];
$add = function (string $label, bool $ok, string $detail) use (&$checks) { $checks[] = [$label, $ok, $detail]; };

$add('PHP 8.1 or newer', PHP_VERSION_ID >= 80100, 'You have PHP ' . PHP_VERSION . (PHP_VERSION_ID >= 80100 ? '' : '. Install a newer XAMPP.'));
foreach (['pdo_mysql' => 'PDO MySQL (database)', 'mbstring' => 'mbstring (text handling)'] as $ext => $label) {
    $add($label, extension_loaded($ext), extension_loaded($ext) ? 'enabled' : "Open php.ini (XAMPP Control Panel > Apache > Config > PHP), remove the ; before extension=$ext, then restart Apache.");
}
$dbOk = false;
try {
    $pdo = db();
    $dbOk = true;
    $add('Database connection', true, 'Connected to "' . env('DB_NAME', 'loyalbox') . '" on ' . env('DB_HOST', '127.0.0.1'));
    $need = ['users', 'admins', 'products', 'product_batches', 'product_codes', 'claims', 'rewards', 'redemptions', 'point_transactions', 'security_events', 'activity_logs'];
    $missing = array_diff($need, $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
    $add('Database tables imported', !$missing, $missing ? 'Missing: ' . implode(', ', $missing) . '. Import database.sql in phpMyAdmin (Import tab, with no database selected).' : 'all ' . count($need) . ' tables found');
    if (!$missing) {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        $add('Admin account', $n > 0, $n > 0 ? 'exists' : 'Not created yet. Open admin/ in your browser and create it.');
    }
} catch (Throwable $e) {
    $add('Database connection', false, 'Cannot connect. Is MySQL started in the XAMPP Control Panel? Did you import database.sql? If you set a MySQL password, put it in config/config.local.php (see config/config.example.php).');
}
$envKey = env('CODE_PEPPER');
$keyFile = ROOT . '/config/secret.key';
if ($envKey && !str_starts_with($envKey, 'change_me')) {
    $add('Secret key for codes', strlen($envKey) >= 32, strlen($envKey) >= 32 ? 'taken from your settings' : 'CODE_PEPPER is too short (min 32 characters).');
} elseif (is_file($keyFile)) {
    $add('Secret key for codes', strlen(trim((string)file_get_contents($keyFile))) >= 32, 'config/secret.key exists. Back this file up: without it existing codes stop working.');
} else {
    $add('Secret key for codes can be created', is_writable(ROOT . '/config'), is_writable(ROOT . '/config') ? 'It will be created automatically the first time you generate codes.' : 'The config folder is not writable. On macOS/Linux run: chmod -R 777 config');
}

// Are private folders blocked from the web? (needs "AllowOverride All", the XAMPP default)
if (PHP_SAPI === 'cli-server') {
    $add('Private folders are blocked from the web', true, 'PHP built-in server: only the public folder is served, so src/ and config/ are not reachable.');
} else {
    $root = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/public/check.php'))), '/');
    $url = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $root . '/src/config.php';
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true, 'follow_location' => 0]]);
    @file_get_contents($url, false, $ctx);
    $code = 0;
    foreach ($http_response_header ?? [] as $line) { if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) { $code = (int)$m[1]; } }
    if ($code === 0) {
        $add('Private folders are blocked from the web', true, 'Could not test from here. Open ' . $url . ' in your browser: it must say Forbidden.');
    } else {
        $add('Private folders (src, config) are blocked from the web', in_array($code, [403, 404], true),
            in_array($code, [403, 404], true) ? 'ok (the .htaccess files are working)' : 'NOT blocked (HTTP ' . $code . '). In httpd.conf set AllowOverride All for htdocs (XAMPP default) and restart Apache.');
    }
}

$allOk = !in_array(false, array_column($checks, 1), true);
page_start(['title' => 'Setup check', 'bare' => true]); ?>
<section class="card check-card">
  <h1>Setup check</h1>
  <p class="subtitle"><?= $allOk ? 'Everything is ready.' : 'Fix the items marked below, then reload this page.' ?></p>
  <ul class="checklist">
<?php foreach ($checks as [$label, $ok, $detail]) : ?>
    <li><span><strong><?= e($label) ?></strong><br><small class="hint"><?= e($detail) ?></small></span>
      <span class="badge <?= $ok ? 'badge-ok' : 'badge-danger' ?>"><?= $ok ? 'OK' : 'Fix needed' ?></span></li>
<?php endforeach; ?>
  </ul>
  <div class="toolbar"><a class="btn" href="customer/">Customer area</a><a class="btn" href="admin/">Admin area</a></div>
</section>
<?php page_end();
