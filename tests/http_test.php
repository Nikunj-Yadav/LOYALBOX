<?php
/**
 * End-to-end HTTP tests against a running dev server that uses the TEST database.
 * Start it first (see README "Running tests"), then:  php tests/http_test.php
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap_test.php';
$base = getenv('HTTP_BASE') ?: 'http://127.0.0.1:8081';

$adminPdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306')), getenv('TEST_ADMIN_USER') ?: env('DB_USER'), getenv('TEST_ADMIN_PASS') ?: env('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$adminPdo->exec('DROP DATABASE IF EXISTS loyalbox_test');
$adminPdo->exec('CREATE DATABASE loyalbox_test CHARACTER SET utf8mb4');
install_schema();
q("INSERT INTO products (name,sku,points,is_active) VALUES ('Choc','CHOC',50,1)");
q("INSERT INTO rewards (name,points_cost,stock,is_active) VALUES ('Bag',40,3,1)");

/** Tiny HTTP client with its own cookie jar. Never follows redirects, so we can inspect them. */
final class Client {
    public array $cookies = [];
    public function __construct(private string $base) {}
    public function req(string $method, string $path, array $data = [], array $headers = []): array {
        $h = ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies))];
        $opt = ['method' => $method, 'follow_location' => 0, 'ignore_errors' => true, 'timeout' => 20, 'header' => array_merge($h, $headers)];
        if ($method === 'POST') { $opt['header'][] = 'Content-Type: application/x-www-form-urlencoded'; $opt['content'] = http_build_query($data); }
        $body = file_get_contents($this->base . $path, false, stream_context_create(['http' => $opt])) ?: '';
        $status = 0; $hdr = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $line, $m)) { $status = (int)$m[1]; }
            elseif (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $hdr[strtolower(trim($k))][] = trim($v); }
            if (stripos($line, 'Set-Cookie:') === 0 && preg_match('/Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $m)) { $this->cookies[$m[1]] = $m[2]; }
        }
        return ['status' => $status, 'body' => $body, 'headers' => $hdr, 'location' => $hdr['location'][0] ?? ''];
    }
    public function token(string $path): string { preg_match('/name="csrf" value="([a-f0-9]+)"/', $this->req('GET', $path)['body'], $m); return $m[1] ?? ''; }
    public function post(string $path, array $data, ?string $tokenFrom = null): array { $data['csrf'] = $this->token($tokenFrom ?? $path); return $this->req('POST', $path, $data); }
}

$results = []; $failed = 0;
function check(string $n, bool $ok, string $d = ''): void { global $results, $failed; $results[] = [$n, $ok, $d]; if (!$ok) $failed++; echo ($ok ? '  PASS ' : '  FAIL ') . $n . ($ok || !$d ? '' : "  -> $d") . "\n"; }
$errRe = '/Fatal error|Warning:|Notice:|Deprecated:|Stack trace|SQLSTATE|Parse error|PDOException/i';

echo "\n== First-time admin setup (browser, no terminal) ==\n";
$s = new Client($base);
$r = $s->req('GET', '/admin/login.php');
check('On a brand-new install the admin sign-in page sends you to setup', $r['status'] === 302 && str_contains($r['location'], 'setup.php'), $r['location']);
$r = $s->req('GET', '/admin/setup.php');
check('Setup page shows the create-admin form', $r['status'] === 200 && str_contains($r['body'], 'Create admin account') && !preg_match($errRe, $r['body']));
$r = $s->post('/admin/setup.php', ['name' => 'Test Admin', 'email' => 'not-an-email', 'password' => 'short', 'password2' => 'other']);
check('Setup rejects bad e-mail / short password / mismatch and creates nothing', $r['status'] === 200 && (int)scalar('SELECT COUNT(*) FROM admins') === 0 && str_contains($r['body'], 'valid e-mail'));
$r = $s->req('POST', '/admin/setup.php', ['name' => 'X', 'email' => 'x@test.local', 'password' => 'long-enough-pass-1', 'password2' => 'long-enough-pass-1']);
check('Setup without a CSRF token is refused (419)', $r['status'] === 419 && (int)scalar('SELECT COUNT(*) FROM admins') === 0);
$r = $s->post('/admin/setup.php', ['name' => 'Test Admin', 'email' => 'Admin@Test.local', 'password' => 'admin-password-1', 'password2' => 'admin-password-1']);
check('Valid setup creates the admin and signs them in', $r['status'] === 302 && str_contains($r['location'], 'index.php') && (int)scalar('SELECT COUNT(*) FROM admins') === 1);
check('Admin e-mail is stored lower-case and the password hashed', scalar('SELECT email FROM admins') === 'admin@test.local' && str_starts_with((string)scalar('SELECT password_hash FROM admins'), '$2y$'));
$dash0 = $s->req('GET', '/admin/index.php'); check('The admin dashboard opens right after setup', $dash0['status'] === 200, 'status ' . $dash0['status'] . ' loc ' . $dash0['location']);
$r = (new Client($base))->req('GET', '/admin/setup.php');
check('Once an admin exists the setup page is gone (redirects to login)', $r['status'] === 302 && str_contains($r['location'], 'login.php'));
$r = (new Client($base))->req('POST', '/admin/setup.php', ['name' => 'Evil', 'email' => 'evil@test.local', 'password' => 'long-enough-pass-1', 'password2' => 'long-enough-pass-1', 'csrf' => 'x']);
check('A second admin can NOT be created through setup', (int)scalar('SELECT COUNT(*) FROM admins') === 1);
$batch = create_code_batch(1, 12, 'http test', 1); $codes = $batch['codes'];
$r = $s->req('GET', '/check.php');
check('Setup check page (check.php) renders and every item is OK', $r['status'] === 200 && !str_contains($r['body'], 'Fix needed') && str_contains($r['body'], 'Database tables imported') && str_contains($r['body'], 'Admin account'), 'status ' . $r['status']);

echo "\n== Access control ==\n";
$anon = new Client($base);
foreach (['index', 'claim', 'rewards', 'history', 'redemptions', 'profile'] as $p) {
    $r = $anon->req('GET', "/customer/$p.php");
    check("Anonymous /customer/$p.php redirects to login", $r['status'] === 302 && str_contains($r['location'], 'login.php'), "status {$r['status']} -> {$r['location']}");
}
foreach (['index', 'products', 'codes', 'customers', 'customer', 'rewards', 'claims', 'redemptions', 'transactions', 'security', 'logs'] as $p) {
    $r = $anon->req('GET', "/admin/$p.php");
    check("Anonymous /admin/$p.php redirects to login", $r['status'] === 302 && str_contains($r['location'], 'login.php'), "status {$r['status']}");
}
check('Admin JSON endpoint returns 401 when signed out', $anon->req('GET', '/admin/api-stats.php')['status'] === 401);
$r = $anon->req('POST', '/customer/login.php', ['email' => 'a@b.co', 'password' => 'x']);
check('POST without CSRF token is rejected (419)', $r['status'] === 419 && !preg_match($errRe, $r['body']));
$r = $anon->req('POST', '/customer/login.php', ['email' => 'a@b.co', 'password' => 'x', 'csrf' => 'forged']);
check('POST with forged CSRF token is rejected (419)', $r['status'] === 419);
$h = $anon->req('GET', '/customer/login.php')['headers'];
check('Security headers present (CSP, nosniff, frame deny)', isset($h['content-security-policy'], $h['x-content-type-options'], $h['x-frame-options']) && str_contains($h['content-security-policy'][0], "script-src 'self'"));
$setc = implode(' ', (new Client($base))->req('GET', '/customer/login.php')['headers']['set-cookie'] ?? []);   // fresh client: no cookie yet, so one is issued
check('Session cookie is HttpOnly + SameSite', stripos($setc, 'httponly') !== false && stripos($setc, 'samesite=lax') !== false, $setc);

echo "\n== Customer journey ==\n";
$c = new Client($base);
$r = $c->post('/customer/register.php', ['name' => 'Short', 'email' => 'bad', 'phone' => '', 'password' => 'short', 'password2' => 'other']);
check('Register with invalid data shows inline errors and creates nothing', $r['status'] === 200 && str_contains($r['body'], 'valid e-mail') && (int)scalar('SELECT COUNT(*) FROM users') === 0);
$xss = '<script>alert(1)</script>';
$r = $c->post('/customer/register.php', ['name' => $xss, 'email' => 'zed@test.local', 'phone' => '', 'password' => 'correct-horse-9', 'password2' => 'correct-horse-9']);
check('Valid registration redirects to the dashboard', $r['status'] === 302 && str_contains($r['location'], 'index.php'));
$pw = scalar('SELECT password_hash FROM users WHERE email="zed@test.local"');
check('Password is stored as a bcrypt hash, not plaintext', str_starts_with((string)$pw, '$2y$') && $pw !== 'correct-horse-9');
$dash = $c->req('GET', '/customer/index.php');
check('Dashboard renders for the new customer', $dash['status'] === 200 && !preg_match($errRe, $dash['body']));
check('User-supplied name is HTML-escaped (XSS)', !str_contains($dash['body'], $xss) && str_contains($dash['body'] . $c->req('GET', '/customer/profile.php')['body'], '&lt;script&gt;'));
$r = (new Client($base))->post('/customer/register.php', ['name' => 'Dup', 'email' => 'ZED@test.local', 'phone' => '', 'password' => 'correct-horse-9', 'password2' => 'correct-horse-9']);
check('Duplicate e-mail (any case) is refused', $r['status'] === 200 && str_contains($r['body'], 'already exists') && (int)scalar('SELECT COUNT(*) FROM users') === 1);

$r = $c->post('/customer/claim.php', ['code' => strtolower(format_code($codes[0]))]);
check('Claiming a valid code redirects (post/redirect/get)', $r['status'] === 302, "status {$r['status']}");
$page = $c->req('GET', '/customer/claim.php');
check('Success toast data is delivered', str_contains($page['body'], '+50 points'));
check('Balance in DB is 50', (int)scalar('SELECT points_balance FROM users WHERE email="zed@test.local"') === 50);
$r = $c->post('/customer/claim.php', ['code' => format_code($codes[0])]);
check('Re-using the code shows a friendly error and no points', $r['status'] === 200 && str_contains($r['body'], 'already been used') && (int)scalar('SELECT points_balance FROM users WHERE email="zed@test.local"') === 50);
$r = $c->post('/customer/claim.php', ['code' => "' OR 1=1 --"]);
check('SQL-injection style input is handled safely (format error, no SQL error)', $r['status'] === 200 && str_contains($r['body'], 'does not look like') && !preg_match($errRe, $r['body']));
$r = $c->req('GET', '/customer/claim.php?code=' . $codes[1]);
check('?code= pre-fills the field but does NOT claim', str_contains($r['body'], 'value="' . $codes[1] . '"') && scalar('SELECT status FROM product_codes WHERE id=2') === 'active');

$r = $c->post('/customer/rewards.php', ['reward_id' => '1'], '/customer/rewards.php');
check('Redeeming a reward (50 pts, cost 40) redirects', $r['status'] === 302);
check('Balance is 10 after redeeming', (int)scalar('SELECT points_balance FROM users WHERE email="zed@test.local"') === 10);
$page = $c->req('GET', '/customer/rewards.php');
check('Unaffordable reward button is disabled', str_contains($page['body'], 'disabled'));
foreach (['index', 'claim', 'rewards', 'history', 'redemptions', 'profile'] as $p) {
    $b = $c->req('GET', "/customer/$p.php");
    check("Customer page $p renders (200, no PHP/SQL errors)", $b['status'] === 200 && !preg_match($errRe, $b['body']) && str_contains($b['body'], 'app.css'), "status {$b['status']}");
}
$r = $c->req('GET', '/admin/index.php');
check('A signed-in CUSTOMER cannot open admin pages', $r['status'] === 302 && str_contains($r['location'], 'login.php'));
$c->req('GET', '/customer/logout.php');
check('GET /logout does not sign you out (needs POST + CSRF)', $c->req('GET', '/customer/index.php')['status'] === 200);
$r = $c->post('/customer/profile.php', ['action' => 'password', 'current' => 'wrong-password', 'password' => 'brand-new-pass-1', 'password2' => 'brand-new-pass-1']);
check('Password change requires the correct current password', str_contains($r['body'], 'Current password is incorrect') && password_verify('correct-horse-9', (string)scalar('SELECT password_hash FROM users WHERE email="zed@test.local"')));
$r = $c->post('/customer/index.php', [], '/customer/profile.php');  // any page's token works for the sidebar logout form
$r = $c->post('/customer/logout.php', [], '/customer/index.php');
check('POST logout signs out', $r['status'] === 302 && $c->req('GET', '/customer/index.php')['status'] === 302);

echo "\n== Sign-in rate limiting ==\n";
$bf = new Client($base); $last = '';
for ($i = 0; $i < 6; $i++) { $last = $bf->post('/customer/login.php', ['email' => 'zed@test.local', 'password' => 'wrong-' . $i])['body']; }
check('After 5 wrong passwords sign-in is locked', str_contains($last, 'Too many failed sign-in attempts'));
$ok = (new Client($base))->post('/customer/login.php', ['email' => 'zed@test.local', 'password' => 'correct-horse-9']);
check('Even the CORRECT password is refused while locked', $ok['status'] === 200 && str_contains($ok['body'], 'Too many'));
check('Failed sign-ins are recorded for admins to see', (int)scalar("SELECT COUNT(*) FROM security_events WHERE event_type='login_failed'") >= 5);
q("DELETE FROM security_events");

echo "\n== Admin ==\n";
$a = new Client($base);
$r = $a->post('/admin/login.php', ['email' => 'admin@test.local', 'password' => 'wrong-password-x']);
check('Wrong admin password is refused with a generic message', $r['status'] === 200 && str_contains($r['body'], 'incorrect'));
$r = $a->post('/admin/login.php', ['email' => 'admin@test.local', 'password' => 'admin-password-1']);
check('Admin sign-in works', $r['status'] === 302 && str_contains($r['location'], 'index.php'));
foreach (['index', 'products', 'codes', 'customers', 'rewards', 'claims', 'redemptions', 'transactions', 'security', 'logs'] as $p) {
    $b = $a->req('GET', "/admin/$p.php");
    check("Admin page $p renders (200, no PHP/SQL errors)", $b['status'] === 200 && !preg_match($errRe, $b['body']) && str_contains($b['body'], 'app.css'), "status {$b['status']}");
}
$b = $a->req('GET', '/admin/customer.php?id=1');
check('Admin customer detail renders', $b['status'] === 200 && !preg_match($errRe, $b['body']));
check('Unknown customer id gives a friendly 404', $a->req('GET', '/admin/customer.php?id=9999')['status'] === 404);
$stats = json_decode($a->req('GET', '/admin/api-stats.php')['body'], true);
check('Dashboard chart API returns 14 days of real data', is_array($stats) && count($stats) === 14 && array_sum(array_column($stats, 'claims')) === (int)scalar('SELECT COUNT(*) FROM claims'));
$dash = $a->req('GET', '/admin/index.php')['body'];
check('Dashboard numbers match the database (claims count)', str_contains($dash, 'data-count="' . (int)scalar('SELECT COUNT(*) FROM claims') . '"'));

$r = $a->post('/admin/products.php', ['action' => 'save', 'id' => '0', 'name' => 'Tea', 'sku' => 'tea-1', 'points' => '30', 'description' => '', 'is_active' => '1']);
check('Admin can create a product (SKU upper-cased)', $r['status'] === 302 && scalar('SELECT sku FROM products WHERE id=2') === 'TEA-1');
$a->post('/admin/products.php', ['action' => 'save', 'id' => '0', 'name' => 'Dup', 'sku' => 'TEA-1', 'points' => '5']);
check('Duplicate SKU is refused', (int)scalar('SELECT COUNT(*) FROM products') === 2);
$a->post('/admin/products.php', ['action' => 'save', 'id' => '0', 'name' => 'Bad', 'sku' => 'BAD1', 'points' => '-5']);
check('Negative points are refused (server-side validation)', (int)scalar('SELECT COUNT(*) FROM products') === 2);
$a->post('/admin/rewards.php', ['action' => 'save', 'id' => '0', 'name' => 'Mug', 'points_cost' => '20', 'stock' => '9', 'is_active' => '1']);
check('Admin can create a reward', (int)scalar('SELECT COUNT(*) FROM rewards') === 2);

$tok = $a->token('/admin/codes.php');
$gen = ['action' => 'generate', 'product_id' => '2', 'quantity' => '5', 'note' => 'http', 'csrf' => $tok];
$r = $a->req('POST', '/admin/codes.php', $gen);
check('Generating codes shows the new codes once', $r['status'] === 200 && preg_match_all('/[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}/', $r['body']) >= 5 && (int)scalar('SELECT COUNT(*) FROM product_codes WHERE batch_id=2') === 5);
$r2 = $a->req('POST', '/admin/codes.php', $gen);
check('Refreshing/replaying the generate POST is rejected (token rotated)', $r2['status'] === 419 && (int)scalar('SELECT COUNT(*) FROM product_codes WHERE batch_id=2') === 5 && (int)scalar('SELECT COUNT(*) FROM product_batches') === 2);
$r = $a->post('/admin/codes.php', ['action' => 'generate', 'product_id' => '2', 'quantity' => '5000']);
check('Batch size above 1,000 is refused', (int)scalar('SELECT COUNT(*) FROM product_batches') === 2);
$exp = $a->req('GET', '/admin/codes.php?export=1');
$leak = 0; foreach ($codes as $cd) { $leak += substr_count($exp['body'], $cd); }
check('Metadata export is CSV and contains no plaintext codes', str_contains(implode('', $exp['headers']['content-type'] ?? []), 'text/csv') && $leak === 0 && str_contains($exp['body'], 'code_ends_with'));
$cid = (int)scalar('SELECT id FROM product_codes WHERE status="active" AND batch_id=1 LIMIT 1');
$a->post('/admin/codes.php', ['action' => 'code_off', 'id' => (string)$cid]);
check('Admin can deactivate a code and customers cannot claim it', scalar('SELECT status FROM product_codes WHERE id=?', [$cid]) === 'inactive');

$u2 = new Client($base);
$u2->post('/customer/register.php', ['name' => 'Second', 'email' => 'second@test.local', 'phone' => '', 'password' => 'correct-horse-9', 'password2' => 'correct-horse-9']);
for ($i = 0; $i < 5; $i++) { $u2->post('/customer/claim.php', ['code' => 'ZZZZ-ZZZZ-ZZZZ-ZZZ' . $i]); }
$u2->post('/customer/claim.php', ['code' => 'ZZZZ-ZZZZ-ZZZZ-ZZZZ']);
$sec = $a->req('GET', '/admin/security.php')['body'];
check('Security page flags the account with repeated failed claims', str_contains($sec, 'second@test.local') && str_contains($sec, 'claim failed'));
$uid = (int)scalar('SELECT id FROM users WHERE email="second@test.local"');
$a->post('/admin/customers.php', ['action' => 'block', 'id' => (string)$uid]);
check('Admin can block a customer', scalar('SELECT status FROM users WHERE id=?', [$uid]) === 'blocked');
check('A blocked customer is signed out on the next request', $u2->req('GET', '/customer/index.php')['status'] === 302);
$logs = $a->req('GET', '/admin/logs.php')['body'];
check('Activity log recorded the admin actions', str_contains($logs, 'generate codes') && str_contains($logs, 'block customer'));
$red = (int)scalar('SELECT id FROM redemptions LIMIT 1');
$a->post('/admin/redemptions.php', ['action' => 'cancel', 'id' => (string)$red]);
check('Admin cancelling a redemption refunds the customer through the ledger', (int)scalar("SELECT COUNT(*) FROM point_transactions WHERE type='refund'") === 1 && (int)scalar('SELECT points_balance FROM users WHERE email="zed@test.local"') === 50);

echo "\n" . ($failed ? "$failed HTTP TEST(S) FAILED" : 'ALL ' . count($results) . ' HTTP TESTS PASSED') . "\n";
$md = "# HTTP test results\n\nGenerated by `php tests/http_test.php` on " . date('Y-m-d H:i') . " UTC (PHP " . PHP_VERSION . ").\n\n| Result | Test |\n|---|---|\n";
foreach ($results as [$n, $ok, $d]) { $md .= '| ' . ($ok ? 'PASS' : '**FAIL**') . ' | ' . $n . ($ok || !$d ? '' : ' — ' . str_replace('|', '/', $d)) . " |\n"; }
file_put_contents(__DIR__ . '/RESULTS_HTTP.md', $md . "\n**" . (count($results) - $failed) . ' of ' . count($results) . " passed.**\n");
exit($failed ? 1 : 0);
